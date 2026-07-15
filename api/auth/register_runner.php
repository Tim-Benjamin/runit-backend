<?php
// api/auth/register_runner.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

// This endpoint receives multipart/form-data (because of file upload)
$name           = trim($_POST['name'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$momo           = trim($_POST['momoNumber'] ?? '');
$method         = $_POST['deliveryMethod'] ?? 'foot';
$pass           = $_POST['password'] ?? '';

if (!$name || !$email || !$phone || !$momo || !$pass) {
    respondError('All fields are required');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Invalid email address');
}

if (strlen($pass) < 6) {
    respondError('Password must be at least 6 characters');
}

if (!in_array($method, ['foot', 'bike', 'motorbike'])) {
    respondError('Invalid delivery method');
}

// Handle file upload
if (!isset($_FILES['id_document']) || $_FILES['id_document']['error'] !== UPLOAD_ERR_OK) {
    respondError('ID document upload is required');
}

$file     = $_FILES['id_document'];
$allowed  = ['image/jpeg', 'image/png', 'application/pdf'];
$mime     = mime_content_type($file['tmp_name']);

if (!in_array($mime, $allowed)) {
    respondError('ID must be a JPG, PNG or PDF file');
}

if ($file['size'] > 5 * 1024 * 1024) {
    respondError('File size must be under 5MB');
}

$ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid('id_', true) . '.' . $ext;
$dest     = __DIR__ . '/../../uploads/id_documents/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    respondError('Failed to save ID document', 500);
}

$db = getDB();

// Check email uniqueness across runners and users
$stmt = $db->prepare("SELECT id FROM runners WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    respondError('An account with this email already exists');
}

$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    respondError('An account with this email already exists');
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$stmt = $db->prepare(
    "INSERT INTO runners (name, email, password_hash, phone, momo_number, id_document, delivery_method)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->execute([$name, $email, $hash, $phone, $momo, $filename, $method]);

respond([
    'message' => 'Application submitted. An admin will review and approve your account shortly.',
], 201);

require_once '../../config/push.php';
sendPushToRole($db, 'admin',
    '🏃 New runner application',
    $name . ' applied to become a runner. Review and approve.',
    ['url' => '/admin/runners']
);