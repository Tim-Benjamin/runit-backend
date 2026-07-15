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

if ($auth['role'] === 'user') {
    $stmt = $db->prepare("
        SELECT o.*,
               r.name AS runner_name,
               r.phone AS runner_phone
        FROM orders o
        LEFT JOIN runners r ON o.runner_id = r.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$auth['id']]);
}

if ($auth['role'] === 'runner') {
    $stmt = $db->prepare("
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
        WHERE o.runner_id = ? OR (o.status = 'pending' AND o.runner_id IS NULL)
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$auth['id']]);
}

if ($auth['role'] === 'admin') {
    $stmt = $db->prepare("
        SELECT o.*,
               u.name AS user_name,
               r.name AS runner_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN runners r ON o.runner_id = r.id
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([]);
}

$orders = $stmt->fetchAll();
respond(['orders' => $orders]);