<?php
// api/admin/shops.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $name     = trim($body['name'] ?? '');
    $category = $body['category'] ?? '';
    $location = trim($body['location_description'] ?? '');
    $phone    = trim($body['phone'] ?? '');

    if (!$name || !$category || !$location || !$phone) respondError('All fields are required');

    $valid = ['Food','Groceries','Printing','Pharmacy','Other'];
    if (!in_array($category, $valid)) respondError('Invalid category');

    $db->prepare("INSERT INTO shops (name, category, location_description, phone) VALUES (?,?,?,?)")
       ->execute([$name, $category, $location, $phone]);

    respond(['message' => 'Shop added successfully'], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $id     = intval($body['id'] ?? 0);
    $status = $body['status'] ?? '';

    if (!$id) respondError('Shop ID required');
    if (!in_array($status, ['active','inactive'])) respondError('Invalid status');

    $db->prepare("UPDATE shops SET status = ? WHERE id = ?")->execute([$status, $id]);
    respond(['message' => 'Shop updated']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id'] ?? 0);
    if (!$id) respondError('Shop ID required');
    $db->prepare("DELETE FROM shops WHERE id = ?")->execute([$id]);
    respond(['message' => 'Shop deleted']);
}

respondError('Method not allowed', 405);