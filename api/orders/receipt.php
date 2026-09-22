<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$auth     = requireAuth(['user','runner','admin']);
$order_id = intval($_GET['id'] ?? 0);
if (!$order_id) respondError('Order ID required');

$db = getDB();

$stmt = $db->prepare("
    SELECT
        o.*,
        u.name          AS user_name,
        u.phone         AS user_phone,
        r.name          AS runner_name,
        r.phone         AS runner_phone,
        r.momo_number   AS runner_momo,
        r.delivery_method,
        rat.stars       AS rating,
        rat.comment     AS rating_comment,
        gfr.resolved    AS fill_resolved,
        gfr.resolution  AS fill_resolution
    FROM orders o
    JOIN users u             ON o.user_id    = u.id
    LEFT JOIN runners r      ON o.runner_id  = r.id
    LEFT JOIN ratings rat    ON rat.order_id = o.id
    LEFT JOIN gas_fill_reports gfr ON gfr.order_id = o.id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) respondError('Order not found', 404);

// Verify access
if ($auth['role'] === 'user'   && $order['user_id']   != $auth['id']) respondError('Forbidden', 403);
if ($auth['role'] === 'runner' && $order['runner_id']  != $auth['id']) respondError('Forbidden', 403);

// Status log
$log = $db->prepare("SELECT status, note, created_at FROM order_status_log WHERE order_id = ? ORDER BY created_at ASC");
$log->execute([$order_id]);
$order['status_log'] = $log->fetchAll();

respond(['order' => $order]);