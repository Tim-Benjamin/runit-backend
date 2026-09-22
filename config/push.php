<?php
// config/push.php

function _getPushLibrary() {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[Push] vendor/autoload.php not found');
        return false;
    }
    require_once $autoload;
    return true;
}

function sendPushToUser($db, $user_id, $user_role, $title, $body, $data = []) {
    if (!_getPushLibrary()) return false;

    try {
        require_once __DIR__ . '/vapid.php';

        $stmt = $db->prepare("
            SELECT endpoint,
                   p256dh,
                   COALESCE(NULLIF(auth_key,''), auth) AS auth_value
            FROM push_subscriptions
            WHERE user_id = ?
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $subs = $stmt->fetchAll();

        if (empty($subs)) return false;

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => defined('VAPID_SUBJECT')      ? VAPID_SUBJECT      : 'mailto:runitgh10@gmail.com',
                'publicKey'  => defined('VAPID_PUBLIC_KEY')   ? VAPID_PUBLIC_KEY   : '',
                'privateKey' => defined('VAPID_PRIVATE_KEY')  ? VAPID_PRIVATE_KEY  : '',
            ],
        ], [
            'TTL'     => 86400,
            'urgency' => 'high',
            'topic'   => 'runit-notification',
        ]);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icon-192.png',
            'badge' => '/icon-192.png',
            'data'  => array_merge(['url' => '/'], $data),
        ]);

        $stale = [];
        foreach ($subs as $sub) {
            if (empty($sub['endpoint']) || empty($sub['p256dh']) || empty($sub['auth_value'])) {
                continue;
            }
            try {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint'        => $sub['endpoint'],
                    'contentEncoding' => 'aesgcm',
                    'keys'            => [
                        'p256dh' => $sub['p256dh'],
                        'auth'   => $sub['auth_value'],
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            } catch (\Exception $e) {
                error_log('[Push] Queue error uid=' . $user_id . ': ' . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                error_log('[Push] Delivery failed uid=' . $user_id . ': ' . $report->getReason());
                if ($report->isSubscriptionExpired()) {
                    $stale[] = $report->getEndpoint();
                }
            }
        }

        // Clean expired subscriptions
        foreach ($stale as $ep) {
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
               ->execute([$ep]);
            error_log('[Push] Removed expired subscription for uid=' . $user_id);
        }

        return true;

    } catch (\Exception $e) {
        error_log('[Push] sendPushToUser exception uid=' . $user_id . ': ' . $e->getMessage());
        return false;
    }
}

function sendPushToRole($db, $role, $title, $body, $data = []) {
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT user_id
            FROM push_subscriptions
            WHERE user_role = ? OR role = ?
        ");
        $stmt->execute([$role, $role]);
        $users = $stmt->fetchAll();
        $sent  = 0;
        foreach ($users as $u) {
            if (sendPushToUser($db, $u['user_id'], $role, $title, $body, $data)) $sent++;
        }
        return $sent;
    } catch (\Exception $e) {
        error_log('[Push] sendPushToRole exception role=' . $role . ': ' . $e->getMessage());
        return 0;
    }
}