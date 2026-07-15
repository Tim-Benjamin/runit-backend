<?php
// api/admin/fees.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT * FROM delivery_fees ORDER BY id");
    $stmt->execute();
    respond(['fees' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $category = $body['category'] ?? '';
    $fee      = floatval($body['base_fee'] ?? 0);

    if (!$category)  respondError('Category required');
    if ($fee < 0)    respondError('Fee cannot be negative');

    $db->prepare("UPDATE delivery_fees SET base_fee = ? WHERE category = ?")
       ->execute([$fee, $category]);

    respond(['message' => 'Fee updated']);
}

respondError('Method not allowed', 405);