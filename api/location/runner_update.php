<?php
// api/location/runner_update.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$runner = requireAuth(['runner']);
$body   = json_decode(file_get_contents('php://input'), true);

$order_id = intval($body['order_id'] ?? 0);
$lat      = floatval($body['lat']      ?? 0);
$lng      = floatval($body['lng']      ?? 0);

if (!$order_id)        respondError('order_id required');
if (!$lat || !$lng)    respondError('lat and lng required');

$db = getDB();

// Verify this runner owns this order
$stmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND runner_id = ? LIMIT 1");
$stmt->execute([$order_id, $runner['id']]);
if (!$stmt->fetch()) respondError('Order not found or not yours', 403);

// Upsert runner location
$db->prepare("
    INSERT INTO runner_location (runner_id, order_id, lat, lng)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), updated_at = NOW()
")->execute([$runner['id'], $order_id, $lat, $lng]);

respond(['message' => 'Location updated']);