<?php
session_start();
require_once "db.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

$user_id = $_SESSION["user_id"];
$week = isset($_GET['week']) ? intval($_GET['week']) : date('W');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$officialTimes = [
    'MON' => '',
    'TUE' => '',
    'WED' => '',
    'THU' => '',
    'FRI' => '',
    'SAT' => ''
];

// compute Monday..Saturday date range for the requested ISO week
try {
    $mondayDt = new DateTime();
    $mondayDt->setISODate($year, $week);
} catch (Exception $e) {
    $mondayDt = new DateTime();
}
$startDate = $mondayDt->format('Y-m-d');
$endDt = clone $mondayDt;
$endDt->modify('+5 days');
$endDate = $endDt->format('Y-m-d');

$sql = "SELECT day_date_real, day_date, day_time, created_at 
    FROM official_time 
    WHERE users_user_id = ? 
    AND ((day_date_real BETWEEN ? AND ?) OR (created_at BETWEEN ? AND ?))
    ORDER BY official_id DESC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("issss", $user_id, $startDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $rawReal = trim((string)($row['day_date_real'] ?? ''));
        $rawText = trim((string)$row['day_date']);
        $day = '';
        if ($rawReal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawReal)) {
            $day = strtoupper(substr(date('D', strtotime($rawReal)), 0, 3));
        } elseif ($rawText !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawText)) {
            $day = strtoupper(substr(date('D', strtotime($rawText)), 0, 3));
        } elseif ($rawText !== '' && preg_match('/[0-9]/', $rawText)) {
            // numeric but non-ISO date strings -> try strtotime
            $try = strtotime($rawText);
            if ($try !== false) {
                $dateStr = date('Y-m-d', $try);
                if ($dateStr >= $startDate && $dateStr <= $endDate) {
                    $day = strtoupper(substr(date('D', $try), 0, 3));
                } else {
                    $day = strtoupper(substr($rawText,0,3));
                }
            } else {
                $day = strtoupper(substr($rawText,0,3));
            }
        } else {
            // textual day_date present (e.g. 'Mon'/'MON'), prefer it; if blank fall back to created_at
            if ($rawText !== '') {
                $day = strtoupper(substr($rawText,0,3));
            } else {
                if (!empty($row['created_at']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $row['created_at'])) {
                    $day = strtoupper(date('D', strtotime(substr($row['created_at'],0,10))));
                } else {
                    $day = '';
                }
            }
        }
        if (array_key_exists($day, $officialTimes)) {
            $officialTimes[$day] = $row['day_time'];
        }
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($officialTimes);