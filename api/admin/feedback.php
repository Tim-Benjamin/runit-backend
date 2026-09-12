<?php
// api/admin/feedback.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'all';

    if ($type === 'ratings' || $type === 'all') {
        $stmt = $db->prepare("
            SELECT r.*, u.name AS user_name, ru.name AS runner_name
            FROM ratings r
            JOIN users   u  ON r.user_id   = u.id
            JOIN runners ru ON r.runner_id = ru.id
            JOIN orders  o  ON r.order_id  = o.id
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        $stmt->execute();
        $ratings = $stmt->fetchAll();
    }

    if ($type === 'reports' || $type === 'all') {
        $stmt = $db->prepare("
            SELECT rp.*,
                   o.description AS order_description,
                   CASE rp.reporter_role
                     WHEN 'user'   THEN u.name
                     WHEN 'runner' THEN ru.name
                   END AS reported_by_name
            FROM reports rp
            JOIN orders  o  ON rp.order_id    = o.id
            LEFT JOIN users   u  ON rp.reporter_role = 'user'   AND rp.reporter_id = u.id
            LEFT JOIN runners ru ON rp.reporter_role = 'runner' AND rp.reporter_id = ru.id
            ORDER BY rp.created_at DESC
            LIMIT 100
        ");
        $stmt->execute();
        $reports = $stmt->fetchAll();
    }

    if ($type === 'gas' || $type === 'all') {
        $stmt = $db->prepare("
            SELECT
                gfr.id          AS report_id,
                gfr.order_id,
                gfr.confirmed_amount,
                gfr.dispute_note,
                gfr.resolved,
                gfr.resolution,
                gfr.created_at  AS reported_at,
                o.fill_amount,
                o.fill_receipt,
                o.cylinder_size,
                o.description,
                u.name          AS user_name,
                u.phone         AS user_phone,
                r.name          AS runner_name,
                r.phone         AS runner_phone,
                r.id            AS runner_id
            FROM gas_fill_reports gfr
            JOIN orders  o ON gfr.order_id  = o.id
            JOIN users   u ON o.user_id     = u.id
            JOIN runners r ON o.runner_id   = r.id
            ORDER BY gfr.created_at DESC
        ");
        $stmt->execute();
        $gas_reports = $stmt->fetchAll();
    }

    $response = [];
    if (isset($ratings))     $response['ratings']     = $ratings;
    if (isset($reports))     $response['reports']     = $reports;
    if (isset($gas_reports)) $response['gas_reports'] = $gas_reports;

    respond($response);
}

// POST — resolve a regular report
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $report_id = intval($body['report_id'] ?? 0);
    if (!$report_id) respondError('Report ID required');

    $db->prepare("
        UPDATE reports
        SET status = 'resolved', resolved_by = ?, resolved_at = NOW()
        WHERE id = ?
    ")->execute([$admin['id'], $report_id]);

    respond(['message' => 'Report marked as resolved']);
}

respondError('Method not allowed', 405);