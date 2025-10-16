<?php
session_start();
require_once "db.php";

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    exit("Unauthorized");
}

$user_id = $_SESSION["user_id"];

// Get profile picture from database
$sql = "SELECT profile_picture, profile_picture_type FROM student_info WHERE users_user_id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit("Database error");
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $image_data = $row['profile_picture'];
    $image_type = $row['profile_picture_type'];
    
    if ($image_data) {
        // Use stored MIME type if available, otherwise detect from binary data
        if ($image_type) {
            $mime_type = $image_type;
        } else {
            // Fallback: detect MIME type from binary data
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->buffer($image_data);
        }
        
        // Set appropriate headers
        header("Content-Type: " . $mime_type);
        header("Content-Length: " . strlen($image_data));
        header("Cache-Control: public, max-age=3600"); // Cache for 1 hour
        
        // Output the image data
        echo $image_data;
    } else {
        // Return default avatar if no image
        header("Content-Type: image/svg+xml");
        echo '<?xml version="1.0" encoding="UTF-8"?>
<svg width="100" height="100" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
<rect width="100" height="100" fill="#F0F0F0"/>
<circle cx="50" cy="35" r="15" fill="#CCCCCC"/>
<path d="M20 80C20 65.6406 32.6406 53 47 53H63C77.3594 53 90 65.6406 90 80V100H20V80Z" fill="#CCCCCC"/>
</svg>';
    }
} else {
    // Return default avatar if no record found
    header("Content-Type: image/svg+xml");
    echo '<?xml version="1.0" encoding="UTF-8"?>
<svg width="100" height="100" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
<rect width="100" height="100" fill="#F0F0F0"/>
<circle cx="50" cy="35" r="15" fill="#CCCCCC"/>
<path d="M20 80C20 65.6406 32.6406 53 47 53H63C77.3594 53 90 65.6406 90 80V100H20V80Z" fill="#CCCCCC"/>
</svg>';
}

$stmt->close();
$conn->close();
?>
