<?php
session_start();

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("Location: homepage.php");
    exit();
}

$error = "";
$success = "";
$changePasswordError = "";

// Store old values to retain in case of errors
$old = [
    'username' => '',
    'signup_username' => '',
    'signup_password' => '',
    'signup_email' => '',
    'change_email' => '',
];

$conn = new mysqli("localhost", "root", "", "weeklyreport");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// AJAX: verify OTP code without submitting the whole form
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"]) && $_POST["ajax"] === "verify_code") {
    header('Content-Type: application/json');
    $change_username = isset($_POST['username']) ? $_POST['username'] : '';
    $change_email = isset($_POST['email']) ? $_POST['email'] : '';
    $verification_code = isset($_POST['code']) ? $_POST['code'] : '';

    $response = [ 'ok' => false, 'message' => 'Invalid request' ];

    if ($change_username !== '' && $change_email !== '' && $verification_code !== '') {
        $stmt = $conn->prepare("SELECT user_id, email FROM users WHERE username = ?");
        $stmt->bind_param("s", $change_username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($user_id, $db_email);
            $stmt->fetch();
            if ($change_email !== $db_email) {
                $response = [ 'ok' => false, 'message' => 'Email does not match our records!' ];
            } elseif (!isset($_SESSION["verification_code"]) || !isset($_SESSION["verification_expiry"]) || time() > $_SESSION["verification_expiry"]) {
                $response = [ 'ok' => false, 'message' => 'Invalid or expired verification code!' ];
            } elseif ($verification_code != $_SESSION["verification_code"]) {
                $response = [ 'ok' => false, 'message' => 'Invalid verification code!' ];
            } else {
                $response = [ 'ok' => true, 'message' => '' ];
            }
        } else {
            $response = [ 'ok' => false, 'message' => 'Username not found!' ];
        }
        $stmt->close();
    } else {
        $response = [ 'ok' => false, 'message' => 'Please provide username, email and code.' ];
    }

    echo json_encode($response);
    exit();
}

// AJAX: login without page reload
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"]) && $_POST["ajax"] === "login") {
    header('Content-Type: application/json');
    $username = isset($_POST["username"]) ? trim($_POST["username"]) : '';
    $password = isset($_POST["password"]) ? $_POST["password"] : '';
    $ip = $_SERVER['REMOTE_ADDR'];

    $response = [ 'ok' => false, 'message' => 'Invalid request' ];

    if ($username !== '' && $password !== '') {
        // First get the user information
        $stmt = $conn->prepare("SELECT user_id, password, is_locked FROM users WHERE BINARY username = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $response = [ 'ok' => false, 'message' => 'Database error' ];
            echo json_encode($response);
            exit();
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows === 0) {
            // Log attempt for non-existent user
            $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (NULL, ?, 'USER_NOT_FOUND')");
            if ($log) {
                $log->bind_param("s", $ip);
                $log->execute();
                $log->close();
            }
            $response = [ 'ok' => false, 'message' => 'Invalid username or password!' ];
        } else {
            $stmt->bind_result($user_id, $db_password, $is_locked);
            $stmt->fetch();

            if ($is_locked) {
                // Log locked account attempt
                $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'ACCOUNT_LOCKED')");
                if ($log) {
                    $log->bind_param("is", $user_id, $ip);
                    $log->execute();
                    $log->close();
                }
                $response = [ 'ok' => false, 'message' => 'Your account has been locked due to multiple failed attempts.' ];
            } elseif (password_verify($password, $db_password)) {
                // Log successful login
                $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'SUCCESS')");
                if ($log) {
                    $log->bind_param("is", $user_id, $ip);
                    $log->execute();
                    $log->close();
                }

                // Generate new session token
                $token = bin2hex(random_bytes(32));
                
                // Check if user already has a session record
                $checkSession = $conn->prepare("SELECT sessions_id FROM sessions WHERE users_user_id = ? LIMIT 1");
                $checkSession->bind_param("i", $user_id);
                $checkSession->execute();
                $checkSession->store_result();
                
                if ($checkSession->num_rows > 0) {
                    // Update existing session
                    $update = $conn->prepare("UPDATE sessions SET token = ?, is_active = 1 WHERE users_user_id = ?");
                    $update->bind_param("si", $token, $user_id);
                    $update->execute();
                    $update->close();
                } else {
                    // Create new session record
                    $insert = $conn->prepare("INSERT INTO sessions (users_user_id, token, is_active) VALUES (?, ?, 1)");
                    $insert->bind_param("is", $user_id, $token);
                    $insert->execute();
                    $insert->close();
                }
                
                $checkSession->close();

                $_SESSION["loggedin"] = true;
                $_SESSION["username"] = $username;
                $_SESSION["user_id"] = $user_id;
                $_SESSION["token"] = $token;

                $response = [ 'ok' => true, 'message' => 'Login successful' ];
            } else {
                // Check for previous failed attempts before logging this one
                $countStmt = $conn->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT status 
                        FROM login_attempts 
                        WHERE users_user_id = ? 
                        ORDER BY attempt_time DESC 
                        LIMIT 2
                    ) AS last_attempts 
                    WHERE status = 'INVALID_PASSWORD'
                ");
                
                if ($countStmt) {
                    $countStmt->bind_param("i", $user_id);
                    $countStmt->execute();
                    $countStmt->bind_result($failedCount);
                    $countStmt->fetch();
                    $countStmt->close();

                    // If this will be the third attempt, log it as ACCOUNT_LOCKED
                    if ($failedCount >= 2) {
                        $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'ACCOUNT_LOCKED')");
                        if ($log) {
                            $log->bind_param("is", $user_id, $ip);
                            $log->execute();
                            $log->close();
                        }

                        // Lock the account
                        $lock = $conn->prepare("UPDATE users SET is_locked = 1 WHERE user_id = ?");
                        if ($lock) {
                            $lock->bind_param("i", $user_id);
                            $lock->execute();
                            $lock->close();
                        }
                        
                        $response = [ 'ok' => false, 'message' => 'Your account has been locked due to multiple failed attempts.' ];
                    } else {
                        // Log as normal invalid password attempt
                        $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'INVALID_PASSWORD')");
                        if ($log) {
                            $log->bind_param("is", $user_id, $ip);
                            $log->execute();
                            $log->close();
                        }
                        
                        $response = [ 'ok' => false, 'message' => 'Invalid username or password!' ];
                    }
                }
            }
        }
        $stmt->close();
    }

    echo json_encode($response);
    exit();
}

