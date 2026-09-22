<?php
// api/promo/validate.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth = requireAuth(['user']);
$body = json_decode(file_get_contents('php://input'), true);
$code = strtoupper(trim($body['code'] ?? ''));
$fee  = floatval($body['fee'] ?? 0);

if (!$code) respondError('Promo code required');
if ($fee <= 0) respondError('Fee required');

$db = getDB();

$stmt = $db->prepare("
    SELECT * FROM promo_codes
    WHERE code = ? AND is_active = 1
    AND (expires_at IS NULL OR expires_at > NOW())
    AND (max_uses IS NULL OR uses < max_uses)
    LIMIT 1
");
$stmt->execute([$code]);
$promo = $stmt->fetch();

if (!$promo) respondError('Invalid or expired promo code');
if ($fee < floatval($promo['min_order'])) {
    respondError('Minimum order fee of GH₵ ' . number_format($promo['min_order'], 2) . ' required for this code');
}

// Calculate discount
$discount = 0;
if ($promo['type'] === 'percent') {
    $discount = round($fee * (floatval($promo['value']) / 100), 2);
} else {
    $discount = min(floatval($promo['value']), $fee);
}

$final_fee = max(0, $fee - $discount);

respond([
    'valid'     => true,
    'code'      => $promo['code'],
    'type'      => $promo['type'],
    'value'     => floatval($promo['value']),
    'discount'  => $discount,
    'original'  => $fee,
    'final_fee' => $final_fee,
    'message'   => $promo['type'] === 'percent'
        ? floatval($promo['value']) . '% off applied — you save GH₵ ' . number_format($discount, 2)
        : 'GH₵ ' . number_format($discount, 2) . ' off applied',
]);