<?php
// api/admin/shops.php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/auth.php';

requireAuth(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/runit-backend/uploads/shops/';

    $stmt = $db->prepare("SELECT *, (view_count + boost_views) AS total_views FROM shops ORDER BY total_views DESC, name ASC");
    $stmt->execute();
    $shops = $stmt->fetchAll();

    foreach ($shops as &$shop) {
        $shop['image_url'] = $shop['image_path'] ? $base_url . $shop['image_path'] : null;
    }

    respond(['shops' => $shops]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle multipart form (image upload)
    $name     = trim($_POST['name']                 ?? '');
    $category = $_POST['category']                  ?? '';
    $location = trim($_POST['location_description'] ?? '');
    $phone    = trim($_POST['phone']                ?? '');

    if (!$name || !$category || !$location || !$phone) respondError('All fields are required');

    $valid = ['Food','Groceries','Printing','Pharmacy','Other'];
    if (!in_array($category, $valid)) respondError('Invalid category');

    $image_path = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['image'];
        $allowed = ['image/jpeg','image/png','image/webp'];
        $mime    = mime_content_type($file['tmp_name']);

        if (!in_array($mime, $allowed)) respondError('Image must be JPG, PNG or WebP');
        if ($file['size'] > 3 * 1024 * 1024) respondError('Image must be under 3MB');

        $ext        = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename   = 'shop_' . uniqid() . '.' . $ext;
        $dest       = __DIR__ . '/../../uploads/shops/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) respondError('Failed to save image', 500);
        $image_path = $filename;
    }

    $db->prepare("
        INSERT INTO shops (name, category, location_description, phone, image_path)
        VALUES (?,?,?,?,?)
    ")->execute([$name, $category, $location, $phone, $image_path]);

    respond(['message' => 'Shop added successfully'], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id']             ?? 0);

    if (!$id) respondError('Shop ID required');

    // Boost views
    if (isset($body['boost'])) {
        $boost = intval($body['boost']);
        if ($boost < 0 || $boost > 100000) respondError('Invalid boost value');
        $db->prepare("UPDATE shops SET boost_views = ? WHERE id = ?")
           ->execute([$boost, $id]);
        respond(['message' => 'Boost views updated']);
    }

    // Toggle status
    if (isset($body['status'])) {
        $status = $body['status'];
        if (!in_array($status, ['active','inactive'])) respondError('Invalid status');
        $db->prepare("UPDATE shops SET status = ? WHERE id = ?")
           ->execute([$status, $id]);
        respond(['message' => 'Shop updated']);
    }

    respondError('Nothing to update');
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = intval($body['id'] ?? 0);
    if (!$id) respondError('Shop ID required');

    // Delete image file if exists
    $s = $db->prepare("SELECT image_path FROM shops WHERE id = ? LIMIT 1");
    $s->execute([$id]);
    $shop = $s->fetch();
    if ($shop && $shop['image_path']) {
        $file = __DIR__ . '/../../uploads/shops/' . $shop['image_path'];
        if (file_exists($file)) unlink($file);
    }

    $db->prepare("DELETE FROM shops WHERE id = ?")->execute([$id]);
    respond(['message' => 'Shop deleted']);
}

respondError('Method not allowed', 405);