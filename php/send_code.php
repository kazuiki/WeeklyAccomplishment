<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function respond($ok, $message) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

// basic request check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['ajax']) || $_POST['ajax'] !== 'send_code') {
    respond(false, 'Invalid request');
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';

if ($email === '') {
    respond(false, 'Email address is required');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Invalid email format');
}

// verify email exists in the system and check lock status
$stmt = $conn->prepare("SELECT user_id, username, is_locked FROM users WHERE email = ?");
if (!$stmt) respond(false, 'Database error');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    respond(false, 'Email address not found in our system');
}

$stmt->bind_result($user_id, $username, $is_locked);
$stmt->fetch();
$stmt->close();

// Check if account is admin-locked
if ($is_locked == 2) {
    // Send admin-locked notification email instead of verification code
    $mail = new PHPMailer(true);
    
    try {
        // Gmail SMTP config
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'arcas.johnpritch.l@gmail.com';
        $mail->Password   = 'jayn zmfl lekz dfwy';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender & recipient
        $mail->setFrom('arcas.johnpritch.l@gmail.com', 'System Administrator');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Account Access Restricted - Password Reset Unavailable';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <h2 style='color: #dc3545; margin: 0;'>🔒 Account Access Restricted</h2>
                </div>
                
                <p>Hello <strong>{$username}</strong>,</p>
                
                <p>We received a request to reset the password for your account. However, your account has been temporarily restricted by an administrator and password reset is currently unavailable.</p>
                
                <div style='background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                    <strong>⚠️ Account Status:</strong> Administratively Locked<br>
                    <strong>📧 Action Required:</strong> Contact system administrator
                </div>
                
                <p><strong>What this means:</strong></p>
                <ul>
                    <li>Your account access has been temporarily suspended</li>
                    <li>Password reset functionality is disabled for your account</li>
                    <li>You cannot log in until the restriction is lifted</li>
                </ul>
                
                <p><strong>Next steps:</strong></p>
                <ol>
                    <li>Contact your system administrator or IT support team</li>
                    <li>Provide your username: <code>{$username}</code></li>
                    <li>Request account access restoration</li>
                </ol>
                
                <div style='background-color: #e2e3e5; border-left: 4px solid #6c757d; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>Need Help?</strong></p>
                    <p style='margin: 5px 0 0 0;'>If you believe this restriction was applied in error, please contact your administrator immediately with your account details.</p>
                </div>
                
                <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
                
                <p style='font-size: 12px; color: #6c757d; text-align: center;'>
                    This is an automated message. Please do not reply to this email.<br>
                    If you did not request a password reset, you can safely ignore this message.
                </p>
            </div>
        ";
        
        $mail->AltBody = "Hello {$username},\n\nWe received a request to reset your password. However, your account has been temporarily restricted by an administrator.\n\nAccount Status: Administratively Locked\nAction Required: Contact system administrator\n\nWhat this means:\n- Your account access has been temporarily suspended\n- Password reset functionality is disabled\n- You cannot log in until the restriction is lifted\n\nNext steps:\n1. Contact your system administrator\n2. Provide your username: {$username}\n3. Request account access restoration\n\nIf you believe this restriction was applied in error, please contact your administrator immediately.";

        $mail->send();
        respond(true, "Account restriction notice sent to {$email}. Please contact your administrator for assistance.");
        
    } catch (Exception $e) {
        respond(false, 'Failed to send notification. Your account is locked by an administrator - please contact support.');
    }
}

// Rate limiting check
if (!isset($_SESSION['last_code_time'])) $_SESSION['last_code_time'] = 0;
if (time() - $_SESSION['last_code_time'] < 20) {
    respond(false, 'Please wait before requesting another code');
}

// Generate code & store in session
$code = random_int(100000, 999999);
$_SESSION['verification_code'] = (string)$code;
$_SESSION['verification_expiry'] = time() + 180;
$_SESSION['last_code_time'] = time();

// Store username for password reset process
$_SESSION['reset_username'] = $username;

$mail = new PHPMailer(true);

try {
    // Gmail SMTP config
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'arcas.johnpritch.l@gmail.com';
    $mail->Password   = 'jayn zmfl lekz dfwy';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Sender & recipient
    $mail->setFrom('arcas.johnpritch.l@gmail.com', 'System');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Password Reset Verification Code';
    $mail->Body    = "
        <p>Hello <strong>{$username}</strong>,</p>
        <p>Your verification code is: <strong style='font-size:18px'>{$code}</strong></p>
        <p>This code is valid for 3 minutes.</p>
        <p>If you did not request this, ignore this message.</p>
    ";
    $mail->AltBody = "Hello {$username},\n\nYour verification code is: {$code}\nValid for 3 minutes.";

    $mail->send();
    respond(true, "Verification code sent to {$email}");
    
} catch (Exception $e) {
    unset($_SESSION['verification_code'], $_SESSION['verification_expiry']);
    respond(false, 'Failed to send verification code. Please try again.');
}
