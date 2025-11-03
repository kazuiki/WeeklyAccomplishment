<?php
session_start();
// Ensure only logged-in admin can access table data
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}
require_once 'db.php';

// Get filter parameters (same as main dashboard)
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$records_per_page = 5;
$offset = ($page - 1) * $records_per_page;

// Build query for weekly accomplishments with filters (same as main dashboard)
$query = "SELECT 
            wa.id,
            wa.date_record,
            wa.time_in,
            wa.time_out,
            wa.task_completed,
            wa.grand_total,
            wa.last_updated_at,
            u.user_id,
            u.username,
            u.email
          FROM weekly_accomplishments wa
          LEFT JOIN users u ON wa.users_user_id = u.user_id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $search_condition = " AND (u.username LIKE ? OR u.email LIKE ? OR wa.task_completed LIKE ?)";
    $query .= $search_condition;
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($date_from)) {
    $date_condition = " AND wa.date_record >= ?";
    $query .= $date_condition;
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $date_condition = " AND wa.date_record <= ?";
    $query .= $date_condition;
    $params[] = $date_to;
    $types .= "s";
}

if (!empty($user_filter)) {
    $user_condition = " AND wa.users_user_id = ?";
    $query .= $user_condition;
    $params[] = $user_filter;
    $types .= "i";
}

$query .= " ORDER BY wa.date_record DESC, wa.last_updated_at DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

// Execute query
$stmt = $conn->prepare($query);
$rows = [];
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => $row['id'],
                'date_record' => date('M d, Y', strtotime($row['date_record'])),
                'username' => htmlspecialchars($row['username']),
                'email' => htmlspecialchars($row['email']),
                'time_in' => htmlspecialchars($row['time_in'] ?? 'N/A'),
                'time_out' => htmlspecialchars($row['time_out'] ?? 'N/A'),
                'grand_total' => number_format($row['grand_total'] ?? 0, 2),
                'task_completed' => htmlspecialchars($row['task_completed'] ?? 'No task recorded'),
                'last_updated_at' => date('M d, Y g:i A', strtotime($row['last_updated_at'])),
                'user_id' => $row['user_id']
            ];
        }
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode(['rows' => $rows, 'timestamp' => time()]);
exit();