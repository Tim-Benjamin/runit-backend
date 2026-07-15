<?php
// api/orders/create.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

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

$db = getDB();

$stmt = $db->prepare("
    INSERT INTO orders (
        user_id, description, category, notes,
        proposed_fee, delivery_lat, delivery_lng,
        pickup_lat, pickup_lng, pickup_address, dropoff_address,
        pickup_phone, cylinder_size
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
]);

$order_id = $db->lastInsertId();

$db->prepare("INSERT INTO order_status_log (order_id, status) VALUES (?, 'pending')")
   ->execute([$order_id]);

   
 // Push notifications
try {
    require_once '../../config/push.php';

    // 1. Confirm to user their order was placed
    sendPushToUser($db, $user['id'], 'user',
        '✅ Order placed!',
        'Your order is live. Runners near you are being notified now.',
        ['url' => '/orders/' . $order_id, 'order_id' => (int)$order_id]
    );

    // 2. Notify all runners
    $runner_ids = $db->query("SELECT DISTINCT user_id FROM push_subscriptions WHERE user_role = 'runner'")
                     ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($runner_ids as $rid) {
        sendPushToUser($db, $rid, 'runner',
            '📦 New order!',
            $description . ' · GH₵ ' . number_format($fee, 2),
            ['url' => '/runner/feed', 'order_id' => (int)$order_id, 'sound' => 'order']
        );
    }

    // 3. Notify admin
    sendPushToRole($db, 'admin',
        '📦 New order #' . $order_id,
        $category . ' — GH₵ ' . number_format($fee, 2),
        ['url' => '/admin/orders', 'order_id' => (int)$order_id]
    );
} catch (Exception $e) {
    error_log('Push error in create: ' . $e->getMessage());
}

// Email notification
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
respond(['message' => 'Order placed successfully', 'order_id' => (int)$order_id], 201);