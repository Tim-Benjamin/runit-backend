<?php
// api/push/announce.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';
require_once '../../config/push.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

requireAuth(['admin']);
$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true);

$title    = trim($body['title']    ?? '');
$message  = trim($body['message']  ?? '');
$audience = $body['audience']      ?? 'all'; // all | users | runners
$url      = $body['url']           ?? '/';

if (!$title || !$message) respondError('Title and message are required');

$valid_audiences = ['all', 'users', 'runners'];
if (!in_array($audience, $valid_audiences)) respondError('Invalid audience');

// Get subscribers based on audience
if ($audience === 'all') {
    $stmt = $db->query("SELECT DISTINCT user_id, user_role FROM push_subscriptions");
} elseif ($audience === 'users') {
    $stmt = $db->query("SELECT DISTINCT user_id, user_role FROM push_subscriptions WHERE user_role = 'user'");
} else {
    $stmt = $db->query("SELECT DISTINCT user_id, user_role FROM push_subscriptions WHERE user_role = 'runner'");
}

$targets = $stmt->fetchAll();
$sent    = 0;

foreach ($targets as $target) {
    sendPushToUser($db, $target['user_id'], $target['user_role'],
        $title, $message, ['url' => $url, 'type' => 'announcement']
    );
    $sent++;
}

respond(['message' => 'Announcement sent to ' . $sent . ' subscriber(s)', 'sent' => $sent]);