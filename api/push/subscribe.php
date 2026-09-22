<?php
// api/push/subscribe.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth = requireAuth(['user', 'runner', 'admin']);
$body = json_decode(file_get_contents('php://input'), true);

// Handle both formats — wrapped {subscription:{...}} and direct {...}
if (isset($body['subscription'])) {
    $sub = $body['subscription'];
} else {
    $sub = $body;
}

$endpoint = $sub['endpoint']       ?? '';
$p256dh   = $sub['keys']['p256dh'] ?? '';
$auth_key = $sub['keys']['auth']   ?? '';

if (!$endpoint || !$p256dh || !$auth_key) {
    respondError('Invalid subscription data — missing endpoint or keys');
}

$db = getDB();

// Delete old subscriptions for this user
$db->prepare("DELETE FROM push_subscriptions WHERE user_id = ?")
   ->execute([$auth['id']]);

// Insert fresh — use both old and new column names for compatibility
$db->prepare("
    INSERT INTO push_subscriptions
        (user_id, user_role, role, endpoint, p256dh, auth, auth_key)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([
    $auth['id'],
    $auth['role'],
    $auth['role'],
    $endpoint,
    $p256dh,
    $auth_key,
    $auth_key,
]);

respond(['message' => 'Subscribed successfully', 'user_id' => $auth['id']]);