<?php
// api/feedback/rate.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$user  = requireAuth(['user']);
$body  = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);
$stars    = intval($body['stars']    ?? 0);
$comment  = trim($body['comment']   ?? '');

if (!$order_id)             respondError('Order ID required');
if ($stars < 1 || $stars > 5) respondError('Stars must be between 1 and 5');

$db = getDB();

// Verify order belongs to user and is delivered
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'delivered' LIMIT 1");
$stmt->execute([$order_id, $user['id']]);
$order = $stmt->fetch();

if (!$order) respondError('Order not found or not yet delivered', 404);
if (!$order['runner_id']) respondError('No runner assigned to this order', 400);

// Check if already rated
$check = $db->prepare("SELECT id FROM ratings WHERE order_id = ? LIMIT 1");
$check->execute([$order_id]);
if ($check->fetch()) respondError('You have already rated this delivery');

$db->prepare("
    INSERT INTO ratings (order_id, user_id, runner_id, stars, comment)
    VALUES (?, ?, ?, ?, ?)
")->execute([$order_id, $user['id'], $order['runner_id'], $stars, $comment ?: null]);

respond(['message' => 'Rating submitted. Thank you!'], 201);