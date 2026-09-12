<?php
// api/notifications/list.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$auth = requireAuth(['user', 'runner', 'admin']);
$db   = getDB();

$since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));

// Build notifications from real events based on role
$notifications = [];

if ($auth['role'] === 'user') {
    // Order status changes
    $stmt = $db->prepare("
        SELECT
            l.id,
            l.status,
            l.changed_at,
            o.id AS order_id,
            o.description,
            o.final_fee,
            o.proposed_fee,
            o.counter_fee,
            r.name AS runner_name,
            r.phone AS runner_phone
        FROM order_status_log l
        JOIN orders o ON l.order_id = o.id
        LEFT JOIN runners r ON o.runner_id = r.id
        WHERE o.user_id = ?
          AND l.changed_at > ?
        ORDER BY l.changed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$auth['id'], $since]);
    $logs = $stmt->fetchAll();

    foreach ($logs as $log) {
        $type    = $log['status'];
        $title   = '';
        $body    = '';
        $icon    = '📦';

        switch ($type) {
            case 'accepted':
                $title = 'Runner assigned!';
                $body  = ($log['runner_name'] ?? 'A runner') . ' accepted your order and is on the way.';
                $icon  = '🏃';
                break;
            case 'on_the_way':
                $title = 'Runner is on the way!';
                $body  = 'Your order is being delivered now.';
                $icon  = '🛵';
                break;
            case 'arrived':
                $title = 'Runner has arrived!';
                $body  = 'Your runner is here. Get ready to receive your order.';
                $icon  = '📍';
                break;
            case 'delivered':
                $title = 'Order delivered!';
                $body  = 'Your order has been delivered. Pay GH₵ ' . number_format($log['final_fee'] ?? $log['proposed_fee'], 2) . ' in cash.';
                $icon  = '✅';
                break;
            case 'cancelled':
                $title = 'Order cancelled';
                $body  = 'Your order #' . $log['order_id'] . ' was cancelled.';
                $icon  = '❌';
                break;
        }

        if ($title) {
            $notifications[] = [
                'id'       => 'status_' . $log['id'],
                'type'     => $type,
                'title'    => $title,
                'body'     => $body,
                'icon'     => $icon,
                'order_id' => $log['order_id'],
                'time'     => $log['changed_at'],
            ];
        }
    }

    // Counter fee proposals
    $stmt2 = $db->prepare("
        SELECT o.id AS order_id, o.counter_fee, o.description, o.updated_at
        FROM orders o
        WHERE o.user_id = ?
          AND o.counter_fee IS NOT NULL
          AND o.status = 'pending'
          AND o.updated_at > ?
        ORDER BY o.updated_at DESC
    ");
    $stmt2->execute([$auth['id'], $since]);
    foreach ($stmt2->fetchAll() as $row) {
        $notifications[] = [
            'id'       => 'counter_' . $row['order_id'],
            'type'     => 'counter_fee',
            'title'    => 'Runner proposed a different fee',
            'body'     => 'GH₵ ' . number_format($row['counter_fee'], 2) . ' for your order. Tap to approve or decline.',
            'icon'     => '💬',
            'order_id' => $row['order_id'],
            'time'     => $row['updated_at'],
        ];
    }
}

if ($auth['role'] === 'runner') {
    // New pending orders
    $stmt = $db->prepare("
        SELECT o.id, o.description, o.category, o.proposed_fee, o.created_at
        FROM orders o
        WHERE o.status = 'pending'
          AND o.runner_id IS NULL
          AND o.created_at > ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll() as $order) {
        $notifications[] = [
            'id'       => 'order_' . $order['id'],
            'type'     => 'new_order',
            'title'    => 'New order available!',
            'body'     => $order['description'] . ' · GH₵ ' . number_format($order['proposed_fee'], 2),
            'icon'     => '📦',
            'order_id' => $order['id'],
            'time'     => $order['created_at'],
        ];
    }
}

if ($auth['role'] === 'admin') {
    // Pending runner approvals
    $stmt = $db->prepare("
        SELECT id, name, created_at FROM runners
        WHERE status = 'pending' AND created_at > ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll() as $runner) {
        $notifications[] = [
            'id'    => 'runner_' . $runner['id'],
            'type'  => 'new_runner',
            'title' => 'New runner application',
            'body'  => $runner['name'] . ' applied to become a runner.',
            'icon'  => '🏃',
            'time'  => $runner['created_at'],
        ];
    }

    // New orders in last period
    $stmt2 = $db->prepare("
        SELECT COUNT(*) AS count FROM orders
        WHERE created_at > ? AND status = 'pending'
    ");
    $stmt2->execute([$since]);
    $row = $stmt2->fetch();
    if ($row['count'] > 0) {
        $notifications[] = [
            'id'    => 'admin_orders_' . time(),
            'type'  => 'new_orders',
            'title' => $row['count'] . ' new order' . ($row['count'] > 1 ? 's' : '') . ' placed',
            'body'  => 'Check the orders dashboard.',
            'icon'  => '📊',
            'time'  => date('Y-m-d H:i:s'),
        ];
    }
}

// Sort by time desc
usort($notifications, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));

respond(['notifications' => $notifications, 'count' => count($notifications)]);