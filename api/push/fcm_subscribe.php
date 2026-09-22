<?php
// api/push/fcm_subscribe.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth  = requireAuth(['user','runner','admin']);
$body  = json_decode(file_get_contents('php://input'), true);
$token = trim($body['fcm_token'] ?? '');
if (!$token) respondError('FCM token required');

$db = getDB();

// Store FCM token — one per user
$db->prepare("
    INSERT INTO fcm_tokens (user_id, role, token, updated_at)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE token = VALUES(token), updated_at = NOW()
")->execute([$auth['id'], $auth['role'], $token]);

respond(['message' => 'FCM token saved']);