<?php
// api/auth/register_runner.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$name   = trim($_POST['name']           ?? '');
$email  = trim($_POST['email']          ?? '');
$phone  = trim($_POST['phone']          ?? '');
$momo   = trim($_POST['momoNumber']     ?? '');
$method = $_POST['deliveryMethod']      ?? 'foot';
$pass   = $_POST['password']            ?? '';

if (!$name || !$email || !$phone || !$momo || !$pass) respondError('All fields are required');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))        respondError('Invalid email address');
if (strlen($pass) < 6)                                 respondError('Password must be at least 6 characters');
if (!in_array($method, ['foot','bike','motorbike']))   respondError('Invalid delivery method');

// ── ID document upload ──────────────────────────────────────────────────────
$id_field = null;
foreach (['id_file', 'id_document'] as $field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $id_field = $field;
        break;
    }
}

if (!$id_field) respondError('Ghana Card or Student ID upload is required');

$file    = $_FILES[$id_field];
$allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
$mime    = mime_content_type($file['tmp_name']);

if (!in_array($mime, $allowed)) respondError('ID must be a JPG, PNG, WebP or PDF file');
if ($file['size'] > 5 * 1024 * 1024) respondError('ID file must be under 5MB');

$ext          = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename     = 'id_' . uniqid('', true) . '.' . $ext;
$uploadDir    = __DIR__ . '/../../uploads/runner_ids/';

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
    respondError('Failed to save ID file. Check uploads/runner_ids/ folder permissions.', 500);
}

// ── Database ────────────────────────────────────────────────────────────────
$db = getDB();

// Check email uniqueness across runners and users
$stmt = $db->prepare("SELECT id FROM runners WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    unlink($uploadDir . $filename);
    respondError('An account with this email already exists');
}

$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    unlink($uploadDir . $filename);
    respondError('An account with this email already exists');
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$db->prepare("
    INSERT INTO runners
        (name, email, password_hash, phone, momo_number, id_document, id_file_path, delivery_method)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
")->execute([$name, $email, $hash, $phone, $momo, $filename, $filename, $method]);

$runner_id = (int)$db->lastInsertId();

// ── Notify admin ─────────────────────────────────────────────────────────────
try {
    require_once '../../config/push.php';
    sendPushToRole($db, 'admin',
        '🏃 New runner application',
        $name . ' applied to become a runner. Review and approve.',
        ['url' => '/admin/runners']
    );
} catch (Exception $e) { /* push not critical */ }

respond([
    'message'   => 'Application submitted. Admin will review and approve your account shortly.',
    'runner_id' => $runner_id,
], 201);