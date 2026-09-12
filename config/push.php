<?php
// config/push.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/vapid.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function getPushInstance() {
    return new WebPush([
        'VAPID' => [
            'subject'    => VAPID_SUBJECT,
            'publicKey'  => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ], ['TTL' => 86400, 'urgency' => 'high']);
}

function sendPushToUser($db, $user_id, $user_role, $title, $body, $data = []) {
    try {
        $stmt = $db->prepare("
            SELECT endpoint, p256dh, auth
            FROM push_subscriptions
            WHERE user_id = ? AND user_role = ?
        ");
        $stmt->execute([$user_id, $user_role]);
        $subs = $stmt->fetchAll();

        if (empty($subs)) return;

        $webPush = getPushInstance();

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/favicon.svg',
            'badge' => '/favicon.svg',
            'data'  => array_merge(['url' => '/'], $data),
        ]);

        $staleEndpoints = [];

        foreach ($subs as $sub) {
            try {
                $subscription = Subscription::create([
                    'endpoint'        => $sub['endpoint'],
                    'contentEncoding' => 'aesgcm',
                    'keys'            => [
                        'p256dh' => $sub['p256dh'],
                        'auth'   => $sub['auth'],
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            } catch (Exception $e) {
                error_log('Push queue error: ' . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $staleEndpoints[] = $report->getEndpoint();
            }
        }

        // Clean expired subscriptions
        foreach ($staleEndpoints as $ep) {
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
               ->execute([$ep]);
        }

    } catch (Exception $e) {
        error_log('sendPushToUser error: ' . $e->getMessage());
    }
}

function sendPushToRole($db, $role, $title, $body, $data = []) {
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT user_id FROM push_subscriptions WHERE user_role = ?
        ");
        $stmt->execute([$role]);
        $users = $stmt->fetchAll();

        foreach ($users as $u) {
            sendPushToUser($db, $u['user_id'], $role, $title, $body, $data);
        }
    } catch (Exception $e) {
        error_log('sendPushToRole error: ' . $e->getMessage());
    }
}