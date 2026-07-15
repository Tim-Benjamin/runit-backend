<?php
// api/sse/runner_feed.php
// Streams new pending orders to runners in real time

require_once '../../config/db.php';
require_once '../../config/auth.php';

// Verify runner token from query string
$token   = $_GET['token'] ?? '';
$payload = verifyToken($token);

if (!$payload || $payload['role'] !== 'runner') {
    http_response_code(401);
    exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

$db           = getDB();
$last_sent_id = 0;

// Stream loop — runs until client disconnects
while (true) {
    if (connection_aborted()) break;

    // Fetch new pending orders since last check
    $stmt = $db->prepare("
        SELECT o.*, u.name AS user_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.status = 'pending'
          AND o.runner_id IS NULL
          AND o.id > ?
        ORDER BY o.id ASC
    ");
    $stmt->execute([$last_sent_id]);
    $orders = $stmt->fetchAll();

    foreach ($orders as $order) {
        echo "event: new_order\n";
        echo "data: " . json_encode([
            'type'  => 'new_order',
            'order' => $order,
        ]) . "\n\n";

        $last_sent_id = max($last_sent_id, (int)$order['id']);
    }

    // Send heartbeat every loop to keep connection alive
    echo ": heartbeat\n\n";
    flush();

    sleep(5); // poll every 5 seconds
}