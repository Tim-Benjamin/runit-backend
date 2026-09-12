<?php
// api/admin/stories.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

$admin = requireAuth(['admin']);
$db    = getDB();

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base     = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/stories/';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT *, CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END AS is_active
        FROM stories ORDER BY group_id ASC, sort_order ASC, created_at DESC
    ");
    $stmt->execute();
    $stories = $stmt->fetchAll();
    foreach ($stories as &$s) {
        if ($s['type'] === 'image' || $s['type'] === 'video') $s['url'] = $base . $s['content'];
        if ($s['thumbnail_path']) $s['thumbnail_url'] = $base . $s['thumbnail_path'];
    }
    unset($s);
    respond(['stories' => $stories]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type          = $_POST['type']           ?? '';
    $caption       = trim($_POST['caption']   ?? '');
    $bg_color      = $_POST['bg_color']       ?? '#0a1f1c';
    $text_color    = $_POST['text_color']     ?? '#ffffff';
    $duration_days = intval($_POST['duration_days'] ?? 1);
    $text_content  = trim($_POST['text_content']    ?? '');
    $group_id      = trim($_POST['group_id']        ?? '');
    $group_title   = trim($_POST['group_title']     ?? '');
    $sort_order    = intval($_POST['sort_order']    ?? 0);

    if (!in_array($type, ['image','video','text'])) respondError('Invalid type');
    if ($duration_days < 1 || $duration_days > 7)  respondError('Duration must be 1–7 days');

    // Generate a new group_id if creating a new group
    if (!$group_id) $group_id = bin2hex(random_bytes(8));

    $uploadDir = __DIR__ . '/../../uploads/stories/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $content       = '';
    $thumbnail_path = null;

    if ($type === 'text') {
        if (!$text_content) respondError('Text content required');
        $content = $text_content;
    } else {
        $field   = $type === 'image' ? 'image' : 'video';
        $allowed = $type === 'image'
            ? ['image/jpeg','image/png','image/webp','image/gif']
            : ['video/mp4','video/webm','video/quicktime','video/x-m4v'];
        $maxSize = $type === 'image' ? 10 * 1024 * 1024 : 100 * 1024 * 1024;

        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) respondError('File required');
        $file = $_FILES[$field];
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) respondError('Invalid file type');
        if ($file['size'] > $maxSize)   respondError('File too large');

        $ext     = pathinfo($file['name'], PATHINFO_EXTENSION) ?: ($type === 'image' ? 'jpg' : 'mp4');
        $content = $type . '_' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $content)) respondError('Upload failed', 500);

        // Extract video thumbnail using ffmpeg if available
        if ($type === 'video') {
            $thumb_name = 'thumb_' . uniqid('', true) . '.jpg';
            $thumb_path = $uploadDir . $thumb_name;
            $video_path = $uploadDir . $content;
            $ffmpeg     = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');

            if ($ffmpeg) {
                $cmd = escapeshellcmd($ffmpeg)
                     . ' -i ' . escapeshellarg($video_path)
                     . ' -ss 00:00:01 -vframes 1 -q:v 2 '
                     . escapeshellarg($thumb_path)
                     . ' 2>/dev/null';
                shell_exec($cmd);
                if (file_exists($thumb_path) && filesize($thumb_path) > 0) {
                    $thumbnail_path = $thumb_name;
                }
            }

            // Fallback: if admin also uploaded a thumbnail image
            if (!$thumbnail_path && isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $tf   = $_FILES['thumbnail'];
                $tmime = mime_content_type($tf['tmp_name']);
                if (in_array($tmime, ['image/jpeg','image/png','image/webp'])) {
                    $tname = 'thumb_' . uniqid('', true) . '.jpg';
                    if (move_uploaded_file($tf['tmp_name'], $uploadDir . $tname)) {
                        $thumbnail_path = $tname;
                    }
                }
            }
        }
    }

    $expires_at = date('Y-m-d H:i:s', strtotime('+' . $duration_days . ' days'));

    $db->prepare("
        INSERT INTO stories
            (admin_id, type, content, caption, bg_color, text_color,
             duration_days, expires_at, group_id, group_title,
             sort_order, thumbnail_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $admin['id'], $type, $content,
        $caption ?: null, $bg_color, $text_color,
        $duration_days, $expires_at,
        $group_id, $group_title ?: null,
        $sort_order, $thumbnail_path
    ]);

    respond([
        'message'    => 'Slide added to story group',
        'group_id'   => $group_id,
        'expires_at' => $expires_at,
    ], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id']       ?? 0);
    $gid  = $body['group_id']        ?? '';
    $db->prepare("DELETE FROM stories WHERE " . ($id ? "id = ?" : "group_id = ?"))
       ->execute([$id ?: $gid]);
    respond(['message' => 'Deleted']);
}

respondError('Method not allowed', 405);