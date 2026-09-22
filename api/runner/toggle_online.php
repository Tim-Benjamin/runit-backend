<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$runner = requireAuth(['runner']);
$db     = getDB();

$body      = json_decode(file_get_contents('php://input'), true);
$is_online = isset($body['is_online']) ? (int)!!$body['is_online'] : null;

if ($is_online === null) respondError('is_online required');

$db->prepare("
    UPDATE runners SET is_online = ?, last_seen = NOW() WHERE id = ?
")->execute([$is_online, $runner['id']]);

respond([
    'message'   => $is_online ? 'You are now online' : 'You are now offline',
    'is_online' => (bool)$is_online,
]);