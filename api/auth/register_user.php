<?php
// api/auth/register_user.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/rate_limit.php';

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

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// 10 registrations per IP per hour
checkRateLimit($db, 'register_' . $ip, 10, 3600);

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

$user_id = $db->lastInsertId();

// Handle referral code
if (!empty($body['referred_by'])) {
    $ref_code = strtoupper(trim($body['referred_by']));

    // Find referrer in users
    $rs = $db->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
    $rs->execute([$ref_code]);
    $referrer = $rs->fetch();
    $ref_role = 'user';

    if (!$referrer) {
        $rs = $db->prepare("SELECT id FROM runners WHERE referral_code = ? LIMIT 1");
        $rs->execute([$ref_code]);
        $referrer = $rs->fetch();
        $ref_role = 'runner';
    }

    if ($referrer) {
        // Save referred_by on new user
        $db->prepare("UPDATE users SET referred_by = ? WHERE id = ?")
           ->execute([$ref_code, $user_id]);

        // Record referral
        $db->prepare("
            INSERT INTO referrals (referrer_id, referrer_role, referred_id, referred_role)
            VALUES (?, ?, ?, 'user')
        ")->execute([$referrer['id'], $ref_role, $user_id]);
    }
}

// Generate referral code for new user
$ref_code_new = 'U' . strtoupper(substr(md5($user_id), 0, 7));
$db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")
   ->execute([$ref_code_new, $user_id]);

respond(['message' => 'Account created successfully. You can now log in.'], 201);