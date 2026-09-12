<?php
// api/location/get.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$auth     = requireAuth(['runner', 'admin']);
$order_id = intval($_GET['order_id'] ?? 0);

if (!$order_id) respondError('order_id is required');

$db   = getDB();
$stmt = $db->prepare("
    SELECT lat, lng, updated_at
    FROM user_location
    WHERE order_id = ?
    ORDER BY updated_at DESC
    LIMIT 1
");
$stmt->execute([$order_id]);
$loc = $stmt->fetch();

if (!$loc) respondError('No location data yet', 404);

respond(['location' => $loc]);