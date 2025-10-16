<?php
session_start();

if (isset($_SESSION["user_id"])) {
    $conn = new mysqli("localhost", "root", "", "weeklyreport");
    
    if (!$conn->connect_error) {
        // Just update is_active to 0
        $update = $conn->prepare("UPDATE sessions SET is_active = 0 WHERE users_user_id = ?");
        if ($update) {
            $update->bind_param("i", $_SESSION["user_id"]);
            $update->execute();
            $update->close();
        }
        $conn->close();
    }
}


session_unset();
session_destroy();


header("Location: Login.php");
exit();
?>