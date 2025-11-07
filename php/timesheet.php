<?php
session_start();
require_once "db.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// Get selected week and year from GET or use current week
$selectedWeek = isset($_GET['week']) ? intval($_GET['week']) : date('W');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Fetch student info for name
$student = [
    'student_fn' => '',
    'student_mi' => '',
    'student_ln' => ''
];
if ($stmt = $conn->prepare("SELECT student_fn, student_mi, student_ln FROM student_info WHERE users_user_id = ? LIMIT 1")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $student = $res->fetch_assoc();
    }
    $stmt->close();
}

$last = trim($student['student_ln'] ?? '');
$first = trim($student['student_fn'] ?? '');
$mi = trim($student['student_mi'] ?? '');
$nameFormatted = trim($last . ', ' . $first . ($mi !== '' ? ' ' . strtoupper(substr($mi, 0, 1)) : ''));
if ($nameFormatted === ', ' || $nameFormatted === '') {
    // fallback to username if no student data
    if ($u = $conn->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1")) {
        $u->bind_param("i", $user_id);
        $u->execute();
        $u->bind_result($uname);
        if ($u->fetch()) { $nameFormatted = $uname; }
        $u->close();
    }
}

// Get all weeks with data for dropdown
$allWeeks = [];
$weeksSql = "SELECT DISTINCT YEAR(date_record) as yr, WEEK(date_record, 1) as wk
             FROM weekly_accomplishments
             WHERE users_user_id = ?
             ORDER BY yr DESC, wk DESC";
if ($wStmt = $conn->prepare($weeksSql)) {
    $wStmt->bind_param("i", $user_id);
    $wStmt->execute();
    $wRes = $wStmt->get_result();
    while ($wRow = $wRes->fetch_assoc()) {
        $allWeeks[] = ['year' => $wRow['yr'], 'week' => $wRow['wk']];
    }
    $wStmt->close();
}

// Check if "All Weeks" is selected
$isAllWeeks = isset($_GET['all']) && $_GET['all'] == '1';

// Load weekly accomplishments (filtered by week or all)
$entries = [];
if ($isAllWeeks) {
    // Load all entries
    $sql = "SELECT DATE(date_record) as d, time_in, time_out, task_completed, total_hours
            FROM weekly_accomplishments
            WHERE users_user_id = ?
            ORDER BY DATE(date_record) DESC, id DESC";
    if ($w = $conn->prepare($sql)) {
        $w->bind_param("i", $user_id);
        $w->execute();
        $r = $w->get_result();
        while ($row = $r->fetch_assoc()) $entries[] = $row;
        $w->close();
    }
} else {
    // Load for selected week
    $sql = "SELECT DATE(date_record) as d, time_in, time_out, task_completed, total_hours
            FROM weekly_accomplishments
            WHERE users_user_id = ?
            AND YEAR(date_record) = ?
            AND WEEK(date_record, 1) = ?
            ORDER BY DATE(date_record) DESC, id DESC";
    if ($w = $conn->prepare($sql)) {
        $w->bind_param("iii", $user_id, $selectedYear, $selectedWeek);
        $w->execute();
        $r = $w->get_result();
        while ($row = $r->fetch_assoc()) $entries[] = $row;
        $w->close();
    }
}

function fmt_time($t) {
    if ($t === null || $t === '' || $t === '---') return '';
    // normalize to HH:MM if HH:MM:SS
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) {
        return date('h:i A', strtotime($t));
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
        return date('h:i A', strtotime($t));
    }
    return htmlspecialchars($t);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DTR - Timesheet</title>
