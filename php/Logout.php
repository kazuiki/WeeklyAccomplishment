<?php
session_start();
require_once 'db.php';

if (isset($_SESSION["user_id"])) {
    if (!$conn->connect_error) {
        $user_id = $_SESSION["user_id"];
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Check if this is an AFK logout
        $is_afk_logout = isset($_POST['afk_logout']) && $_POST['afk_logout'] === 'true';
        
        // Update session to inactive
        $update = $conn->prepare("UPDATE sessions SET is_active = 0 WHERE users_user_id = ?");
        if ($update) {
            $update->bind_param("i", $user_id);
            $update->execute();
            $update->close();
        }
        
        // Log the logout attempt in login_attempts table with appropriate status
        $logout_status = $is_afk_logout ? 'AFK_LOGOUT' : 'LOG_OUT';
        $log_logout = $conn->prepare("INSERT INTO login_attempts (users_user_id, ip_address, attempt_time, status) VALUES (?, ?, NOW(), ?)");
        if ($log_logout) {
            $log_logout->bind_param("iss", $user_id, $ip_address, $logout_status);
            $log_logout->execute();
            $log_logout->close();
        }
        
        $conn->close();
    }
}


session_unset();
session_destroy();


header("Location: Login.php");
exit();
?>