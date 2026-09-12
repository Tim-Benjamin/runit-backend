<?php
// api/admin/runners.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/runner_ids/';

// ── GET — list all runners with stats + ID file URL ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $db->prepare("
        SELECT
            r.*,
            COUNT(DISTINCT o.id)                                          AS total_orders,
            COALESCE(SUM(e.platform_cut), 0)                              AS total_owed,
            COALESCE(SUM(CASE WHEN s.status = 'paid' THEN s.amount ELSE 0 END), 0) AS total_settled,
            AVG(rt.stars)                                                 AS avg_rating,
            COUNT(DISTINCT rt.id)                                         AS total_ratings
        FROM runners r
        LEFT JOIN orders          o  ON o.runner_id  = r.id AND o.status = 'delivered'
        LEFT JOIN runner_earnings e  ON e.runner_id  = r.id
        LEFT JOIN settlements     s  ON s.runner_id  = r.id
        LEFT JOIN ratings         rt ON rt.runner_id = r.id
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $runners = $stmt->fetchAll();

    foreach ($runners as &$runner) {
        // Build full ID file URL — check both column names for compatibility
        $id_file = $runner['id_file_path'] ?? $runner['id_document'] ?? null;
        $runner['id_file_url'] = $id_file ? $base_url . $id_file : null;

        // Remove password hash — never send to frontend
        unset($runner['password_hash']);
    }
    unset($runner);

    respond(['runners' => $runners]);
}

// ── POST — update runner status ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $runner_id = intval($body['runner_id'] ?? 0);
    $status    = $body['status']           ?? '';

    if (!$runner_id) respondError('Runner ID required');
    if (!in_array($status, ['active','suspended'])) respondError('Invalid status');

    // Get runner info before updating
    $r = $db->prepare("SELECT name, email FROM runners WHERE id = ? LIMIT 1");
    $r->execute([$runner_id]);
    $runner_data = $r->fetch();

    if (!$runner_data) respondError('Runner not found', 404);

    $db->prepare("UPDATE runners SET status = ? WHERE id = ?")
       ->execute([$status, $runner_id]);

    // Push notification to runner
    try {
        require_once '../../config/push.php';
        if ($status === 'active') {
            sendPushToUser($db, $runner_id, 'runner',
                '🎉 Account approved!',
                'Congratulations ' . $runner_data['name'] . '! Your runner account is now active. Head to the feed to start accepting orders.',
                ['url' => '/runner/feed']
            );
        } else {
            sendPushToUser($db, $runner_id, 'runner',
                '⚠️ Account suspended',
                'Your account has been suspended. Please contact admin for more information.',
                ['url' => '/runner/profile']
            );
        }
    } catch (Exception $e) { /* push not critical */ }

    respond([
        'message' => 'Runner ' . $runner_data['name'] . ' status updated to ' . $status,
    ]);
}

respondError('Method not allowed', 405);