<?php
// api/push/subscribe.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth = requireAuth(['user', 'runner', 'admin']);
$body = json_decode(file_get_contents('php://input'), true);

$endpoint = $body['endpoint']                    ?? '';
$p256dh   = $body['keys']['p256dh']              ?? '';
$auth_key = $body['keys']['auth']                ?? '';

if (!$endpoint || !$p256dh || !$auth_key) {
    respondError('Invalid subscription data');
}

$db = getDB();

$db->prepare("
    INSERT INTO push_subscriptions (user_id, user_role, endpoint, p256dh, auth)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE p256dh = ?, auth = ?, user_id = ?, user_role = ?
")->execute([
    $auth['id'], $auth['role'], $endpoint, $p256dh, $auth_key,
    $p256dh, $auth_key, $auth['id'], $auth['role'],
]);

respond(['message' => 'Subscribed to push notifications']);