// AJAX: signup
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"]) && $_POST["ajax"] === "signup") {
    header('Content-Type: application/json');
    $res = [ 'ok' => false, 'message' => '' ];

    $signup_username = $_POST["signup_username"] ?? '';
    $signup_password = $_POST["signup_password"] ?? '';
    $signup_email    = $_POST["signup_email"] ?? '';
    $signup_confirm_password = $_POST["signup_confirm_password"] ?? '';

    if ($signup_username === '' || $signup_password === '' || $signup_email === '' || $signup_confirm_password === '') {
        echo json_encode([ 'ok' => false, 'message' => 'Missing required fields.' ]); exit();
    }

    if ($signup_password !== $signup_confirm_password) {
        echo json_encode([ 'ok' => false, 'message' => 'Passwords do not match.' ]); exit();
    }

    // Check for existing username ONLY (emails may be duplicated per requirements)
    $exists = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $exists->bind_param("s", $signup_username);
    $exists->execute();
    $exists->store_result();
    if ($exists->num_rows > 0) {
        echo json_encode([ 'ok' => false, 'message' => 'Username already exists.' ]); exit();
    }
    $exists->close();

    $hashed_password = password_hash($signup_password, PASSWORD_DEFAULT);
    // mark new accounts with is_new = 1
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, is_new) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("sss", $signup_username, $hashed_password, $signup_email);
    if ($stmt->execute()) {
        echo json_encode([ 'ok' => true, 'message' => 'Signup successful!' ]);
    } else {
        $msg = strip_tags($stmt->error);
        if (strpos(strtolower($msg), 'duplicate') !== false) {
            // Database likely enforces unique email; app requirement allows duplicate emails
            $msg = 'Username already exists (or database enforces unique email).';
        }
        echo json_encode([ 'ok' => false, 'message' => $msg !== '' ? $msg : 'Error creating user.' ]);
    }
    exit();
}

