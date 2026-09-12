<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$db       = getDB();
$category = $_GET['category'] ?? '';
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/shops/';

if ($category) {
    $stmt = $db->prepare("SELECT *, (view_count + boost_views) AS total_views FROM shops WHERE status = 'active' AND category = ? ORDER BY total_views DESC");
    $stmt->execute([$category]);
} else {
    $stmt = $db->prepare("SELECT *, (view_count + boost_views) AS total_views FROM shops WHERE status = 'active' ORDER BY total_views DESC");
    $stmt->execute();
}

$shops = $stmt->fetchAll();

foreach ($shops as &$shop) {
    $shop['image_url'] = $shop['image_path'] ? $base_url . $shop['image_path'] : null;
    $db->prepare("UPDATE shops SET view_count = view_count + 1 WHERE id = ?")
       ->execute([$shop['id']]);
}

respond(['shops' => $shops]);