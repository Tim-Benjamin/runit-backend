<?php
// api/orders/declare_fill.php
// Runner uploads receipt + declares fill amount
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$runner   = requireAuth(['runner']);
$order_id = intval($_POST['order_id'] ?? 0);
$amount   = floatval($_POST['fill_amount'] ?? 0);

if (!$order_id)   respondError('Order ID required');
if ($amount <= 0) respondError('Fill amount must be greater than 0');

// Validate receipt image
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    respondError('Receipt photo is required');
}

$file    = $_FILES['receipt'];
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
$mime    = mime_content_type($file['tmp_name']);

if (!in_array($mime, $allowed)) respondError('Receipt must be a JPG, PNG or WebP image');
if ($file['size'] > 5 * 1024 * 1024) respondError('Receipt image must be under 5MB');

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order)                                          respondError('Order not found', 404);
if ((int)$order['runner_id'] !== (int)$runner['id']) respondError('Not your order', 403);
if ($order['category'] !== 'Gas Refill')              respondError('Not a gas refill order', 400);
if ($order['fill_receipt'])                           respondError('Fill already declared for this order');

// Save receipt image
$ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = 'receipt_' . $order_id . '_' . time() . '.' . $ext;
$dest     = __DIR__ . '/../../uploads/receipts/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    respondError('Failed to save receipt image', 500);
}

// Save declaration
$db->prepare("
    UPDATE orders
    SET fill_amount = ?, fill_receipt = ?, fill_declared_at = NOW()
    WHERE id = ?
")->execute([$amount, $filename, $order_id]);

// Create gas fill report
$db->prepare("
    INSERT INTO gas_fill_reports (order_id, runner_id, user_id, declared_amount, receipt_path)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE declared_amount = ?, receipt_path = ?
")->execute([
    $order_id, $runner['id'], $order['user_id'],
    $amount, $filename, $amount, $filename
]);

// Notify user
require_once '../../config/push.php';
sendPushToUser($db, $order['user_id'], 'user',
    '🔥 Cylinder filled!',
    'Runner filled GH₵ ' . number_format($amount, 2) . '. Please confirm when cylinder is returned.',
    ['url' => '/orders/' . $order_id, 'order_id' => $order_id]
);

respond(['message' => 'Fill declaration saved. Return the cylinder and update the status.']);