<?php
// api/orders/list.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$auth = requireAuth(['user', 'runner', 'admin']);
$db   = getDB();

// If runner is offline, return empty feed with a message
if ($auth['role'] === 'runner') {
    $stmt = $db->prepare("SELECT is_online, status FROM runners WHERE id = ? LIMIT 1");
    $stmt->execute([$auth['id']]);
    $runnerRow = $stmt->fetch();

    if ($runnerRow && !$runnerRow['is_online'] && ($runnerRow['status'] === 'active')) {
        respond(['orders' => [], 'offline' => true]);
    }
}

// Filters from query string
$status    = $_GET['status']    ?? '';
$category  = $_GET['category']  ?? '';
$search    = trim($_GET['search']    ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 20;
$offset    = ($page - 1) * $per_page;

$where  = [];
$params = [];

// Role-based base scope — preserves each role's original access rules
if ($auth['role'] === 'user') {
    $where[]  = 'o.user_id = ?';
    $params[] = $auth['id'];
} elseif ($auth['role'] === 'runner') {
    // Runner sees their own orders PLUS unclaimed pending orders (the live feed)
    $where[]  = '(o.runner_id = ? OR (o.status = \'pending\' AND o.runner_id IS NULL))';
    $params[] = $auth['id'];
}
// admin: no base restriction — sees everything

// Optional filters
if ($status)    { $where[] = 'o.status = ?';   $params[] = $status; }
if ($category)  { $where[] = 'o.category = ?'; $params[] = $category; }
if ($search)    { $where[] = '(o.description LIKE ? OR o.id LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
if ($date_from) { $where[] = 'DATE(o.created_at) >= ?'; $params[] = $date_from; }
if ($date_to)   { $where[] = 'DATE(o.created_at) <= ?'; $params[] = $date_to; }

$whereSQL = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total (for pagination)
$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Select columns / joins still differ per role, same as before
if ($auth['role'] === 'runner') {
    $select = "
        SELECT o.*,
               u.name  AS user_name,
               u.phone AS user_phone,
               ul.lat  AS user_lat,
               ul.lng  AS user_lng,
               ul.updated_at AS location_updated_at
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN (
            SELECT user_id, order_id, lat, lng, updated_at
            FROM user_location ul2
            WHERE ul2.updated_at = (
                SELECT MAX(updated_at) FROM user_location
                WHERE user_id = ul2.user_id AND order_id = ul2.order_id
            )
        ) ul ON ul.user_id = o.user_id AND ul.order_id = o.id
    ";
} elseif ($auth['role'] === 'user') {
    $select = "
        SELECT o.*,
               r.name  AS runner_name,
               r.phone AS runner_phone
        FROM orders o
        LEFT JOIN runners r ON o.runner_id = r.id
    ";
} else {
    // admin
    $select = "
        SELECT o.*,
               u.name AS user_name,
               r.name AS runner_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN runners r ON o.runner_id = r.id
    ";
}

// Fetch page
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare("
    $select
    $whereSQL
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute($params);

respond([
    'orders'      => $stmt->fetchAll(),
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $per_page,
    'total_pages' => ceil($total / $per_page),
]);