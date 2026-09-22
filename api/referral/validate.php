<?php
// api/referral/validate.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true);
$code = strtoupper(trim($body['code'] ?? ''));

if (!$code) respondError('Referral code required');

$db = getDB();

// Check users
$stmt = $db->prepare("SELECT id, name, referral_code FROM users WHERE referral_code = ? LIMIT 1");
$stmt->execute([$code]);
$ref = $stmt->fetch();
if ($ref) { respond(['valid' => true, 'name' => $ref['name'], 'role' => 'user']); }

// Check runners
$stmt = $db->prepare("SELECT id, name, referral_code FROM runners WHERE referral_code = ? LIMIT 1");
$stmt->execute([$code]);
$ref = $stmt->fetch();
if ($ref) { respond(['valid' => true, 'name' => $ref['name'], 'role' => 'runner']); }

respondError('Invalid referral code');