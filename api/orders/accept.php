<?php
// api/orders/accept.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$runner = requireAuth(['runner']);
$body   = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);

if (!$order_id) respondError('Order ID is required');

$db = getDB();

// Check order exists and is still pending
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order)                        respondError('Order not found', 404);
if ($order['status'] !== 'pending') respondError('Order is no longer available');
if ($order['runner_id'] !== null)   respondError('Order already taken');

// Assign runner + update status
$db->prepare("
    UPDATE orders SET runner_id = ?, status = 'accepted', final_fee = proposed_fee
    WHERE id = ?
")->execute([$runner['id'], $order_id]);

// Log status change
$db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, 'accepted')")
   ->execute([$order_id]);

// Record earnings
$fee          = floatval($order['proposed_fee']);
$platform_cut = round($fee * 0.20, 2);
$runner_cut   = round($fee * 0.80, 2);

$db->prepare("
    INSERT INTO runner_earnings (runner_id, order_id, delivery_fee, runner_cut, platform_cut)
    VALUES (?, ?, ?, ?, ?)
")->execute([$runner['id'], $order_id, $fee, $runner_cut, $platform_cut]);


// Push notification
try {
    require_once '../../config/push.php';

    // Notify user their runner is assigned
    $u = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $u->execute([$order['user_id']]);
    $ud = $u->fetch();

    $r = $db->prepare("SELECT name, phone FROM runners WHERE id = ? LIMIT 1");
    $r->execute([$runner['id']]);
    $rd = $r->fetch();

    sendPushToUser($db, $order['user_id'], 'user',
        '🏃 Runner assigned!',
        ($rd['name'] ?? 'A runner') . ' accepted your order and is heading your way!',
        ['url' => '/orders/' . $order_id, 'order_id' => (int)$order_id]
    );
} catch (Exception $e) {
    error_log('Push error in accept: ' . $e->getMessage());
}

// Send runner accepted email to user
require_once '../../config/mailer.php';
$user_stmt = $db->prepare("SELECT u.name, u.email FROM users u JOIN orders o ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
$user_stmt->execute([$order_id]);
$user_data = $user_stmt->fetch();

$runner_stmt = $db->prepare("SELECT name, phone FROM runners WHERE id = ? LIMIT 1");
$runner_stmt->execute([$runner['id']]);
$runner_data = $runner_stmt->fetch();

if ($user_data && $runner_data) {
    emailRunnerAccepted($user_data['email'], $user_data['name'], $order, $runner_data);
}
respond(['message' => 'Order accepted']);
