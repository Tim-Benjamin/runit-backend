<?php
// config/fcm.php — FCM v1 API using service account

define('FCM_SERVICE_ACCOUNT_PATH', '/home/u691168412/domains/runitgh.com/private/firebase-service-account.json');
define('FCM_PROJECT_ID', 'runit-2ce34');

// Get OAuth2 access token from service account JSON
function getFcmAccessToken() {
    $serviceAccount = json_decode(file_get_contents(FCM_SERVICE_ACCOUNT_PATH), true);
    if (!$serviceAccount) {
        error_log('[FCM] Could not read service account file');
        return null;
    }

    $now    = time();
    $expiry = $now + 3600;

    // Build JWT header + payload
    $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode([
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $expiry,
    ]));

    // Remove padding and make URL safe
    $header  = rtrim(strtr($header,  '+/', '-_'), '=');
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');

    $signingInput = $header . '.' . $payload;

    // Sign with private key
    $privateKey = $serviceAccount['private_key'];
    $key        = openssl_pkey_get_private($privateKey);
    if (!$key) {
        error_log('[FCM] Could not load private key');
        return null;
    }

    openssl_sign($signingInput, $signature, $key, 'SHA256');
    $signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $jwt = $signingInput . '.' . $signature;

    // Exchange JWT for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('[FCM] Token exchange failed: ' . $response);
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// Send FCM v1 message to a single device token
function sendFcmToToken($fcmToken, $title, $body, $data = []) {
    $accessToken = getFcmAccessToken();
    if (!$accessToken) {
        error_log('[FCM] No access token — skipping send');
        return false;
    }

    $isOrderAlert = isset($data['type']) && $data['type'] === 'new_order';
    $channelId    = $isOrderAlert ? 'order_alerts' : 'general';

    $message = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => array_map('strval', $data), // FCM v1 requires all data values as strings
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id'            => $channelId,
                    'sound'                 => $isOrderAlert ? 'order_alert' : 'default',
                    'notification_priority' => 'PRIORITY_MAX',
                    'visibility'            => 'PUBLIC',
                    'default_vibrate_timings' => false,
                    'vibrate_timings'       => ['0s', '0.5s', '0.2s', '0.5s', '0.2s', '1s'],
                ],
            ],
        ],
    ];

    $url = 'https://fcm.googleapis.com/v1/projects/' . FCM_PROJECT_ID . '/messages:send';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('[FCM] Send failed (' . $httpCode . '): ' . $response);
        return false;
    }

    return true;
}

// Send to all devices for a given role (e.g. all runners)
function sendFcmToRole($db, $role, $title, $body, $data = []) {
    $stmt = $db->prepare("SELECT token FROM fcm_tokens WHERE role = ?");
    $stmt->execute([$role]);
    $tokens = $stmt->fetchAll();

    $sent = 0;
    foreach ($tokens as $t) {
        if (sendFcmToToken($t['token'], $title, $body, $data)) {
            $sent++;
        }
    }
    error_log('[FCM] Sent to ' . $sent . ' ' . $role . '(s)');
}

// Send to a specific user
function sendFcmToUser($db, $userId, $role, $title, $body, $data = []) {
    $stmt = $db->prepare("
        SELECT token FROM fcm_tokens
        WHERE user_id = ? AND role = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $role]);
    $t = $stmt->fetch();
    if ($t) {
        sendFcmToToken($t['token'], $title, $body, $data);
    }
}