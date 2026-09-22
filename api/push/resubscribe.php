<?php
// api/push/resubscribe.php
// Called by service worker when push subscription changes automatically
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$body        = json_decode(file_get_contents('php://input'), true);
$old_ep      = $body['old_endpoint']  ?? '';
$sub         = $body['subscription']  ?? [];
$new_ep      = $sub['endpoint']       ?? '';
$p256dh      = $sub['keys']['p256dh'] ?? '';
$auth_key    = $sub['keys']['auth']   ?? '';

if (!$old_ep || !$new_ep) respondError('Missing endpoints');

$db = getDB();

// Find the user by old endpoint
$stmt = $db->prepare("SELECT user_id, user_role, role FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
$stmt->execute([$old_ep]);
$old = $stmt->fetch();

if (!$old) respond(['message' => 'Old subscription not found — ignored']);

// Update to new subscription
$db->prepare("
    UPDATE push_subscriptions
    SET endpoint = ?, p256dh = ?, auth = ?, auth_key = ?
    WHERE endpoint = ?
")->execute([$new_ep, $p256dh, $auth_key, $auth_key, $old_ep]);

respond(['message' => 'Subscription updated']);