<?php
// api/admin/stats.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

// Total orders
$total_orders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();

// Active runners
$active_runners = $db->query("SELECT COUNT(*) FROM runners WHERE status = 'active'")->fetchColumn();

// Pending runners
$pending_runners = $db->query("SELECT COUNT(*) FROM runners WHERE status = 'pending'")->fetchColumn();

// Total users
$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Revenue (20% of all delivered orders)
$revenue = $db->query("
    SELECT COALESCE(SUM(platform_cut), 0)
    FROM runner_earnings
")->fetchColumn();

// Orders by status
$status_counts = $db->query("
    SELECT status, COUNT(*) as count
    FROM orders GROUP BY status
")->fetchAll();

$by_status = [];
foreach ($status_counts as $row) {
    $by_status[$row['status']] = (int)$row['count'];
}

// Recent orders (last 10)
$recent_orders = $db->query("
    SELECT o.*, u.name AS user_name, r.name AS runner_name
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN runners r ON o.runner_id = r.id
    ORDER BY o.created_at DESC
    LIMIT 10
")->fetchAll();

// Pending runner applications
$pending_runner_list = $db->query("
    SELECT * FROM runners WHERE status = 'pending'
    ORDER BY created_at DESC
")->fetchAll();

respond([
    'stats' => [
        'total_orders'    => (int)$total_orders,
        'active_runners'  => (int)$active_runners,
        'pending_runners' => (int)$pending_runners,
        'total_users'     => (int)$total_users,
        'revenue'         => floatval($revenue),
        'by_status'       => $by_status,
    ],
    'recent_orders'      => $recent_orders,
    'pending_runners'    => $pending_runner_list,
]);