// AJAX: change password
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"]) && $_POST["ajax"] === "change_password") {
    header('Content-Type: application/json');
    $change_username   = $_POST["change_username"] ?? '';
    $change_email      = $_POST["change_email"] ?? '';
    $verification_code = $_POST["verification_code"] ?? '';
    $new_password      = $_POST["new_password"] ?? '';
    $confirm_password  = $_POST["confirm_password"] ?? '';

    $stmt = $conn->prepare("SELECT user_id, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $change_username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        echo json_encode([ 'ok' => false, 'message' => 'Username not found!' ]); exit();
    }
    $stmt->bind_result($user_id, $db_email);
    $stmt->fetch();

    if ($change_email !== $db_email) {
        echo json_encode([ 'ok' => false, 'message' => 'Email does not match our records!' ]); exit();
    }
    if (!isset($_SESSION["verification_code"]) || !isset($_SESSION["verification_expiry"]) || time() > $_SESSION["verification_expiry"] || $verification_code != $_SESSION["verification_code"]) {
        echo json_encode([ 'ok' => false, 'message' => 'Invalid or expired verification code!' ]); exit();
    }
    if ($new_password !== $confirm_password) {
        echo json_encode([ 'ok' => false, 'message' => 'Passwords do not match!' ]); exit();
    }

    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password = ?, is_locked = 0 WHERE user_id = ?");
    $update->bind_param("si", $hashed_new_password, $user_id);
    if ($update->execute()) {
        unset($_SESSION["verification_code"]);
        unset($_SESSION["verification_expiry"]);
        echo json_encode([ 'ok' => true, 'message' => 'Password successfully changed!' ]);
    } else {
        echo json_encode([ 'ok' => false, 'message' => 'Error updating password: ' . $update->error ]);
    }
    $update->close();
    $stmt->close();
    exit();
}


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // LOGIN
    if (isset($_POST["username"]) && isset($_POST["password"]) &&
        !isset($_POST["signup_username"]) && !isset($_POST["change_username"])) {
       
        $username = $_POST["username"];
        $password = $_POST["password"];
        $old['username'] = $username;

        $ip = $_SERVER['REMOTE_ADDR'];

        $stmt = $conn->prepare("SELECT user_id, password, is_locked FROM users WHERE BINARY username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($user_id, $db_password, $is_locked);
            $stmt->fetch();

            if ($is_locked) {
                $error = "Your account has been locked due to multiple failed attempts.";

                $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'ACCOUNT_LOCKED')");
                $log->bind_param("is", $user_id, $ip);
                $log->execute();
                $log->close();
            } elseif (password_verify($password, $db_password)) {
                $_SESSION["loggedin"] = true;
                $_SESSION["username"] = $username;
                $_SESSION["user_id"] = $user_id;

                $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'SUCCESS')");
                $log->bind_param("is", $user_id, $ip);
                $log->execute();
                $log->close();

                // Generate new session token
                $token = bin2hex(random_bytes(32));
                $_SESSION["token"] = $token;

                // Check if user already has a session record
                $checkSession = $conn->prepare("SELECT sessions_id FROM sessions WHERE users_user_id = ? LIMIT 1");
                $checkSession->bind_param("i", $user_id);
                $checkSession->execute();
                $checkSession->store_result();
                
                if ($checkSession->num_rows > 0) {
                    // Update existing session
                    $update = $conn->prepare("UPDATE sessions SET token = ?, is_active = 1 WHERE users_user_id = ?");
                    $update->bind_param("si", $token, $user_id);
                    $update->execute();
                    $update->close();
                } else {
                    // Create new session record
                    $insert = $conn->prepare("INSERT INTO sessions (users_user_id, token, is_active) VALUES (?, ?, 1)");
                    $insert->bind_param("is", $user_id, $token);
                    $insert->execute();
                    $insert->close();
                }
                
                $checkSession->close();

                header("Location: homepage.php");
                exit();
            } else {
                $error = "Invalid username or password!";

                $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (?, ?, 'INVALID_PASSWORD')");
                $log->bind_param("is", $user_id, $ip);
                $log->execute();
                $log->close();

                // check last 3 attempts
                $countStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM (
                        SELECT status FROM login_attempts
                        WHERE users_user_id = ?
                        ORDER BY attempt_time DESC
                        LIMIT 3
                    ) AS last_attempts
                    WHERE status = 'INVALID_PASSWORD'
                ");
                $countStmt->bind_param("i", $user_id);
                $countStmt->execute();
                $countStmt->bind_result($failedCount);
                $countStmt->fetch();
                $countStmt->close();

                if ($failedCount >= 3) {
                    $lock = $conn->prepare("UPDATE users SET is_locked = 1 WHERE user_id = ?");
                    $lock->bind_param("i", $user_id);
                    $lock->execute();
                    $lock->close();

                    $error = "Your account has been locked";
                }
            }
        } else {
            $error = "Invalid username or password!";

            $log = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, status) VALUES (NULL, ?, 'USER_NOT_FOUND')");
            $log->bind_param("s", $ip);
            $log->execute();
            $log->close();
        }

        $stmt->close();
    }
    // SIGNUP
    elseif (isset($_POST["signup_username"])) {
        $signup_username = $_POST["signup_username"];
        $signup_password = $_POST["signup_password"];
        $signup_email    = $_POST["signup_email"];
        $signup_confirm_password = $_POST["signup_confirm_password"];

        // Retain values
        $old['signup_username'] = $signup_username;
        $old['signup_password'] = $signup_password;
        $old['signup_email'] = $signup_email;

        if ($signup_password !== $signup_confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $hashed_password = password_hash($signup_password, PASSWORD_DEFAULT);

            // mark new accounts with is_new = 1
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, is_new) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $signup_username, $hashed_password, $signup_email);
            if ($stmt->execute()) {
                $success = "Signup successful!";
                $old = array_fill_keys(array_keys($old), '');
            } else {
                $error = "Error creating user: " . $stmt->error;
            }
        }
    }
    // CHANGE PASSWORD
    elseif (isset($_POST["change_username"])) {
        $change_username = $_POST["change_username"];
        $change_email    = $_POST["change_email"];
        $verification_code = $_POST["verification_code"];
        $new_password    = $_POST["new_password"];
        $confirm_password = $_POST["confirm_password"];

        $old['username'] = $change_username;
        $old['change_email'] = $change_email;

        $stmt = $conn->prepare("SELECT user_id, email FROM users WHERE username = ?");
        $stmt->bind_param("s", $change_username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($user_id, $db_email);
            $stmt->fetch();

            if ($change_email !== $db_email) {
                $changePasswordError = "Email does not match our records!";
            } elseif (
                !isset($_SESSION["verification_code"]) ||
                !isset($_SESSION["verification_expiry"]) ||
                time() > $_SESSION["verification_expiry"] ||
                $verification_code != $_SESSION["verification_code"]
            ) {
                $changePasswordError = "Invalid or expired verification code!";
            } elseif ($new_password !== $confirm_password) {
                $changePasswordError = "Passwords do not match!";
            } else {
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update = $conn->prepare("UPDATE users SET password = ?, is_locked = 0 WHERE user_id = ?");
                $update->bind_param("si", $hashed_new_password, $user_id);
                if ($update->execute()) {
                    $success = "Password successfully changed!.";
                    unset($_SESSION["verification_code"]);
                    unset($_SESSION["verification_expiry"]);
                    $old['change_email'] = '';
                } else {
                    $changePasswordError = "Error updating password: " . $update->error;
                }
                $update->close();
            }
        } else {
            $changePasswordError = "Username not found!";
        }

        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Page</title>
<link rel="stylesheet" href="css/style.css">
<style>
/* Inline field validation messages: small, left-aligned, red */
.field-validation {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.2;
    color: #c62828; /* red tone */
    text-align: left;
    min-height: 0; /* don't force height when empty */
}
.field-validation:empty { display: none; }
/* Ensure input group keeps left alignment for validation */
.input-group.float { position: relative; }

/* Typewriter cursor animation */
.typewriter-cursor {
    display: inline-block;
    width: 3px;
    height: 65px;
    background-color: currentColor;
    margin-left: 2px;
    vertical-align: baseline;
    animation: blink 0.7s infinite;
}
@keyframes blink {
    0%, 49% { opacity: 1; }
    50%, 100% { opacity: 0; }
}
</style>
</head>
<body>
<div id="errorNotification" class="error-notification" aria-live="polite"></div>
<div class="auth-layout">
    <section class="hero">
        <div class="hero-content">
            <h1><span id="typewriter-line1"></span><br><span id="typewriter-line2"></span></h1>
            <p class="hero-sub">— your portal for tracking daily tasks, progress, and milestones throughout your training.</p>
            <div class="hero-logos">
                <img src="img/qcu.png" alt="QCU"/>
                <img src="img/it.png" alt="IT"/>
            </div>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-head">
                <h2>Welcome Back</h2>
                <p>Login to your account to proceed.</p>
                <br>
            </div>
            <form method="POST" action="Login.php" id="loginForm">
                <div class="input-group float">
                    <input type="text" id="username" name="username" placeholder=" " required value="<?= htmlspecialchars($old['username']) ?>">
                    <label for="username">Username</label>
                </div>
                <div class="input-group float">
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label for="password">Password</label>
                </div>
                <div class="form-row-small">
                    <a class="link forgot" href="javascript:void(0)" onclick="openChangePasswordModal()">Forgot password</a>
                </div>
                <?php if (!empty($error)) { ?>
                    <p class="form-msg error"><?= $error ?></p>
                <?php } ?>
                <?php if (!empty($success)) { ?>
                    <p class="form-msg success"><?= $success ?></p>
                <?php } ?>
                <button type="submit" class="btn primary" id="loginBtn">Login</button>
                <div class="signup-link" style="margin-top:12px;">
                    <span>No Account?</span>
                    <a href="javascript:void(0)" onclick="openSignupModal()">Sign up here</a>
                </div>
            </form>
        </div>
    </section>
</div>

<!-- Signup Modal -->
<div id="signupModal" class="modal" onclick="closeSignupModal()">
    <div class="modal-content recovery" onclick="event.stopPropagation()">
        <h2>Create your account</h2>
        <p class="modal-sub">Fill up login credential to create your account.</p>
        <form id="signupForm" method="POST" action="javascript:void(0)" autocomplete="off" novalidate>
            <div class="input-group float">
                <input type="text" id="signup-username" name="signup_username" placeholder=" " required value="<?= htmlspecialchars($old['signup_username']) ?>">
                <label for="signup-username">Username</label>
            </div>
            <div class="input-group float">
                <input type="email" id="signup-email" name="signup_email" placeholder=" " required value="<?= htmlspecialchars($old['signup_email']) ?>">
                <label for="signup-email">Email</label>
            </div>
            <div class="input-group float">
                <input type="password" id="signup-password" name="signup_password" placeholder=" " required>
                <label for="signup-password">Password</label>
                <div class="field-validation" id="signup-password-error"></div>
            </div>
            <div class="input-group float">
                <input type="password" id="signup-confirm-password" name="signup_confirm_password" placeholder=" " required>
                <label for="signup-confirm-password">Confirm Password</label>
                <div class="field-validation" id="signup-confirm-error"></div>
            </div>
            <button type="submit" class="btn primary" id="signupSubmit">Submit</button>
        </form>
        <div class="signup-link" style="margin-top: 10px; font-size: 12px; color:rgb(0, 0, 0); margin-left: 70px;">
            <span>Already have an account? </span>
            <a href="javascript:void(0)" onclick="closeSignupModal()" style="color: #6e7bff;">Sign in here.</a>
        </div>
    </div>
</div>

<!-- Signup Status: Success Modal -->
<div id="signupSuccessModal" class="modal">
    <div class="modal-content status">
        <div class="status-bar success"></div>
        <div class="status-icon success">✓</div>
        <h2 class="status-title">Successful</h2>
        <p class="status-sub">Account creation successful, You can logon to your account now</p>
        <div class="modal-buttons single">
            <button type="button" class="btn primary success" onclick="closeSignupSuccessModal(); window.location.href='Login.php';">Back to login screen</button>
        </div>
    </div>
</div>

<!-- Signup Status: Error Modal -->
<div id="signupErrorModal" class="modal">
    <div class="modal-content status">
        <div class="status-bar error"></div>
        <div class="status-icon error">✕</div>
        <h2 class="status-title">ERROR</h2>
        <p class="status-sub">oops.. Something went wrong, try again</p>
        <div class="modal-buttons single">
            <button type="button" class="btn primary error" onclick="backToSignupFromError();">Back</button>
        </div>
    </div>
</div>

<!-- Login Error Modal -->
<div id="loginErrorModal" class="modal">
    <div class="modal-content status">
        <div class="status-bar error"></div>
        <div class="status-icon error">✕</div>
        <h2 class="status-title">ERROR</h2>
        <p class="status-sub">Sorry user, can't let you in, please check your credentials</p>
        <div class="modal-buttons single">
            <button type="button" class="btn primary error" onclick="closeLoginErrorModal()">Try Again</button>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div id="changePasswordModal" class="modal">
    <div class="modal-content recovery">
        <br>
        <h2 align="left">Password Recovery</h2>
        <p class="modal-sub">Enter your email to get OTP.</p>
        <br>
        <form id="changePasswordForm" method="POST" action="Login.php">
            <input type="hidden" id="change-username" name="change_username" value="<?= htmlspecialchars($old['username']) ?>">
            <div class="input-group inline-action float">
                <div class="flex-1">
                    <input type="email" id="change-email" name="change_email" placeholder=" " required value="<?= htmlspecialchars($old['change_email']) ?>">
                    <label for="change-email">Email</label>
                </div>
                <button type="button" id="sendCodeBtn" class="btn small">Send Code</button>
            </div>
            <div class="input-group float">
                <input type="text" id="verification-code" name="verification_code" placeholder=" " required>
                <label for="verification-code">Verification Code</label>
                <div class="field-validation" id="verification-code-status"></div>
            </div>
            <!-- New Password fields always visible; server validates OTP on submit -->
            <div id="newPasswordFields">
                <div class="input-group float">
                    <input type="password" id="new-password" name="new_password" placeholder=" " disabled>
                    <label for="new-password">Password</label>
                    <div class="field-validation" id="recovery-password-error"></div>
                </div>
                <div class="input-group float">
                    <input type="password" id="confirm-password" name="confirm_password" placeholder=" " disabled>
                    <label for="confirm-password">Confirm Password</label>
                    <div class="field-validation" id="recovery-confirm-error"></div>
                </div>
            </div>
            <?php if (!empty($changePasswordError)) { ?>    
                <p style="color: red; margin-top: 10px;"><?= $changePasswordError ?></p>
            <?php } ?>
            <div class="modal-buttons row">
                <button type="submit" class="btn primary">Reset</button>
                <button type="button" class="btn outline" onclick="closeChangePasswordModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Add variable for modal state
let keepOverlayForStatus = false;

// Floating notification helper (error or success)
function showFloatingNotification(message, type = 'error') {
    // Reuse existing top-center notification element if available so success appears same as error
    const el = document.getElementById('errorNotification');
    if (el) {
        // apply temporary class without wiping existing classes
        if (type === 'success') {
            el.classList.add('success-notification');
            el.classList.remove('error-notification');
        } else {
            el.classList.add('error-notification');
            el.classList.remove('success-notification');
        }
        // set message
        el.textContent = message;
        // show
        el.classList.add('show');
        // auto hide and then restore classes
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => {
                // ensure error-notification is present as default
                el.classList.remove('success-notification');
                if (!el.classList.contains('error-notification')) el.classList.add('error-notification');
            }, 300);
        }, 3000);
        return;
    }

    // fallback: create an ephemeral floating element if #errorNotification doesn't exist
    const n = document.createElement('div');
    n.className = `floating-notification ${type}-notification`;
    const icon = document.createElement('div');
    icon.className = `notification-icon ${type}`;
    icon.setAttribute('aria-hidden', 'true');
    const txt = document.createElement('div');
    txt.className = 'notification-text';
    txt.textContent = message;
    n.appendChild(icon);
    n.appendChild(txt);
    document.body.appendChild(n);
    requestAnimationFrame(() => n.classList.add('show'));
    setTimeout(() => {
        n.classList.remove('show');
        setTimeout(() => n.remove(), 300);
    }, 3000);
}

