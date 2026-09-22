<?php
// Run every minute: * * * * *
require_once '../../config/db.php';
require_once '../../config/push.php';

$db = getDB();

// Find scheduled orders whose time has come
$stmt = $db->prepare("
    SELECT o.*, u.name AS user_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.status = 'scheduled'
    AND o.scheduled_for <= NOW()
");
$stmt->execute();
$orders = $stmt->fetchAll();

foreach ($orders as $order) {
    $db->prepare("UPDATE orders SET status = 'pending', is_scheduled = 0 WHERE id = ?")
       ->execute([$order['id']]);

    $db->prepare("
        INSERT INTO order_status_log (order_id, status, note)
        VALUES (?, 'pending', 'Scheduled order released to runner feed')
    ")->execute([$order['id']]);

    try {
        sendPushToUser($db, $order['user_id'], 'user',
            '⏰ Scheduled order is now live',
            'Your scheduled order #' . $order['id'] . ' is now in the runner feed.',
            ['url' => '/orders/' . $order['id']]
        );
        sendPushToRole($db, 'runner',
            '📦 New order available',
            'A scheduled order is now available: ' . $order['category'],
            ['url' => '/runner/feed']
        );
    } catch (Exception $e) {}

    echo "Released order #" . $order['id'] . "\n";
}