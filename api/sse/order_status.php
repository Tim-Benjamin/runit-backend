<?php
// api/sse/order_status.php
// Streams order status changes to a specific user

require_once '../../config/db.php';
require_once '../../config/auth.php';

$token    = $_GET['token']    ?? '';
$order_id = intval($_GET['order_id'] ?? 0);
$payload  = verifyToken($token);

if (!$payload) {
    http_response_code(401);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

if (ob_get_level()) ob_end_clean();

$db          = getDB();
$last_status = '';

while (true) {
    if (connection_aborted()) break;

    // Get current order status
    $stmt = $db->prepare("
        SELECT o.status, o.final_fee, o.runner_id,
               r.name AS runner_name, r.phone AS runner_phone
        FROM orders o
        LEFT JOIN runners r ON o.runner_id = r.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if ($order && $order['status'] !== $last_status) {
        echo "event: status_update\n";
        echo "data: " . json_encode([
            'type'   => 'status_update',
            'status' => $order['status'],
            'order'  => $order,
        ]) . "\n\n";

        $last_status = $order['status'];

        // Stop streaming once delivered or cancelled
        if (in_array($order['status'], ['delivered', 'cancelled'])) break;
    }

    echo ": heartbeat\n\n";
    flush();

    sleep(4);
}