// Smooth AJAX Login (prevents full page refresh)
document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.textContent = 'Signing in...';

    var form = new URLSearchParams();
    form.append('ajax', 'login');
    form.append('username', document.getElementById('username').value);
    form.append('password', document.getElementById('password').value);

    fetch('Login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
    })
    .then(r => r.json())
    .then(json => {
        if (json.ok) {
            window.location.href = 'homepage.php';
        } else {
            // Show server-provided message (e.g., 'Your account is locked.')
            showFloatingNotification(json.message || 'Invalid username or password!', 'error');
            // Clear password field
            document.getElementById('password').value = '';
        }
    })
    .catch(() => {
        showFloatingNotification('Network error. Please try again.', 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Login';
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Fix Forgot Password link
    document.querySelector('.link.forgot').addEventListener('click', function(e) {
        e.preventDefault();
        openChangePasswordModal();
    });

    // Fix Sign up link
    document.querySelector('.signup-link a').addEventListener('click', function(e) {
        e.preventDefault();
        openSignupModal();
    });

    // Fix Sign in link in signup modal
    document.querySelector('#signupModal .signup-link a').addEventListener('click', function(e) {
        e.preventDefault();
        closeSignupModal();
    });

    // Send Code button handler
    const sendCodeBtn = document.getElementById('sendCodeBtn');
    if (sendCodeBtn) {
        sendCodeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const username = document.getElementById('change-username').value;
            const email = document.getElementById('change-email').value;
            
            if (!username || !email) {
                showFloatingNotification('Please enter both username and email', 'error');
                return;
            }

            // Disable button and show loading state
            const originalText = this.textContent;
            this.disabled = true;
            this.textContent = 'Sending...';

            // Send request to server
            const data = new URLSearchParams();
            data.append('ajax', 'send_code');
            data.append('username', username);
            data.append('email', email);

            fetch('send_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showFloatingNotification(data.message || 'Verification code sent to your email', 'success');
                    // Do NOT enable password fields here; they will be enabled after code verification
                    // Start countdown timer
                    let timeLeft = 60;
                    this.disabled = true;
                    const timer = setInterval(() => {
                        if (timeLeft <= 0) {
                            clearInterval(timer);
                            this.disabled = false;
                            this.textContent = originalText;
                        } else {
                            this.textContent = `Wait ${timeLeft}s`;
                            timeLeft--;
                        }
                    }, 1000);
                } else {
                    showFloatingNotification(data.message || 'Failed to send code', 'error');
                    this.disabled = false;
                    this.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showFloatingNotification('Network error. Please try again.', 'error');
                this.disabled = false;
                this.textContent = originalText;
            });
        });
    }
});

