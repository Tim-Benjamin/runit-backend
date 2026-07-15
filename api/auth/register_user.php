<?php
// api/auth/register_user.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$body  = json_decode(file_get_contents('php://input'), true);
$name  = trim($body['name'] ?? '');
$email = trim($body['email'] ?? '');
$phone = trim($body['phone'] ?? '');
$pass  = $body['password'] ?? '';

if (!$name || !$email || !$phone || !$pass) {
    respondError('All fields are required');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Invalid email address');
}

if (strlen($pass) < 6) {
    respondError('Password must be at least 6 characters');
}

$db = getDB();

// Check if email already exists
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    respondError('An account with this email already exists');
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$stmt = $db->prepare(
    "INSERT INTO users (name, email, password_hash, phone) VALUES (?, ?, ?, ?)"
);
$stmt->execute([$name, $email, $hash, $phone]);

respond(['message' => 'Account created successfully. You can now log in.'], 201);