<?php
// api/feedback/report.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth     = requireAuth(['user', 'runner']);
$body     = json_decode(file_get_contents('php://input'), true);
$order_id = intval($body['order_id'] ?? 0);
$reason   = trim($body['reason']     ?? '');
$details  = trim($body['details']    ?? '');

$valid_reasons = [
    'wrong_item', 'runner_no_show', 'overcharge',
    'wrong_location', 'customer_unreachable',
    'rude_behavior', 'other'
];

if (!$order_id)                          respondError('Order ID required');
if (!in_array($reason, $valid_reasons))  respondError('Invalid reason');

$db = getDB();

// Verify order exists and reporter is involved
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) respondError('Order not found', 404);

if ($auth['role'] === 'user'   && (int)$order['user_id']   !== (int)$auth['id']) respondError('Forbidden', 403);
if ($auth['role'] === 'runner' && (int)$order['runner_id'] !== (int)$auth['id']) respondError('Forbidden', 403);

$db->prepare("
    INSERT INTO reports (order_id, reporter_id, reporter_role, reason, details)
    VALUES (?, ?, ?, ?, ?)
")->execute([$order_id, $auth['id'], $auth['role'], $reason, $details ?: null]);

respond(['message' => 'Report submitted. Admin will review it shortly.'], 201);