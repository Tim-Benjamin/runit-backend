<?php
// api/admin/settlements.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT s.*, r.name AS runner_name FROM settlements s JOIN runners r ON s.runner_id = r.id ORDER BY s.marked_at DESC");
    $stmt->execute();
    respond(['settlements' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $runner_id = intval($body['runner_id'] ?? 0);
    $amount    = floatval($body['amount'] ?? 0);

    if (!$runner_id) respondError('Runner ID required');
    if ($amount <= 0) respondError('Amount must be greater than 0');

    $now   = date('Y-m-d');
    $start = date('Y-m-d', strtotime('last monday'));

    $db->prepare("
        INSERT INTO settlements (runner_id, amount, period_start, period_end, status, marked_by, marked_at)
        VALUES (?, ?, ?, ?, 'paid', ?, NOW())
    ")->execute([$runner_id, $amount, $start, $now, $admin['id']]);

    respond(['message' => 'Settlement recorded successfully']);
}

respondError('Method not allowed', 405);