// Password validation helpers
function validatePasswordRules(pwd) {
    const rules = {
        length: pwd.length >= 8,
        uppercase: /[A-Z]/.test(pwd),
        special: /[^A-Za-z0-9]/.test(pwd)
    };
    return { ok: rules.length && rules.uppercase && rules.special, rules };
}

function buildPasswordErrorText(r) {
    const missing = [];
    if (!r.rules.length) missing.push('8+ characters');
    if (!r.rules.uppercase) missing.push('1 uppercase letter');
    if (!r.rules.special) missing.push('1 special character');
    return missing.length ? `Password must include: ${missing.join(', ')}` : '';
}

// Attach live validation to Signup modal
document.addEventListener('DOMContentLoaded', () => {
    const sp = document.getElementById('signup-password');
    const sc = document.getElementById('signup-confirm-password');
    const spErr = document.getElementById('signup-password-error');
    const scErr = document.getElementById('signup-confirm-error');
    const signupFormEl = document.getElementById('signupForm');

    function validateSignupFields(showConfirmErrors = false) {
        let ok = true;
        if (sp) {
            const res = validatePasswordRules(sp.value || '');
            spErr && (spErr.textContent = buildPasswordErrorText(res));
            if (!res.ok) ok = false;
        }
        if (sc && sp) {
            const scVal = sc.value || '';
            const match = scVal === (sp.value || '');
            // Only show confirm error after user started typing in confirm, or when explicitly requested (on submit)
            const shouldShow = showConfirmErrors || scVal.length > 0;
            if (scErr) scErr.textContent = shouldShow && !match ? 'Passwords do not match' : '';
            if (!match) ok = false;
        }
        return ok;
    }

    if (sp) sp.addEventListener('input', () => validateSignupFields(false));
    if (sc) sc.addEventListener('input', () => validateSignupFields(false));
    if (signupFormEl) {
        signupFormEl.addEventListener('submit', function(ev){
            if (!validateSignupFields(true)) {
                ev.preventDefault(); ev.stopPropagation();
                // Keep modal open and focus first invalid
                if (spErr && spErr.textContent) sp && sp.focus();
                else if (scErr && scErr.textContent) sc && sc.focus();
                return false;
            }
        }, true);
    }
});

