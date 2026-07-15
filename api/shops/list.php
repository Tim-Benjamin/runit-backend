<?php
// api/shops/list.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$db       = getDB();
$category = $_GET['category'] ?? '';

if ($category) {
    $stmt = $db->prepare("SELECT * FROM shops WHERE status = 'active' AND category = ? ORDER BY name");
    $stmt->execute([$category]);
} else {
    $stmt = $db->prepare("SELECT * FROM shops WHERE status = 'active' ORDER BY name");
    $stmt->execute();
}

respond(['shops' => $stmt->fetchAll()]);