<?php
// api/admin/promos.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

// GET — list all promos
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT * FROM promo_codes ORDER BY created_at DESC");
    $stmt->execute();
    respond(['promos' => $stmt->fetchAll()]);
}

// POST — create promo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $code      = strtoupper(trim($body['code']      ?? ''));
    $type      = $body['type']      ?? 'percent';
    $value     = floatval($body['value']    ?? 0);
    $min_order = floatval($body['min_order'] ?? 0);
    $max_uses  = isset($body['max_uses']) && $body['max_uses'] !== '' ? intval($body['max_uses']) : null;
    $expires   = !empty($body['expires_at']) ? $body['expires_at'] : null;

    if (!$code)                              respondError('Code required');
    if (!preg_match('/^[A-Z0-9]{3,20}$/', $code)) respondError('Code must be 3-20 uppercase letters/numbers');
    if (!in_array($type, ['percent','fixed']))     respondError('Invalid type');
    if ($value <= 0)                         respondError('Value must be greater than 0');
    if ($type === 'percent' && $value > 100) respondError('Percent cannot exceed 100');

    try {
        $db->prepare("
            INSERT INTO promo_codes (code, type, value, min_order, max_uses, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$code, $type, $value, $min_order, $max_uses, $expires]);
        respond(['message' => 'Promo code ' . $code . ' created'], 201);
    } catch (Exception $e) {
        respondError('Code already exists');
    }
}

// PUT — toggle active
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $id        = intval($body['id']        ?? 0);
    $is_active = intval($body['is_active'] ?? 0);
    if (!$id) respondError('ID required');
    $db->prepare("UPDATE promo_codes SET is_active = ? WHERE id = ?")
       ->execute([$is_active, $id]);
    respond(['message' => 'Updated']);
}

// DELETE
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id'] ?? 0);
    if (!$id) respondError('ID required');
    $db->prepare("DELETE FROM promo_codes WHERE id = ?")->execute([$id]);
    respond(['message' => 'Deleted']);
}

respondError('Method not allowed', 405);