// Attach live validation to Password Recovery modal
document.addEventListener('DOMContentLoaded', () => {
    const np = document.getElementById('new-password');
    const cp = document.getElementById('confirm-password');
    const npErr = document.getElementById('recovery-password-error');
    const cpErr = document.getElementById('recovery-confirm-error');
    const changeForm = document.getElementById('changePasswordForm');

    function validateRecoveryFields(showConfirmErrors = false) {
        let ok = true;
        if (np && !np.disabled) {
            const res = validatePasswordRules(np.value || '');
            npErr && (npErr.textContent = buildPasswordErrorText(res));
            if (!res.ok) ok = false;
        }
        if (cp && np && !cp.disabled) {
            const cpVal = cp.value || '';
            const match = cpVal === (np.value || '');
            const shouldShow = showConfirmErrors || cpVal.length > 0;
            if (cpErr) cpErr.textContent = shouldShow && !match ? 'Passwords do not match' : '';
            if (!match) ok = false;
        }
        return ok;
    }

    if (np) np.addEventListener('input', () => validateRecoveryFields(false));
    if (cp) cp.addEventListener('input', () => validateRecoveryFields(false));
    if (changeForm) {
        changeForm.addEventListener('submit', function(ev){
            if (!validateRecoveryFields(true)) {
                ev.preventDefault(); ev.stopPropagation();
                if (npErr && npErr.textContent) np && np.focus();
                else if (cpErr && cpErr.textContent) cp && cp.focus();
                return false;
            }
        }, true);
    }
});

