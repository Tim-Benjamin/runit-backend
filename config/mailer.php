<?php
// config/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

function sendMail($to_email, $to_name, $subject, $html_body) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'minawood4321@gmail.com'; // ← change this
        $mail->Password   = 'inxrifuxdmdkglww';    // ← change this (Gmail App Password)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('minawood4321@gmail.com', 'RunIt');
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

function emailOrderPlaced($user_email, $user_name, $order) {
    $subject = 'Your RunIt order was received 📦';
    $fee     = number_format($order['proposed_fee'], 2);
    $html    = "
    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;background:#0a1f1c;color:#e8f5f3;padding:32px;border-radius:16px'>
      <div style='font-size:28px;font-weight:800;color:#00c9a7;margin-bottom:8px'>RunIt</div>
      <h2 style='margin:0 0 16px'>Order Received ✅</h2>
      <p>Hi {$user_name}, your order has been placed and runners are being notified.</p>
      <div style='background:#0f2e29;border-radius:12px;padding:16px;margin:20px 0'>
        <div style='font-size:12px;color:#7a9e99;margin-bottom:6px'>What you ordered</div>
        <div style='font-size:15px;margin-bottom:12px'>{$order['description']}</div>
        <div style='font-size:12px;color:#7a9e99'>Category: {$order['category']}</div>
        <div style='font-size:12px;color:#7a9e99'>Delivery fee: GH₵ {$fee}</div>
      </div>
      <p style='color:#7a9e99;font-size:13px'>A runner will accept your order shortly. You'll receive another email when they're on their way.</p>
      <p style='color:#7a9e99;font-size:13px'>💵 Remember: pay your runner in cash on delivery.</p>
    </div>";

    return sendMail($user_email, $user_name, $subject, $html);
}

function emailRunnerAccepted($user_email, $user_name, $order, $runner) {
    $subject = 'A runner is on the way! 🏃';
    $fee     = number_format($order['final_fee'] ?? $order['proposed_fee'], 2);
    $html    = "
    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;background:#0a1f1c;color:#e8f5f3;padding:32px;border-radius:16px'>
      <div style='font-size:28px;font-weight:800;color:#00c9a7;margin-bottom:8px'>RunIt</div>
      <h2 style='margin:0 0 16px'>Runner Assigned 🏃</h2>
      <p>Hi {$user_name}, a runner has accepted your order and is heading your way!</p>
      <div style='background:#0f2e29;border-radius:12px;padding:16px;margin:20px 0'>
        <div style='font-size:12px;color:#7a9e99;margin-bottom:6px'>Your runner</div>
        <div style='font-size:18px;font-weight:700;margin-bottom:4px'>{$runner['name']}</div>
        <div style='font-size:14px;color:#00c9a7'>📞 {$runner['phone']}</div>
      </div>
      <div style='background:#0f2e29;border-radius:12px;padding:16px;margin:20px 0'>
        <div style='font-size:12px;color:#7a9e99'>Your order: {$order['description']}</div>
        <div style='font-size:12px;color:#7a9e99;margin-top:4px'>Amount to pay: GH₵ {$fee} cash</div>
      </div>
      <p style='color:#7a9e99;font-size:13px'>You can call your runner directly using the number above.</p>
    </div>";

    return sendMail($user_email, $user_name, $subject, $html);
}

function emailOrderDelivered($user_email, $user_name, $order) {
    $subject = 'Order delivered! ✅';
    $fee     = number_format($order['final_fee'] ?? $order['proposed_fee'], 2);
    $html    = "
    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;background:#0a1f1c;color:#e8f5f3;padding:32px;border-radius:16px'>
      <div style='font-size:28px;font-weight:800;color:#00c9a7;margin-bottom:8px'>RunIt</div>
      <h2 style='margin:0 0 16px'>Order Delivered 🎉</h2>
      <p>Hi {$user_name}, your order has been delivered. We hope everything went smoothly!</p>
      <div style='background:#0f2e29;border-radius:12px;padding:16px;margin:20px 0'>
        <div style='font-size:12px;color:#7a9e99;margin-bottom:6px'>Order summary</div>
        <div style='font-size:14px;margin-bottom:8px'>{$order['description']}</div>
        <div style='font-size:13px;color:#00c9a7;font-weight:700'>GH₵ {$fee} paid</div>
      </div>
      <p style='color:#7a9e99;font-size:13px'>Thank you for using RunIt. Place another order anytime!</p>
    </div>";

    return sendMail($user_email, $user_name, $subject, $html);
}