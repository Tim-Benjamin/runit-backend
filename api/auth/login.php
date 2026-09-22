<?php
// api/auth/login.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$body     = json_decode(file_get_contents('php://input'), true);
$email    = strtolower(trim($body['email']    ?? ''));
$password = $body['password'] ?? '';

if (!$email || !$password) respondError('Email and password required');

$db = getDB();

// Rate limiting — only if table exists
try {
    require_once '../../config/rate_limit.php';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    checkRateLimit($db, 'login_' . $email, 5, 300);
    checkRateLimit($db, 'login_ip_' . $ip, 20, 600);
} catch (Exception $e) {
    // Rate limit table not set up yet — continue without it
    error_log('Rate limit skipped: ' . $e->getMessage());
}

// Check each role table
$roles = [
    'user'   => 'users',
    'runner' => 'runners',
    'admin'  => 'admins',
];

foreach ($roles as $role => $table) {
    $stmt = $db->prepare("SELECT * FROM $table WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) continue;

    // ← Fixed: was $pass, must be $password
    if (!password_verify($password, $user['password_hash'])) continue;

    // Block pending/suspended runners
    if ($role === 'runner') {
        if (($user['status'] ?? '') === 'pending') {
            respondError('Your account is awaiting admin approval.', 403);
        }
        if (($user['status'] ?? '') === 'suspended') {
            respondError('Your account has been suspended. Contact admin.', 403);
        }
    }

    // Block suspended users (only if status column exists)
    if ($role === 'user' && isset($user['status']) && $user['status'] === 'suspended') {
        respondError('Your account has been suspended.', 403);
    }

    $payload = [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $role,
        'iat'   => time(),
        'exp'   => time() + (7 * 24 * 60 * 60),
    ];

    if ($role === 'runner') {
        $payload['phone']  = $user['phone']  ?? null;
        $payload['status'] = $user['status'] ?? null;
    }

    $token = generateToken($payload);

    // Clear rate limit on success
    try {
        clearRateLimit($db, 'login_' . $email);
    } catch (Exception $e) {}

    respond([
        'token' => $token,
        'user'  => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $role,
            'phone' => $user['phone'] ?? null,
        ],
    ]);
}

respondError('Invalid email or password', 401);