// Update the modal functions
function openSignupModal() {
    const modal = document.getElementById('signupModal');
    if (!modal) return;
    
    document.body.classList.add('modal-open');
    modal.style.display = 'flex';
    requestAnimationFrame(() => {
        modal.classList.add('show');
    });
}

function openChangePasswordModal() {
    const modal = document.getElementById('changePasswordModal');
    if (!modal) return;
    
    const np = document.getElementById('newPasswordFields');
    if (np) np.style.display = 'block';
    
    const usernameField = document.getElementById('username');
    const changeUsername = document.getElementById('change-username');
    if (changeUsername && usernameField) {
        changeUsername.value = usernameField.value || '';
    }
    
    const newPasswordField = document.getElementById('new-password');
    const confirmPasswordField = document.getElementById('confirm-password');
    if (newPasswordField) newPasswordField.disabled = true;
    if (confirmPasswordField) confirmPasswordField.disabled = true;
    
    document.body.classList.add('modal-open');
    modal.style.display = 'flex';
    requestAnimationFrame(() => {
        modal.classList.add('show');
    });
}

// Add direct click handlers to the links
document.addEventListener('DOMContentLoaded', function() {
    // Forgot password link
    const forgotLink = document.querySelector('.link.forgot');
    if (forgotLink) {
        forgotLink.onclick = function(e) {
            e.preventDefault();
            openChangePasswordModal();
        };
    }

    // Sign up link
    const signupLink = document.querySelector('.signup-link a');
    if (signupLink) {
        signupLink.onclick = function(e) {
            e.preventDefault();
            openSignupModal();
        };
    }

    // Already have account link in signup modal
    const signinLink = document.querySelector('#signupModal .signup-link a');
    if (signinLink) {
        signinLink.onclick = function(e) {
            e.preventDefault();
            closeSignupModal();
        };
    }

    // Live verify of OTP code to enable password fields only when correct
    const vcodeInput = document.getElementById('verification-code');
    const vcodeStatus = document.getElementById('verification-code-status');
    const npField = document.getElementById('new-password');
    const cpField = document.getElementById('confirm-password');
    let vTimer = null;
    function setRecoveryEnabled(enabled) {
        if (npField) npField.disabled = !enabled;
        if (cpField) cpField.disabled = !enabled;
    }
    setRecoveryEnabled(false);
    if (vcodeInput) {
        vcodeInput.addEventListener('input', function(){
            // debounce
            if (vTimer) clearTimeout(vTimer);
            vTimer = setTimeout(() => {
                const username = document.getElementById('change-username').value;
                const email = document.getElementById('change-email').value;
                const code = vcodeInput.value.trim();
                if (!username || !email || !code) {
                    vcodeStatus && (vcodeStatus.textContent = '');
                    setRecoveryEnabled(false);
                    return;
                }
                const params = new URLSearchParams();
                params.append('ajax', 'verify_code');
                params.append('username', username);
                params.append('email', email);
                params.append('code', code);
                fetch('Login.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                  .then(r => r.json())
                  .then(j => {
                    if (j.ok) {
                        // No success text; just enable the fields silently
                        vcodeStatus && (vcodeStatus.textContent = '');
                        setRecoveryEnabled(true);
                    } else {
                        vcodeStatus && (vcodeStatus.textContent = j.message || 'Invalid or expired code');
                        setRecoveryEnabled(false);
                    }
                  })
                  .catch(() => {
                    vcodeStatus && (vcodeStatus.textContent = 'Network error.');
                    setRecoveryEnabled(false);
                  });
            }, 350);
        });
    }
});

// Smooth AJAX Signup
var signupForm = document.getElementById('signupForm');
if (signupForm) {
document.getElementById('signupForm').addEventListener('submit', function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') { e.stopImmediatePropagation(); }
    const btn = document.getElementById('signupSubmit');
    btn.disabled = true; btn.textContent = 'Submitting...';

    const form = new FormData(this);
    form.append('ajax', 'signup');

    fetch('Login.php', { method: 'POST', body: new URLSearchParams(form) })
    .then(r => r.json())
    .then(j => {
        if (j.ok) {
            // show signup success modal with server message
            keepOverlayForStatus = true;
            closeSignupModal();
            document.body.classList.add('modal-open');
            const m = document.getElementById('signupSuccessModal');
            const sub = m.querySelector('.status-sub');
            if (sub) sub.textContent = j.message || 'Account creation successful, You can logon to your account now';
            m.style.display = 'flex';
            setTimeout(() => m.classList.add('show'), 50);
        } else {
            // show signup error modal with server message
            keepOverlayForStatus = true;
            closeSignupModal();
            document.body.classList.add('modal-open');
            const m = document.getElementById('signupErrorModal');
            const sub = m.querySelector('.status-sub');
            if (sub) sub.textContent = j.message || 'Oops.. Something went wrong, try again';
            m.style.display = 'flex';
            setTimeout(() => m.classList.add('show'), 50);
        }
    })
    .catch(() => alert('Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Submit'; });
});
}

