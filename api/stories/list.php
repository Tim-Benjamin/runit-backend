<?php
// api/stories/list.php
require_once '../../config/cors.php';
require_once '../../config/db.php';

$db = getDB();

// Auto-delete expired
$db->prepare("DELETE FROM stories WHERE expires_at < NOW()")->execute();

$stmt = $db->prepare("
    SELECT id, type, content, caption, bg_color, text_color,
           thumbnail_path, group_id, group_title, sort_order,
           created_at, expires_at
    FROM stories
    WHERE expires_at > NOW()
    ORDER BY group_id ASC, sort_order ASC, created_at ASC
");
$stmt->execute();

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base     = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/stories/';

$rows = $stmt->fetchAll();

// Group them
$groups = [];
foreach ($rows as $s) {
    if ($s['type'] === 'image' || $s['type'] === 'video') {
        $s['url'] = $base . $s['content'];
    }
    if ($s['thumbnail_path']) {
        $s['thumbnail_url'] = $base . $s['thumbnail_path'];
    }

    $gid = $s['group_id'] ?: ('solo_' . $s['id']);

    if (!isset($groups[$gid])) {
        $groups[$gid] = [
            'group_id'    => $gid,
            'group_title' => $s['group_title'] ?: ($s['caption'] ?: 'Story'),
            'cover'       => $s,   // first slide is cover
            'slides'      => [],
        ];
    }
    $groups[$gid]['slides'][] = $s;
}

respond(['groups' => array_values($groups)]);