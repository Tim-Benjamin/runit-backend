<?php
// api/auth/login.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$body  = json_decode(file_get_contents('php://input'), true);
$email = trim($body['email'] ?? '');
$pass  = $body['password'] ?? '';

if (!$email || !$pass) {
    respondError('Email and password are required');
}

$db = getDB();

// Check each role table in order
$roles = [
    'user'   => 'users',
    'runner' => 'runners',
    'admin'  => 'admins',
];

foreach ($roles as $role => $table) {
    $stmt = $db->prepare("SELECT * FROM $table WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {

        // Block pending/suspended runners
        if ($role === 'runner') {
            if ($user['status'] === 'pending') {
                respondError('Your account is awaiting admin approval.', 403);
            }
            if ($user['status'] === 'suspended') {
                respondError('Your account has been suspended. Contact admin.', 403);
            }
        }

        // Block suspended users
        if ($role === 'user' && $user['status'] === 'suspended') {
            respondError('Your account has been suspended.', 403);
        }

        $payload = [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $role,
            'iat'   => time(),
            'exp'   => time() + (7 * 24 * 60 * 60), // 7 days
        ];

        if ($role === 'runner') {
            $payload['phone']  = $user['phone'];
            $payload['status'] = $user['status'];
        }

        $token = generateToken($payload);

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
}

respondError('Invalid email or password', 401);