// Smooth AJAX Change Password
document.getElementById('changePasswordForm').addEventListener('submit', function(e){
    e.preventDefault();
    const btns = this.querySelectorAll('.modal-buttons .btn');
    const resetBtn = this.querySelector('.modal-buttons .btn.primary');
    if (resetBtn) { resetBtn.disabled = true; resetBtn.textContent = 'Saving...'; }
    const form = new FormData(this);
    form.append('ajax', 'change_password');
    fetch('Login.php', { method: 'POST', body: new URLSearchParams(form) })
    .then(r => r.json())
    .then(j => {
        showFloatingNotification(j.message || (j.ok ? 'Password successfully changed!' : 'Failed to change password'), j.ok ? 'success' : 'error');
        if (j.ok) { 
            // Clear all fields in the form
            document.getElementById('change-email').value = '';
            document.getElementById('verification-code').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
            // Reset the send code button
            const sendCodeBtn = document.getElementById('sendCodeBtn');
            if (sendCodeBtn) {
                sendCodeBtn.disabled = false;
                sendCodeBtn.textContent = 'Send Code';
            }
            // Close the modal
            closeChangePasswordModal();
        }
    })
    .catch(() => showFloatingNotification('Network error. Please try again.', 'error'))
    .finally(() => { 
        if (resetBtn) { 
            resetBtn.disabled = false; 
            resetBtn.textContent = 'Reset'; 
        } 
    });
});

// Auto-open modal if there is an error
<?php
if (!empty($error)) {
    if (isset($_POST['signup_username'])) { ?>
        openSignupModal();
<?php } elseif (isset($_POST['change_username'])) { ?>
        openChangePasswordModal();
<?php } } ?>
<?php
if (!empty($changePasswordError)) { ?>
    openChangePasswordModal();
<?php } ?>

// Add these functions after your existing modal functions
function closeSignupModal() {
    const modal = document.getElementById('signupModal');
    if (!modal) return;
    
    if (!keepOverlayForStatus) {
        document.body.classList.remove('modal-open');
    }
    modal.classList.remove('show');
    setTimeout(() => {
        if (!keepOverlayForStatus) {
            modal.style.display = 'none';
        }
    }, 300);
}

function closeSignupSuccessModal() {
    const modal = document.getElementById('signupSuccessModal');
    if (!modal) return;
    
    document.body.classList.remove('modal-open');
    modal.classList.remove('show');
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
    keepOverlayForStatus = false;
}

function closeSignupErrorModal() {
    const modal = document.getElementById('signupErrorModal');
    if (!modal) return;
    
    document.body.classList.remove('modal-open');
    modal.classList.remove('show');
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
    keepOverlayForStatus = false;
}

function backToSignupFromError() {
    // Close the error modal and re-open the signup modal
    closeSignupErrorModal();
    // small delay to allow overlay state to reset
    setTimeout(() => {
        openSignupModal();
    }, 350);
}

function closeLoginErrorModal() {
    const modal = document.getElementById('loginErrorModal');
    if (!modal) return;
    
    document.body.classList.remove('modal-open');
    modal.classList.remove('show');
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

function closeChangePasswordModal() {
    const modal = document.getElementById('changePasswordModal');
    if (!modal) return;
    
    // Only close if explicitly called (e.g., by cancel button)
    document.body.classList.remove('modal-open');
    modal.classList.remove('show');
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

// Update modal click handlers to prevent event bubbling
document.addEventListener('DOMContentLoaded', function() {
    // Prevent modal closing when clicking modal content
    const modalContents = document.querySelectorAll('.modal-content');
    modalContents.forEach(content => {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    // Setup modal background clicks to close - but not for changePasswordModal
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                // Only close other modals, not changePasswordModal
                if (modal.id === 'signupModal') closeSignupModal();
                else if (modal.id === 'signupSuccessModal') closeSignupSuccessModal();
                else if (modal.id === 'signupErrorModal') closeSignupErrorModal();
                // Removed changePasswordModal from this list
            }
        });
    });

    // Typewriter animation for hero title with blinking cursor
    const line1 = document.getElementById('typewriter-line1');
    const line2 = document.getElementById('typewriter-line2');
    const text1 = 'BSIT OJT';
    const text2 = 'ACTIVITY LOG';
    let i1 = 0, i2 = 0;
    const cursor = '<span class="typewriter-cursor"></span>';
    
    function typeWriter() {
        // Reset both lines
        if (line1) line1.innerHTML = cursor; // Start with cursor on line 1
        if (line2) line2.innerHTML = '';
        i1 = 0;
        i2 = 0;
        
        // Type first line
        const timer1 = setInterval(() => {
            if (i1 < text1.length) {
                line1.innerHTML = text1.substring(0, i1 + 1) + cursor;
                i1++;
            } else {
                clearInterval(timer1);
                // Move cursor to line 2 and start typing
                line1.innerHTML = text1; // Remove cursor from line 1
                line2.innerHTML = cursor; // Add cursor to line 2
                
                const timer2 = setInterval(() => {
                    if (i2 < text2.length) {
                        line2.innerHTML = text2.substring(0, i2 + 1) + cursor;
                        i2++;
                    } else {
                        clearInterval(timer2);
                        // Keep cursor blinking at the end for 2 seconds
                        setTimeout(() => {
                            typeWriter();
                        }, 2000);
                    }
                }, 100);
            }
        }, 100);
    }
    
    // Start typewriter effect
    typeWriter();
});
</script>
</body>
</html>
</body>
</html>