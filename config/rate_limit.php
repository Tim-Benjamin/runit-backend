<?php
// config/rate_limit.php
// Stores attempt counts in MySQL — no Redis needed on shared hosting

function checkRateLimit($db, $key, $max_attempts = 5, $window_seconds = 300) {
    // key = "login_email@example.com" or "register_ip_x.x.x.x"
    $now = time();

    // Clean old entries first
    $db->prepare("
        DELETE FROM rate_limits
        WHERE expires_at < ?
    ")->execute([$now]);

    // Get current count
    $stmt = $db->prepare("
        SELECT attempts, expires_at FROM rate_limits
        WHERE limit_key = ? LIMIT 1
    ");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if ($row) {
        if ($row['attempts'] >= $max_attempts) {
            $wait = ceil(($row['expires_at'] - $now) / 60);
            respondError('Too many attempts. Try again in ' . $wait . ' minute' . ($wait !== 1 ? 's' : '') . '.', 429);
        }
        // Increment
        $db->prepare("
            UPDATE rate_limits SET attempts = attempts + 1 WHERE limit_key = ?
        ")->execute([$key]);
    } else {
        // First attempt
        $db->prepare("
            INSERT INTO rate_limits (limit_key, attempts, expires_at)
            VALUES (?, 1, ?)
        ")->execute([$key, $now + $window_seconds]);
    }
}

function clearRateLimit($db, $key) {
    $db->prepare("DELETE FROM rate_limits WHERE limit_key = ?")->execute([$key]);
}