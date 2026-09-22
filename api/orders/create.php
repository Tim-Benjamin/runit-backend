<?php
// api/orders/create.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';
require_once '../referral/complete.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$user = requireAuth(['user']);
$body = json_decode(file_get_contents('php://input'), true);

$description    = trim($body['description']    ?? '');
$category       = $body['category']            ?? '';
$notes          = trim($body['notes']          ?? '');
$fee            = floatval($body['proposed_fee'] ?? 0);
$lat            = $body['delivery_lat']         ?? null;
$lng            = $body['delivery_lng']         ?? null;
$pickup_lat     = $body['pickup_lat']           ?? null;
$pickup_lng     = $body['pickup_lng']           ?? null;
$pickup_address = trim($body['pickup_address']  ?? '');
$dropoff_address= trim($body['dropoff_address'] ?? '');
$pickup_phone   = trim($body['pickup_phone']    ?? '');
$cylinder_size  = $body['cylinder_size']        ?? null;

$scheduled_for = null;
$is_scheduled  = 0;

if (!empty($body['scheduled_for'])) {
    $scheduled_dt = strtotime($body['scheduled_for']);
    if ($scheduled_dt && $scheduled_dt > time() + 300) { // at least 5 min in future
        $scheduled_for = date('Y-m-d H:i:s', $scheduled_dt);
        $is_scheduled  = 1;
    } else {
        respondError('Scheduled time must be at least 5 minutes in the future');
    }
}

$valid_cats = ['Food & Drinks','Errands','Shopping','Custom','Pickup & Drop','Gas Refill'];

if (!$description)                    respondError('Order description is required');
if (!in_array($category, $valid_cats)) respondError('Invalid category');
if ($fee < 1)                         respondError('Delivery fee must be at least GH₵ 1');
if (!$lat || !$lng)                   respondError('Delivery location is required');

// Pickup & Drop requires pickup location
if ($category === 'Pickup & Drop' && (!$pickup_lat || !$pickup_lng)) {
    respondError('Pickup location is required for Pickup & Drop orders');
}

// Gas Refill requires cylinder size
if ($category === 'Gas Refill' && !in_array($cylinder_size, ['small','medium','large'])) {
    respondError('Please select a cylinder size');
}

$valid_sizes = ['small','medium','large',null];
if (!in_array($cylinder_size, $valid_sizes)) $cylinder_size = null;

// Scheduled orders start as 'scheduled' instead of 'pending' so they don't
// show up in the runner feed until something promotes them later.
$initial_status = $is_scheduled ? 'scheduled' : 'pending';

$db = getDB();

// ── Apply promo code ──
// Run AFTER all validation above has passed (not right after $fee is set),
// so a promo's usage counter never gets incremented for an order that then
// fails validation and never gets created. This is applied to $fee, the
// same variable already used everywhere else in this file (the snippet's
// $proposed_fee name doesn't exist here).
$promo_code     = null;
$promo_discount = 0;
$original_fee   = $fee;

if (!empty($body['promo_code'])) {
    $pcode = strtoupper(trim($body['promo_code']));
    $ps    = $db->prepare("
        SELECT * FROM promo_codes
        WHERE code = ? AND is_active = 1
        AND (expires_at IS NULL OR expires_at > NOW())
        AND (max_uses IS NULL OR uses < max_uses)
        LIMIT 1
    ");
    $ps->execute([$pcode]);
    $promo = $ps->fetch();

    if ($promo && $fee >= floatval($promo['min_order'])) {
        if ($promo['type'] === 'percent') {
            $promo_discount = round($fee * (floatval($promo['value']) / 100), 2);
        } else {
            $promo_discount = min(floatval($promo['value']), $fee);
        }
        $fee        = max(0, $fee - $promo_discount);
        $promo_code = $pcode;

        // Increment usage count
        $db->prepare("UPDATE promo_codes SET uses = uses + 1 WHERE code = ?")
           ->execute([$pcode]);
    }
}

$stmt = $db->prepare("
    INSERT INTO orders (
        user_id, description, category, notes,
        proposed_fee, delivery_lat, delivery_lng,
        pickup_lat, pickup_lng, pickup_address, dropoff_address,
        pickup_phone, cylinder_size, scheduled_for, is_scheduled, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $user['id'],
    $description,
    $category,
    $notes ?: null,
    $fee,
    $lat,
    $lng,
    $pickup_lat  ?: null,
    $pickup_lng  ?: null,
    $pickup_address  ?: null,
    $dropoff_address ?: null,
    $pickup_phone    ?: null,
    $cylinder_size,
    $scheduled_for,
    $is_scheduled,
    $initial_status,
]);

$order_id = $db->lastInsertId();

$db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, ?)")
   ->execute([$order_id, $initial_status]);

// Complete referral on first order
$order_count = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$order_count->execute([$user['id']]);
if ((int)$order_count->fetchColumn() === 1) {
    completeReferral($db, $user['id']);
}

   
 // Push notifications
try {
    require_once '../../config/push.php';

    // 1. Confirm to user their order was placed
    sendPushToUser($db, $user['id'], 'user',
        $is_scheduled ? '📅 Order scheduled!' : '✅ Order placed!',
        $is_scheduled
            ? 'Your order is scheduled for ' . date('M j, g:i A', strtotime($scheduled_for)) . '.'
            : 'Your order is live. Runners near you are being notified now.',
        ['url' => '/orders/' . $order_id, 'order_id' => (int)$order_id]
    );

    // 2. Notify all runners — skip for scheduled orders, they aren't
    // actionable yet and shouldn't hit the feed until they're promoted.
    if (!$is_scheduled) {
        $runner_ids = $db->query("SELECT DISTINCT user_id FROM push_subscriptions WHERE user_role = 'runner'")
                         ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($runner_ids as $rid) {
            sendPushToUser($db, $rid, 'runner',
                '📦 New order!',
                $description . ' · GH₵ ' . number_format($fee, 2),
                ['url' => '/runner/feed', 'order_id' => (int)$order_id, 'sound' => 'order']
            );
        }
    }

    // 3. Notify admin
    sendPushToRole($db, 'admin',
        '📦 New order #' . $order_id,
        $category . ' — GH₵ ' . number_format($fee, 2) . ($is_scheduled ? ' (scheduled)' : ''),
        ['url' => '/admin/orders', 'order_id' => (int)$order_id]
    );
} catch (Throwable $e) {
    // Throwable (not just Exception) so a fatal error/TypeError in push.php
    // can't take down this response — the order row already exists by now.
    error_log('Push error in create: ' . $e->getMessage());
}

// Email notification
// Wrapped the same way as push notifications above: the order is already
// committed to the database at this point, so a mail failure (bad SMTP
// creds, a fatal error inside mailer.php, etc.) must never prevent the
// success JSON from being returned to the client.
try {
    require_once '../../config/mailer.php';
    $user_stmt = $db->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
    $user_stmt->execute([$user['id']]);
    $user_data = $user_stmt->fetch();
    if ($user_data) {
        emailOrderPlaced($user_data['email'], $user_data['name'], [
            'description'  => $description,
            'category'     => $category,
            'proposed_fee' => $fee,
        ]);
    }
} catch (Throwable $e) {
    error_log('Email error in create: ' . $e->getMessage());
}

respond([
    'message'        => $is_scheduled ? 'Order scheduled successfully' : 'Order placed successfully',
    'order_id'       => (int)$order_id,
    'proposed_fee'   => $fee,
    'original_fee'   => $original_fee,
    'promo_code'     => $promo_code,
    'promo_discount' => $promo_discount,
], 201);