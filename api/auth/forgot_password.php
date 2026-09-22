<?php
require_once '../../config/cors.php';
require_once '../../config/db.php';
require_once '../../config/rate_limit.php';
require_once '../../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$body  = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($body['email'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) respondError('Valid email required');

$db = getDB();

// Rate limit: 3 reset requests per email per 15 minutes
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
checkRateLimit($db, 'reset_' . $email, 3, 900);
checkRateLimit($db, 'reset_ip_' . $ip, 10, 900);

// Find user in users or runners table
$role = null;
$name = null;

$stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();
if ($user) { $role = 'user'; $name = $user['name']; }

if (!$role) {
    $stmt = $db->prepare("SELECT id, name FROM runners WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $runner = $stmt->fetch();
    if ($runner) { $role = 'runner'; $name = $runner['name']; }
}

// Always respond success even if email not found (security)
if (!$role) {
    respond(['message' => 'If this email exists, a reset link has been sent.']);
}

// Generate token
$token      = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Delete old tokens for this email
$db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

// Store new token
$db->prepare("
    INSERT INTO password_resets (email, token, role, expires_at)
    VALUES (?, ?, ?, ?)
")->execute([$email, $token, $role, $expires_at]);

// Send email
$reset_url = 'https://runitgh.com/reset-password?token=' . $token;
$html = '
<!DOCTYPE html>
<html>
<body style="font-family:sans-serif;background:#0a1f1c;margin:0;padding:40px 20px;">
  <div style="max-width:480px;margin:0 auto;background:#132d28;border-radius:20px;padding:36px;border:1px solid rgba(0,201,167,0.2);">
    <div style="text-align:center;margin-bottom:28px;">
      <div style="display:inline-block;background:#00c9a7;color:#0a1f1c;font-weight:800;font-size:22px;width:44px;height:44px;line-height:44px;border-radius:12px;">R</div>
      <div style="color:#fff;font-weight:800;font-size:20px;margin-top:10px;">RunIt</div>
    </div>
    <h2 style="color:#fff;font-size:20px;margin-bottom:8px;">Reset your password</h2>
    <p style="color:#9ab;font-size:14px;line-height:1.6;margin-bottom:28px;">
      Hi ' . htmlspecialchars($name) . ', we received a request to reset your RunIt password.
      Click the button below — this link expires in <strong style="color:#fff;">1 hour</strong>.
    </p>
    <a href="' . $reset_url . '" style="display:block;text-align:center;background:#00c9a7;color:#0a1f1c;font-weight:700;font-size:15px;padding:14px;border-radius:50px;text-decoration:none;margin-bottom:20px;">
      Reset Password
    </a>
    <p style="color:#9ab;font-size:12px;text-align:center;">
      If you did not request this, ignore this email. Your password will not change.
    </p>
    <p style="color:#9ab;font-size:11px;text-align:center;margin-top:20px;word-break:break-all;">
      ' . $reset_url . '
    </p>
  </div>
</body>
</html>';

sendMail($email, $name, 'Reset your RunIt password', $html);

respond(['message' => 'If this email exists, a reset link has been sent.']);