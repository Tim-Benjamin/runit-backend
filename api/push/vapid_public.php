<?php
// api/push/vapid_public.php
// No auth needed — public key is safe to expose
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../config/vapid.php';
echo json_encode(['publicKey' => VAPID_PUBLIC_KEY]);