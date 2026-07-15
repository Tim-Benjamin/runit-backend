<?php
// api/orders/update_status.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$runner = requireAuth(['runner']);
$body   = json_decode(file_get_contents('php://input'), true);
$order_id  = intval($body['order_id'] ?? 0);
$new_status = trim($body['status'] ?? '');

$valid = ['on_the_way', 'arrived', 'delivered'];
if (!$order_id)                      respondError('Order ID is required');
if (!in_array($new_status, $valid))  respondError('Invalid status');

$db = getDB();

$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order)                               respondError('Order not found', 404);
if ((int)$order['runner_id'] !== (int)$runner['id']) respondError('Not your order', 403);

$db->prepare("UPDATE orders SET status = ? WHERE id = ?")
    ->execute([$new_status, $order_id]);

$db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, ?)")
    ->execute([$order_id, $new_status]);

require_once '../../config/push.php';

$ord_stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$ord_stmt->execute([$order_id]);
$ord = $ord_stmt->fetch();

if ($ord) {
    $status_msgs = [
        'on_the_way' => ['🛵 Runner is on the way!',  'Your order is being delivered right now. Get ready!'],
        'arrived'    => ['📍 Runner has arrived!',    'Your runner is at your location. Please come receive your order.'],
        'delivered'  => ['✅ Order delivered!',        'Your order has been delivered. Please pay GH₵ ' . number_format($ord['final_fee'] ?? $ord['proposed_fee'], 2) . ' in cash.'],
    ];

    if (isset($status_msgs[$new_status])) {
        sendPushToUser(
            $db,
            $ord['user_id'],
            'user',
            $status_msgs[$new_status][0],
            $status_msgs[$new_status][1],
            ['url' => '/orders/' . $order_id, 'order_id' => $order_id]
        );
    }
}

// Send delivered email
if ($new_status === 'delivered') {
    require_once '../../config/mailer.php';
    $user_stmt = $db->prepare("SELECT u.name, u.email FROM users u JOIN orders o ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
    $user_stmt->execute([$order_id]);
    $user_data = $user_stmt->fetch();
    if ($user_data) {
        emailOrderDelivered($user_data['email'], $user_data['name'], $order);
    }
}

respond(['message' => 'Status updated']);
