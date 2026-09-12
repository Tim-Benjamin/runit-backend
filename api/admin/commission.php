<?php
// api/admin/commission.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT value FROM platform_settings WHERE `key` = 'commission_percent' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    respond(['commission' => $row ? floatval($row['value']) : 20]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $val  = floatval($body['commission'] ?? -1);

    if ($val < 0 || $val > 100) respondError('Commission must be between 0 and 100');

    $db->prepare("
        INSERT INTO platform_settings (`key`, `value`) VALUES ('commission_percent', ?)
        ON DUPLICATE KEY UPDATE `value` = ?
    ")->execute([$val, $val]);

    respond(['message' => 'Commission updated to ' . $val . '%']);
}

respondError('Method not allowed', 405);