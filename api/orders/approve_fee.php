<?php
// api/orders/approve_fee.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$user     = requireAuth(['user']);
$body     = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);
$action   = $body['action'] ?? ''; // 'approve' or 'decline'

if (!$order_id)                          respondError('Order ID required');
if (!in_array($action, ['approve', 'decline'])) respondError('Invalid action');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order)                               respondError('Order not found', 404);
if ((int)$order['user_id'] !== (int)$user['id']) respondError('Forbidden', 403);
if (!$order['counter_fee'])                respondError('No counter fee to approve');

if ($action === 'approve') {
    // Accept the counter fee — move to accepted
    $db->prepare("
        UPDATE orders
        SET status = 'accepted', final_fee = counter_fee
        WHERE id = ?
    ")->execute([$order_id]);

    $db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, 'accepted')")
        ->execute([$order_id]);

    // Record earnings
    $fee          = floatval($order['counter_fee']);
    $platform_cut = round($fee * 0.20, 2);
    $runner_cut   = round($fee * 0.80, 2);

    $db->prepare("
        INSERT INTO runner_earnings (runner_id, order_id, delivery_fee, runner_cut, platform_cut)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$order['runner_id'], $order_id, $fee, $runner_cut, $platform_cut]);

    respond(['message' => 'Counter fee approved. Runner is on the way!']);
}

// After approve block, notify runner
if ($action === 'approve') {
    // Push notification — tell runner fee was accepted
    try {
        require_once '../../config/push.php';
        sendPushToUser(
            $db,
            $order['runner_id'],
            'runner',
            '✅ Fee accepted!',
            'The user accepted GH₵ ' . number_format($order['counter_fee'], 2) . '. Start the delivery now!',
            ['url' => '/runner/active', 'order_id' => (int)$order_id]
        );
    } catch (Exception $e) {
        error_log('Push error in approve_fee: ' . $e->getMessage());
    }
}

if ($action === 'decline') {
    // Reset order back to pending with no runner
    $db->prepare("
        UPDATE orders SET counter_fee = NULL, runner_id = NULL WHERE id = ?
    ")->execute([$order_id]);

    respond(['message' => 'Counter fee declined. Order is back in the feed.']);
}
