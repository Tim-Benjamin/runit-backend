<?php
// api/admin/users.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT u.*,
               COUNT(DISTINCT o.id) AS total_orders,
               COALESCE(SUM(o.final_fee), 0) AS total_spent
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id AND o.status = 'delivered'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    respond(['users' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($body['user_id'] ?? 0);
    $status  = $body['status'] ?? '';

    if (!$user_id) respondError('User ID required');
    if (!in_array($status, ['active','suspended'])) respondError('Invalid status');

    $db->prepare("UPDATE users SET status = ? WHERE id = ?")
       ->execute([$status, $user_id]);

    respond(['message' => 'User status updated']);
}

respondError('Method not allowed', 405);