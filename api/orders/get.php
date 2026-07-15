<?php
// api/orders/get.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$auth     = requireAuth(['user', 'runner', 'admin']);
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) respondError('Order ID is required');

$db   = getDB();
$stmt = $db->prepare("
    SELECT o.*,
           u.name  AS user_name,
           u.phone AS user_phone,
           r.name  AS runner_name,
           r.phone AS runner_phone,
           r.delivery_method
    FROM orders o
    LEFT JOIN users   u ON o.user_id   = u.id
    LEFT JOIN runners r ON o.runner_id = r.id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) respondError('Order not found', 404);

// Only allow access to the right people
if ($auth['role'] === 'user'   && (int)$order['user_id']   !== (int)$auth['id']) respondError('Forbidden', 403);
if ($auth['role'] === 'runner' && (int)$order['runner_id'] !== (int)$auth['id']) respondError('Forbidden', 403);

// Fetch status log
$log = $db->prepare("SELECT status, changed_at FROM order_status_log WHERE order_id = ? ORDER BY changed_at ASC");
$log->execute([$order_id]);

$order['status_log'] = $log->fetchAll();

respond(['order' => $order]);