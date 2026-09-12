<?php
// api/admin/ads.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base     = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/ads/';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT * FROM launch_ads ORDER BY created_at DESC");
    $stmt->execute();
    $ads = $stmt->fetchAll();
    foreach ($ads as &$a) { if ($a['type'] === 'image') $a['url'] = $base . $a['content']; }
    unset($a);
    respond(['ads' => $ads]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type      = $_POST['type']      ?? '';
    $caption   = trim($_POST['caption']   ?? '');
    $cta_text  = trim($_POST['cta_text']  ?? '');
    $cta_url   = trim($_POST['cta_url']   ?? '');
    $bg_color  = $_POST['bg_color']  ?? '#111111';
    $txt_color = $_POST['text_color'] ?? '#ffffff';
    $txt_content = trim($_POST['text_content'] ?? '');

    if (!in_array($type, ['image','text'])) respondError('Invalid type');

    $uploadDir = __DIR__ . '/../../uploads/ads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $content = '';

    if ($type === 'text') {
        if (!$txt_content) respondError('Text content required');
        $content = $txt_content;
    } else {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) respondError('Image required');
        $file    = $_FILES['image'];
        $mime    = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($mime, $allowed)) respondError('Invalid image type');
        if ($file['size'] > 10 * 1024 * 1024) respondError('Image too large (max 10MB)');
        $ext     = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $content = 'ad_' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $content)) respondError('Upload failed', 500);
    }

    // Deactivate previous ads
    $db->prepare("UPDATE launch_ads SET is_active = 0")->execute();

    $db->prepare("
        INSERT INTO launch_ads (type, content, caption, cta_text, cta_url, bg_color, text_color, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ")->execute([$type, $content, $caption ?: null, $cta_text ?: null, $cta_url ?: null, $bg_color, $txt_color]);

    respond(['message' => 'Ad saved and activated'], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id'] ?? 0);
    if (!$id) respondError('ID required');
    $stmt = $db->prepare("SELECT content, type FROM launch_ads WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if ($a && $a['type'] === 'image') {
        $p = __DIR__ . '/../../uploads/ads/' . $a['content'];
        if (file_exists($p)) unlink($p);
    }
    $db->prepare("DELETE FROM launch_ads WHERE id = ?")->execute([$id]);
    respond(['message' => 'Ad deleted']);
}

respondError('Method not allowed', 405);