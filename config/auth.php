<?php
// config/auth.php

define('JWT_SECRET', 'runit_secret_key_change_this_in_production');

function generateToken($payload) {
    $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode($payload));
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

    if (!hash_equals($expected, $sig)) return null;

    return json_decode(base64_decode($payload), true);
}

function requireAuth($allowed_roles = []) {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!str_starts_with($auth, 'Bearer ')) {
        respondError('Unauthorized', 401);
    }

    $token   = substr($auth, 7);
    $payload = verifyToken($token);

    if (!$payload) {
        respondError('Invalid token', 401);
    }

    if (!empty($allowed_roles) && !in_array($payload['role'], $allowed_roles)) {
        respondError('Forbidden', 403);
    }

    return $payload;
}