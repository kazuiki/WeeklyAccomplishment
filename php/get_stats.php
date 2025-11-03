<?php
session_start();
// Ensure only logged-in admin can access stats
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}
require_once 'db.php';

$stats_query = "SELECT 
                COUNT(DISTINCT wa.users_user_id) as total_students,
                COUNT(wa.id) as total_logs,
                COALESCE(SUM(wa.grand_total), 0) as total_hours
                FROM weekly_accomplishments wa";
$stats_result = $conn->query($stats_query);
if (!$stats_result) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit();
}
$stats = $stats_result->fetch_assoc();
// Normalize types
$stats['total_students'] = intval($stats['total_students']);
$stats['total_logs'] = intval($stats['total_logs']);
$stats['total_hours'] = floatval($stats['total_hours']);

header('Content-Type: application/json');
echo json_encode($stats);
exit();