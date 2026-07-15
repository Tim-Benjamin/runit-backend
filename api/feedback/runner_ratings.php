<?php
// api/feedback/runner_ratings.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed', 405);

$auth = requireAuth(['runner', 'admin']);
$db   = getDB();

// Admin can view any runner, runner views own
$runner_id = $auth['role'] === 'admin'
    ? intval($_GET['runner_id'] ?? $auth['id'])
    : $auth['id'];

// Summary
$summary = $db->prepare("
    SELECT
        COUNT(*)            AS total_ratings,
        AVG(stars)          AS avg_stars,
        SUM(stars = 5)      AS five_star,
        SUM(stars = 4)      AS four_star,
        SUM(stars = 3)      AS three_star,
        SUM(stars = 2)      AS two_star,
        SUM(stars = 1)      AS one_star
    FROM ratings WHERE runner_id = ?
");
$summary->execute([$runner_id]);
$stats = $summary->fetch();

// Individual ratings
$ratings = $db->prepare("
    SELECT r.*, u.name AS user_name, o.description AS order_description
    FROM ratings r
    JOIN users  u ON r.user_id  = u.id
    JOIN orders o ON r.order_id = o.id
    WHERE r.runner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 50
");
$ratings->execute([$runner_id]);

respond([
    'stats'   => $stats,
    'ratings' => $ratings->fetchAll(),
]);