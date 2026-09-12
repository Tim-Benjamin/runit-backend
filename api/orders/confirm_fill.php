<?php
// api/orders/confirm_fill.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$user = requireAuth(['user']);
$body = json_decode(file_get_contents('php://input'), true);

$order_id         = intval($body['order_id']         ?? 0);
$action           = $body['action']                  ?? '';  // 'confirm' or 'dispute'
$confirmed_amount = floatval($body['confirmed_amount'] ?? 0);
$dispute_note     = trim($body['dispute_note']       ?? '');

if (!$order_id)                              respondError('Order ID required');
if (!in_array($action, ['confirm','dispute'])) respondError('Invalid action');
if ($action === 'dispute' && $confirmed_amount <= 0) respondError('Confirmed amount required for dispute');
if ($action === 'dispute' && !$dispute_note) respondError('Dispute note required');

$db = getDB();

// Verify order belongs to user and is a gas refill with a receipt
$stmt = $db->prepare("
    SELECT * FROM orders
    WHERE id = ? AND user_id = ? AND category = 'Gas Refill'
    LIMIT 1
");
$stmt->execute([$order_id, $user['id']]);
$order = $stmt->fetch();

if (!$order)              respondError('Order not found', 404);
if (!$order['fill_receipt']) respondError('No fill receipt on this order');
if ($order['fill_confirmed'] || $order['fill_disputed']) respondError('Already responded to this fill');

if ($action === 'confirm') {
    $db->prepare("
        UPDATE orders SET fill_confirmed = 1 WHERE id = ?
    ")->execute([$order_id]);

    // Notify runner
    try {
        require_once '../../config/push.php';
        sendPushToUser($db, $order['runner_id'], 'runner',
            '✅ Fill confirmed',
            'The user confirmed your gas fill. Great work!',
            ['url' => '/runner/earnings']
        );
    } catch (Exception $e) {}

    respond(['message' => 'Fill confirmed. Thank you!']);
}

if ($action === 'dispute') {
    // Mark order as disputed
    $db->prepare("
        UPDATE orders SET fill_disputed = 1 WHERE id = ?
    ")->execute([$order_id]);

    // Insert into gas_fill_reports
    $db->prepare("
        INSERT INTO gas_fill_reports
            (order_id, confirmed_amount, dispute_note, resolved)
        VALUES (?, ?, ?, 0)
    ")->execute([$order_id, $confirmed_amount, $dispute_note]);

    // Notify runner
    try {
        require_once '../../config/push.php';
        sendPushToUser($db, $order['runner_id'], 'runner',
            '⚠️ Gas fill disputed',
            'A user has disputed your gas fill declaration. Admin is reviewing.',
            ['url' => '/runner/profile']
        );
        // Notify admin
        sendPushToRole($db, 'admin',
            '⛽ Gas fill dispute',
            'Order #' . $order_id . ' — user disputed the fill amount. Review in Feedback.',
            ['url' => '/admin/feedback']
        );
    } catch (Exception $e) {}

    respond(['message' => 'Dispute submitted. Admin will review within 24 hours.']);
}