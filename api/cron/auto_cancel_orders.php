<?php
// api/cron/auto_cancel_orders.php
// Run this via Hostinger cron job every 5 minutes:
// */5 * * * * php /home/username/public_html/runit-backend/api/cron/auto_cancel_orders.php

require_once '../../config/db.php';
require_once '../../config/push.php';

$db = getDB();

// Auto-cancel pending orders older than 30 minutes
$stmt = $db->prepare("
    SELECT o.*, u.name AS user_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.status = 'pending'
    AND o.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
");
$stmt->execute();
$orders = $stmt->fetchAll();

$cancelled = 0;

foreach ($orders as $order) {
    // Cancel the order
    $db->prepare("
        UPDATE orders SET status = 'cancelled', cancel_reason = 'No runner accepted within 30 minutes'
        WHERE id = ?
    ")->execute([$order['id']]);

    // Log status change
    $db->prepare("
        INSERT INTO order_status_log (order_id, status, note)
        VALUES (?, 'cancelled', 'Auto-cancelled: no runner accepted within 30 minutes')
    ")->execute([$order['id']]);

    // Notify user
    try {
        sendPushToUser($db, $order['user_id'], 'user',
            '⏰ Order auto-cancelled',
            'No runner accepted order #' . $order['id'] . ' within 30 minutes. Please try again.',
            ['url' => '/orders/' . $order['id']]
        );
    } catch (Exception $e) {
        error_log('Push error: ' . $e->getMessage());
    }

    $cancelled++;
    error_log('[AutoCancel] Cancelled order #' . $order['id'] . ' for user ' . $order['user_name']);
}

echo "Auto-cancelled " . $cancelled . " orders.\n";