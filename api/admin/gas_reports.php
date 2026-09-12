<?php
// api/admin/gas_reports.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

// GET — list all disputed gas fills
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT
            gfr.id            AS report_id,
            gfr.order_id,
            gfr.confirmed_amount,
            gfr.dispute_note,
            gfr.resolved,
            gfr.resolution,
            gfr.created_at    AS reported_at,
            o.fill_amount,
            o.fill_receipt,
            o.cylinder_size,
            o.description,
            u.name            AS user_name,
            u.phone           AS user_phone,
            r.name            AS runner_name,
            r.phone           AS runner_phone,
            r.id              AS runner_id
        FROM gas_fill_reports gfr
        JOIN orders  o ON gfr.order_id  = o.id
        JOIN users   u ON o.user_id     = u.id
        JOIN runners r ON o.runner_id   = r.id
        ORDER BY gfr.created_at DESC
    ");
    $stmt->execute();
    respond(['reports' => $stmt->fetchAll()]);
}

// POST — resolve a dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $report_id = intval($body['report_id'] ?? 0);
    $action    = $body['action'] ?? '';

    if (!$report_id) respondError('Report ID required');
    if (!in_array($action, ['resolve_runner', 'resolve_user'])) respondError('Invalid action');

    // Fetch report + runner id + user id
    $r = $db->prepare("
        SELECT gfr.*, o.runner_id, o.user_id
        FROM gas_fill_reports gfr
        JOIN orders o ON gfr.order_id = o.id
        WHERE gfr.id = ? LIMIT 1
    ");
    $r->execute([$report_id]);
    $report = $r->fetch();

    if (!$report)          respondError('Report not found', 404);
    if ($report['resolved']) respondError('Already resolved');

    $resolution = $action === 'resolve_runner'
        ? 'Runner at fault — suspended'
        : 'User claim reviewed — runner cleared';

    // Mark resolved
    $db->prepare("
        UPDATE gas_fill_reports
        SET resolved = 1, resolution = ?, resolved_by = ?, resolved_at = NOW()
        WHERE id = ?
    ")->execute([$resolution, $admin['id'], $report_id]);

    // Suspend runner if at fault
    if ($action === 'resolve_runner') {
        $db->prepare("UPDATE runners SET status = 'suspended' WHERE id = ?")
           ->execute([$report['runner_id']]);
    }

    // Respond BEFORE push — push failure must never break the response
    respond(['message' => 'Dispute resolved: ' . $resolution]);

    // Push notifications after respond (output already sent)
    try {
        require_once '../../config/push.php';
        if ($action === 'resolve_runner') {
            sendPushToUser($db, $report['runner_id'], 'runner',
                '⚠️ Account suspended',
                'Your account was suspended following a gas fill dispute resolution. Contact admin.',
                ['url' => '/runner/profile']
            );
        }
        sendPushToUser($db, $report['user_id'], 'user',
            '📋 Dispute resolved',
            $action === 'resolve_runner'
                ? 'Your gas fill dispute was resolved in your favour. The runner has been suspended.'
                : 'Your gas fill dispute was reviewed. Admin found no fault with the runner.',
            ['url' => '/orders/' . $report['order_id']]
        );
    } catch (Exception $e) {
        error_log('Gas dispute push error: ' . $e->getMessage());
    }
}

respondError('Method not allowed', 405);