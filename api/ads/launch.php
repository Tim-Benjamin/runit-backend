<?php
// api/ads/launch.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

$db = getDB();

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base     = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/ads/';

$stmt = $db->prepare("SELECT * FROM launch_ads WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
$stmt->execute();
$ad = $stmt->fetch();

if (!$ad) { respond(['ad' => null]); }

if ($ad['type'] === 'image') $ad['url'] = $base . $ad['content'];
respond(['ad' => $ad]);