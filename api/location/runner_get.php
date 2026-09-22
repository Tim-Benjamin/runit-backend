<?php
// api/location/runner_get.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$auth     = requireAuth(['user','admin']);
$order_id = intval($_GET['order_id'] ?? 0);
if (!$order_id) respondError('order_id required');

$db = getDB();

// Verify user owns this order
if ($auth['role'] === 'user') {
    $stmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$order_id, $auth['id']]);
    if (!$stmt->fetch()) respondError('Order not found', 403);
}

$stmt = $db->prepare("
    SELECT rl.lat, rl.lng, rl.updated_at,
           r.name AS runner_name, r.delivery_method
    FROM runner_location rl
    JOIN runners r ON rl.runner_id = r.id
    WHERE rl.order_id = ?
    ORDER BY rl.updated_at DESC
    LIMIT 1
");
$stmt->execute([$order_id]);
$loc = $stmt->fetch();

if (!$loc) {
    respond(['location' => null]);
}

respond(['location' => $loc]);