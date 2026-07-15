<?php
// api/admin/feedback.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // All ratings
    $ratings = $db->prepare("
        SELECT r.*, u.name AS user_name, ru.name AS runner_name, o.description AS order_description
        FROM ratings r
        JOIN users   u  ON r.user_id   = u.id
        JOIN runners ru ON r.runner_id = ru.id
        JOIN orders  o  ON r.order_id  = o.id
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    $ratings->execute();

    // All reports
    $reports = $db->prepare("
        SELECT rp.*,
               o.description AS order_description,
               CASE rp.reporter_role
                 WHEN 'user'   THEN u.name
                 WHEN 'runner' THEN ru.name
               END AS reporter_name
        FROM reports rp
        JOIN orders  o  ON rp.order_id = o.id
        LEFT JOIN users   u  ON rp.reporter_role = 'user'   AND rp.reporter_id = u.id
        LEFT JOIN runners ru ON rp.reporter_role = 'runner' AND rp.reporter_id = ru.id
        ORDER BY rp.created_at DESC
        LIMIT 100
    ");
    $reports->execute();

    respond([
        'ratings' => $ratings->fetchAll(),
        'reports' => $reports->fetchAll(),
    ]);
}

// Resolve a report
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $report_id = intval($body['report_id'] ?? 0);
    if (!$report_id) respondError('Report ID required');

    $db->prepare("
        UPDATE reports SET status = 'resolved', resolved_by = ?, resolved_at = NOW()
        WHERE id = ?
    ")->execute([$admin['id'], $report_id]);

    respond(['message' => 'Report marked as resolved']);
}

respondError('Method not allowed', 405);