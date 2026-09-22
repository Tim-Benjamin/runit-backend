<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$body     = json_decode(file_get_contents('php://input'), true);
$token    = trim($body['token']    ?? '');
$password = $body['password']      ?? '';

if (!$token)              respondError('Token required');
if (strlen($password) < 6) respondError('Password must be at least 6 characters');

$db = getDB();

$stmt = $db->prepare("
    SELECT * FROM password_resets
    WHERE token = ? AND used = 0 AND expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) respondError('Invalid or expired reset link. Please request a new one.');

$hash  = password_hash($password, PASSWORD_BCRYPT);
$table = $reset['role'] === 'runner' ? 'runners' : 'users';

$db->prepare("UPDATE $table SET password_hash = ? WHERE email = ?")
   ->execute([$hash, $reset['email']]);

$db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
   ->execute([$token]);

respond(['message' => 'Password reset successfully. You can now log in.']);