<style>
    :root { --blue: #224c7b; --line: #224c7b; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 20px; color: #222; }
    .logo-wrap { text-align: center; margin-bottom: 10px; }
    .logo-wrap img { height: 38px; object-fit: contain; }
    .logo-fallback { font-weight: 700; color: var(--blue); }

    .ts-table { width: 100%; border-collapse: collapse; }
    .ts-table th, .ts-table td {
        border: 1px solid var(--line);
        padding: 8px 10px;
        font-size: 13px;
        vertical-align: top;
    }
    .ts-table th { color: inherit; font-weight: 400; }
    /* Only these specific headers should be navy */
    .ts-table th.col-date,
    .ts-table th.col-time,
    .ts-table th.col-rendered,
    .ts-table th.col-remarks,
    .ts-table th.head-activity { color: navy; font-weight: 900; }
    .ts-table tr { page-break-inside: avoid; break-inside: avoid; }

    .top-meta th { text-align: center; font-weight: 600; }
    .group-head th { text-align: center; white-space:nowrap;}

    .col-date { width: 8%; white-space: nowrap; }
    .col-time { width: 6%; white-space: nowrap; }
    .col-activity { width: 35%; white-space: nowrap;}
    .col-rendered { width: 8%; }
    .col-remarks { width: 6%;}

    .week-selector { 
        text-align: right; 
        margin: 15px 0; 
        padding: 10px;
        background: #f5f5f5;
        border-radius: 5px;
    }
    .week-selector label { 
        font-weight: 600; 
        margin-right: 10px;
    }
    .week-selector select {
        padding: 6px 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
    }

    @media print {
        body { margin: 8mm; }
        .no-print { display: none !important; }
        .ts-table tr { page-break-inside: avoid; break-inside: avoid; }
    }
</style>
</head>
<body>
    <div class="logo-wrap">
        <img src="img/pkiilogo.png" alt="Company Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
    </div>

    <div class="week-selector no-print">
        <label for="weekSelect">Select Week:</label>
        <select id="weekSelect" onchange="if(this.value==='all'){location.href='timesheet.php?all=1';}else{location.href='timesheet.php?week='+this.value.split('-')[0]+'&year='+this.value.split('-')[1];}">
            <option value="all" <?= $isAllWeeks ? 'selected' : '' ?>>All Weeks</option>
            <?php if (!empty($allWeeks)): ?>
                <?php foreach ($allWeeks as $w): ?>
                    <?php
                        $wk = $w['week'];
                        $yr = $w['year'];
                        $dt = new DateTime();
                        $dt->setISODate($yr, $wk);
                        $monday = $dt->format('M d');
                        $dt->modify('+6 days');
                        $sunday = $dt->format('M d, Y');
                        $label = "Week $wk, $yr ($monday - $sunday)";
                        $isSelected = (!$isAllWeeks && $wk == $selectedWeek && $yr == $selectedYear);
                    ?>
                    <option value="<?= $wk ?>-<?= $yr ?>" <?= $isSelected ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>

    <table class="ts-table">
        <thead>
            <tr class="top-meta">
                <th colspan="9" style="text-align: center;">
                    <span style="display: inline-block; margin-right: 80px;">Name: <?php echo htmlspecialchars($nameFormatted); ?></span>
                    <span style="display: inline-block; margin-right: 80px;">Department: ITD</span>
                    <span style="display: inline-block;">Position: On-The-Job Trainee</span>
                </th>
            </tr>
            <tr class="group-head">
                <th colspan="3">Timesheet</th>
                <th colspan="1" class="head-activity">Activity Details</th>
                <th colspan="1">Time Summary</th>
                <th colspan="1"></th>
            </tr>
            <tr>
                <th class="col-date">Date</th>
                <th class="col-time">IN</th>
                <th class="col-time">OUT</th>
                <th class="col-activity"></th>
                <th class="col-rendered">Rendered</th>
                <th class="col-remarks">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr><td colspan="6" style="text-align:center; padding:20px;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td style="text-align:center; white-space:nowrap;"><?php echo htmlspecialchars(date('F d, Y', strtotime($e['d']))); ?></td>
                        <td style="text-align:center; white-space:nowrap;"><?php echo fmt_time($e['time_in']); ?></td>
                        <td style="text-align:center; white-space:nowrap;"><?php echo fmt_time($e['time_out']); ?></td>
                        <td style="text-align:center;"><?php echo nl2br(htmlspecialchars($e['task_completed'] ?? '')); ?></td>
                        <td style="text-align:center;"><?php echo htmlspecialchars(isset($e['total_hours']) && $e['total_hours'] !== null ? (string)intval($e['total_hours']) : ''); ?></td>
                        <td style="text-align:center;"></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
