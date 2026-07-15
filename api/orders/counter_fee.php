<?php
// api/orders/counter_fee.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$runner   = requireAuth(['runner']);
$body     = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);
$fee      = floatval($body['counter_fee'] ?? 0);

if (!$order_id)  respondError('Order ID required');
if ($fee < 1)    respondError('Counter fee must be at least GH₵ 1');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order)                        respondError('Order not found', 404);
if ($order['status'] !== 'pending') respondError('Order no longer available');
if ($order['runner_id'] !== null)   respondError('Order already taken');

// Save counter fee and tentatively assign runner
$db->prepare("
    UPDATE orders SET counter_fee = ?, runner_id = ? WHERE id = ?
")->execute([$fee, $runner['id'], $order_id]);


// Push notification — tell user about counter fee
try {
    require_once '../../config/push.php';
    $ord = $db->prepare("SELECT user_id, proposed_fee FROM orders WHERE id = ? LIMIT 1");
    $ord->execute([$order_id]);
    $o = $ord->fetch();
    if ($o) {
        sendPushToUser($db, $o['user_id'], 'user',
            '💬 Runner proposed a new fee!',
            'GH₵ ' . number_format($fee, 2) . ' instead of GH₵ ' . number_format($o['proposed_fee'], 2) . '. Tap to approve or decline.',
            ['url' => '/orders/' . $order_id, 'order_id' => (int)$order_id]
        );
    }
} catch (Exception $e) {
    error_log('Push error in counter_fee: ' . $e->getMessage());
    respond(['message' => 'Counter fee proposed. Waiting for user approval.']);
}