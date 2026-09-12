<?php
// api/runner/earnings.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondError('Method not allowed', 405);
}

$runner = requireAuth(['runner']);
$db     = getDB();

// Overall totals
$totals_stmt = $db->prepare("
    SELECT
        COUNT(DISTINCT e.order_id)          AS total_orders,
        COALESCE(SUM(e.delivery_fee), 0)    AS total_fees_collected,
        COALESCE(SUM(e.runner_cut), 0)      AS total_runner_cut,
        COALESCE(SUM(e.platform_cut), 0)    AS total_platform_cut,
        COALESCE(
            (SELECT SUM(s.amount)
             FROM settlements s
             WHERE s.runner_id = ? AND s.status = 'paid'), 0
        ) AS total_settled
    FROM runner_earnings e
    WHERE e.runner_id = ?
");
$totals_stmt->execute([$runner['id'], $runner['id']]);
$totals = $totals_stmt->fetch();

// Per-order earnings with order description
$earnings_stmt = $db->prepare("
    SELECT e.*,
           o.description,
           o.category
    FROM runner_earnings e
    JOIN orders o ON e.order_id = o.id
    WHERE e.runner_id = ?
    ORDER BY e.created_at DESC
");
$earnings_stmt->execute([$runner['id']]);
$earnings = $earnings_stmt->fetchAll();

// Settlement history
$settlements_stmt = $db->prepare("
    SELECT *
    FROM settlements
    WHERE runner_id = ? AND status = 'paid'
    ORDER BY marked_at DESC
");
$settlements_stmt->execute([$runner['id']]);
$settlements = $settlements_stmt->fetchAll();

// This week's stats (Mon–Sun)
$week_start = date('Y-m-d', strtotime('last monday'));
$week_stmt  = $db->prepare("
    SELECT
        COUNT(*)                         AS week_orders,
        COALESCE(SUM(e.runner_cut), 0)  AS week_earned,
        COALESCE(SUM(e.platform_cut), 0) AS week_owed
    FROM runner_earnings e
    WHERE e.runner_id = ?
      AND e.created_at >= ?
");
$week_stmt->execute([$runner['id'], $week_start]);
$week = $week_stmt->fetch();

respond([
    'totals'      => $totals,
    'week'        => $week,
    'earnings'    => $earnings,
    'settlements' => $settlements,
]);