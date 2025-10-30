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

// verify email exists in the system
$stmt = $conn->prepare("SELECT user_id, username FROM users WHERE email = ?");
if (!$stmt) respond(false, 'Database error');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    respond(false, 'Email address not found in our system');
}

$stmt->bind_result($user_id, $username);
$stmt->fetch();
$stmt->close();

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
