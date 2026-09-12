<?php
// api/location/update.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$user     = requireAuth(['user']);
$body     = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);
$lat      = floatval($body['lat'] ?? 0);
$lng      = floatval($body['lng'] ?? 0);

if (!$order_id || !$lat || !$lng) respondError('order_id, lat and lng are required');

$db = getDB();

// Upsert user location
$stmt = $db->prepare("SELECT id FROM user_location WHERE user_id = ? AND order_id = ? LIMIT 1");
$stmt->execute([$user['id'], $order_id]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare("UPDATE user_location SET lat = ?, lng = ?, updated_at = NOW() WHERE user_id = ? AND order_id = ?")
       ->execute([$lat, $lng, $user['id'], $order_id]);
} else {
    $db->prepare("INSERT INTO user_location (user_id, order_id, lat, lng) VALUES (?,?,?,?)")
       ->execute([$user['id'], $order_id, $lat, $lng]);
}

respond(['message' => 'Location updated']);