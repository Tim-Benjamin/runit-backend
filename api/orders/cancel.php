<?php
// api/orders/cancel.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$auth     = requireAuth(['user', 'runner']);
$body     = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);

if (!$order_id) respondError('Order ID is required');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) respondError('Order not found', 404);

// Users can only cancel their own pending orders
if ($auth['role'] === 'user') {
    if ((int)$order['user_id'] !== (int)$auth['id']) respondError('Forbidden', 403);
    if ($order['status'] !== 'pending') respondError('You can only cancel pending orders');
}

// Runners can only cancel orders assigned to them
if ($auth['role'] === 'runner') {
    if ((int)$order['runner_id'] !== (int)$auth['id']) respondError('Forbidden', 403);
    if (!in_array($order['status'], ['accepted', 'on_the_way'])) {
        respondError('Cannot cancel at this stage');
    }
}

$db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")
   ->execute([$order_id]);

$db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, 'cancelled')")
   ->execute([$order_id]);

respond(['message' => 'Order cancelled']);