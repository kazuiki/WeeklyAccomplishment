<?php
session_start();
require_once "db.php";

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SESSION["user_id"])) {
    $status = isset($_POST["status"]) ? (int)$_POST["status"] : 0;
    $user_id = $_SESSION["user_id"];
    
    $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $status, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        
        if ($success) {
            session_destroy();
            echo json_encode(["success" => true]);
            exit;
        }
    }
}

echo json_encode(["success" => false]);