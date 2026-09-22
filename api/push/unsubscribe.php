<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth = requireAuth(['user','runner','admin']);
$db   = getDB();

$db->prepare("DELETE FROM push_subscriptions WHERE user_id = ?")
   ->execute([$auth['id']]);

respond(['message' => 'Unsubscribed']);