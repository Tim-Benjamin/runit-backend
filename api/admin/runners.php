<?php
// api/admin/runners.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT r.*,
               COUNT(DISTINCT o.id)  AS total_orders,
               COALESCE(SUM(e.platform_cut), 0) AS total_owed,
               COALESCE(SUM(CASE WHEN s.status='paid' THEN s.amount ELSE 0 END), 0) AS total_settled
        FROM runners r
        LEFT JOIN orders       o ON o.runner_id = r.id AND o.status = 'delivered'
        LEFT JOIN runner_earnings e ON e.runner_id = r.id
        LEFT JOIN settlements  s ON s.runner_id = r.id
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    respond(['runners' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $runner_id = intval($body['runner_id'] ?? 0);
    $status    = $body['status'] ?? '';

    if (!$runner_id)                              respondError('Runner ID required');
    if (!in_array($status, ['active','suspended'])) respondError('Invalid status');

    $db->prepare("UPDATE runners SET status = ? WHERE id = ?")
       ->execute([$status, $runner_id]);

    respond(['message' => 'Runner status updated to ' . $status]);
}

respondError('Method not allowed', 405);