<?php
session_start();
require_once "db.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// Get selected week and year from GET parameters
$selectedWeek = isset($_GET["week"]) ? intval($_GET["week"]) : 0;
$weekRange = isset($_GET["range"]) ? $_GET["range"] : "";
$selectedYear = isset($_GET["year"]) ? intval($_GET["year"]) : date('Y');
$approvedName = isset($_GET["approved_name"]) ? trim($_GET["approved_name"]) : '';
$approvedTitle = isset($_GET["approved_title"]) ? trim($_GET["approved_title"]) : '';

// If no week selected, use current week
if ($selectedWeek === 0) {
    $selectedWeek = intval(date('W'));
    $selectedYear = intval(date('Y'));
}

// Calculate the date range for the selected week (Monday to Saturday)
$dateTime = new DateTime();
$dateTime->setISODate($selectedYear, $selectedWeek);
$monday = clone $dateTime;
$saturday = clone $dateTime;
$saturday->modify('+5 days');

// Create week dates array for Monday to Saturday
$weekDates = [
    'MON' => $monday->format('Y-m-d'),
    'TUE' => (clone $monday)->modify('+1 day')->format('Y-m-d'),
    'WED' => (clone $monday)->modify('+2 days')->format('Y-m-d'),
    'THU' => (clone $monday)->modify('+3 days')->format('Y-m-d'),
    'FRI' => (clone $monday)->modify('+4 days')->format('Y-m-d'),
    'SAT' => (clone $monday)->modify('+5 days')->format('Y-m-d'),
];

$days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

// ==== TRAINING PERIOD BASED ON SELECTED WEEK ====
// Use the Monday to Saturday range of the selected week
$trainingFrom = $monday->format('F d, Y');
$trainingTo = $saturday->format('F d, Y');

// If you want the actual first and last dates that have data in this week, use this instead:
/*
$actualDateRangeSql = "SELECT MIN(date_record) as first_date, MAX(date_record) as last_date 
                      FROM weekly_accomplishments 
                      WHERE users_user_id = ? 
                      AND YEAR(date_record) = ? 
                      AND WEEK(date_record, 1) = ?";

if ($dateRangeStmt = $conn->prepare($actualDateRangeSql)) {
    $dateRangeStmt->bind_param("iii", $user_id, $selectedYear, $selectedWeek);
    $dateRangeStmt->execute();
    $dateRangeResult = $dateRangeStmt->get_result();
    
    if ($dateRangeRow = $dateRangeResult->fetch_assoc()) {
        if (!empty($dateRangeRow['first_date']) && $dateRangeRow['first_date'] != '0000-00-00') {
            $trainingFrom = date("F d, Y", strtotime($dateRangeRow['first_date']));
        }
        if (!empty($dateRangeRow['last_date']) && $dateRangeRow['last_date'] != '0000-00-00') {
            $trainingTo = date("F d, Y", strtotime($dateRangeRow['last_date']));
        }
    }
    $dateRangeStmt->close();
}
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $student_fn     = trim($_POST["student_fn"]);
    $student_mi     = trim($_POST["student_mi"]);
    $student_ln     = trim($_POST["student_ln"]);
    $company_name   = trim($_POST["company_name"]);
    $student_course = trim($_POST["student_course"]);
    $student_year   = trim($_POST["student_year"]);


    // check if already exists
    $check = $conn->prepare("SELECT id FROM student_info WHERE users_user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        // update (removed training_from/training_to)
        $sql = "UPDATE student_info
                SET student_fn=?, student_mi=?, student_ln=?, company_name=?, student_course=?, student_year=?
                WHERE users_user_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $student_fn, $student_mi, $student_ln, $company_name, $student_course, $student_year, $user_id);
    } else {
        // insert (removed training_from/training_to)
        $sql = "INSERT INTO student_info
                (student_fn, student_mi, student_ln, company_name, student_course, student_year, users_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $student_fn, $student_mi, $student_ln, $company_name, $student_course, $student_year, $user_id);
    }

    $stmt->execute();
    $stmt->close();
    $check->close();
}


$sql = "SELECT student_fn, student_mi, student_ln, company_name, student_course, student_year
    FROM student_info WHERE users_user_id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("❌ SQL error: " . $conn->error . " | Query: " . $sql);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

// ✅ Format name properly (Firstname MI. Lastname)
$name = "";
if (!empty($student)) {
    $name = $student['student_fn'];
    if (!empty($student['student_mi'])) {
        $name .= " " . strtoupper(substr($student['student_mi'], 0, 1)) . ".";
    }
    $name .= " " . $student['student_ln'];
}

// Week number isn't computed from training period anymore; leave blank unless determined elsewhere
$weekNumber = '';

// Initialize official times array with empty values
$officialTimes = [
    'MON' => '',
    'TUE' => '',
    'WED' => '',
    'THU' => '',
    'FRI' => '',
    'SAT' => ''
];

// Helper: fetch official times for a week range
function getOfficialTimesForWeek($conn, $user_id, $startDate, $endDate) {
    $result = ['MON'=>'','TUE'=>'','WED'=>'','THU'=>'','FRI'=>'','SAT'=>''];
        // Only include textual day_date rows when their created_at falls in the week.
        $sql = "SELECT id, day_date, day_time, created_at
                        FROM official_time
                        WHERE users_user_id = ?
                            AND (
                                (day_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND day_date BETWEEN ? AND ?) OR
                                (NOT (day_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$') AND created_at BETWEEN ? AND ?)
                            )
                        ORDER BY COALESCE(day_date, created_at) DESC";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('issss', $user_id, $startDate, $endDate, $startDate, $endDate);
        $st->execute();
        $res = $st->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $st->close();
    } else {
        error_log('getOfficialTimesForWeek prepare failed: ' . $conn->error);
        return $result;
    }

    // first pass: date rows inside range
    foreach ($rows as $r) {
        $raw = trim($r['day_date']);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $raw)) {
            $dt = strtotime($raw);
            if ($dt === false) continue;
            $d = date('Y-m-d', $dt);
            if ($d < $startDate || $d > $endDate) continue;
            $day = strtoupper(substr(date('D', $dt), 0, 3));
            if ($result[$day] === '') $result[$day] = $r['day_time'];
        }
    }

    // second pass: textual rows — only map if created_at falls inside the week
    foreach ($rows as $r) {
        $raw = trim($r['day_date']);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $raw)) continue; // already handled

        // textual day (e.g. 'Mon') -> map only if created_at is within start/end
        if (!empty($r['created_at']) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $r['created_at'])) {
            $c = substr($r['created_at'], 0, 10);
            if ($c >= $startDate && $c <= $endDate) {
                $mappedDay = strtoupper(substr($raw, 0, 3));
                if ($mappedDay !== '' && $result[$mappedDay] === '') $result[$mappedDay] = $r['day_time'];
            }
        }
    }

    return $result;
}

// Debug log before query
error_log("Fetching official times for user: $user_id, week: $selectedWeek, year: $selectedYear");
// Prepare week date range (Monday - Saturday)
$startDate = $weekDates['MON'];
$endDate = $weekDates['SAT'];

// official_time: prefer rows where day_date is an actual date inside the selected week;
// for textual day_date values (e.g., 'MON'), use created_at to decide which week they belong to.
$officialSql = "SELECT ot.day_date, ot.day_time, ot.created_at
                FROM official_time ot
                WHERE ot.users_user_id = ?
                  AND (
                    (ot.day_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND ot.day_date BETWEEN ? AND ?) OR
                    (NOT (ot.day_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$') AND ot.created_at BETWEEN ? AND ?)
                  )
                ORDER BY COALESCE(ot.day_date, ot.created_at) DESC";

$rows = [];
if ($ot = $conn->prepare($officialSql)) {
    $ot->bind_param('issss', $user_id, $startDate, $endDate, $startDate, $endDate);
    $ot->execute();
    $result = $ot->get_result();
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    $ot->close();
} else {
    error_log("Failed to prepare officialSql: " . $conn->error);
}

// Map fetched rows into officialTimes (convert date -> day name, or use textual day)
foreach ($rows as $row) {
    $raw = trim($row['day_date']);
    $day = '';
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $raw)) {
        $dt = strtotime($raw);
        if ($dt === false) continue;
        $dateStr = date('Y-m-d', $dt);
        // ensure date is inside selected range (defensive)
        if ($dateStr < $startDate || $dateStr > $endDate) continue;
        $day = strtoupper(substr(date('D', $dt), 0, 3));
    } else {
        // If day_date is present but not ISO (e.g., '10/03/2025' or '03-10-2025' or textual)
        if ($raw !== '') {
            // If it contains digits, try to parse common date formats via strtotime
            if (preg_match('/[0-9]/', $raw)) {
                $try = strtotime($raw);
                if ($try !== false) {
                    $dateStr = date('Y-m-d', $try);
                    // ensure date is inside selected range (defensive)
                    if ($dateStr >= $startDate && $dateStr <= $endDate) {
                        $day = strtoupper(substr(date('D', $try), 0, 3));
                    } else {
                        // parsed date not inside week -> fall back to textual substr
                        $day = strtoupper(substr($raw, 0, 3));
                    }
                } else {
                    // not parseable -> treat as textual day
                    $day = strtoupper(substr($raw, 0, 3));
                }
            } else {
                // textual day (MON, Mon, Monday)
                $day = strtoupper(substr($raw, 0, 3));
            }
        } else {
            // if day_date is blank, fall back to created_at mapping
            if (!empty($row['created_at']) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $row['created_at'])) {
                $c = substr($row['created_at'], 0, 10);
                $day = strtoupper(substr(date('D', strtotime($c)), 0, 3));
            } else {
                $day = '';
            }
        }
    }

    if (array_key_exists($day, $officialTimes)) {
        $officialTimes[$day] = $row['day_time'];
        error_log("Mapped official_time -> $day : {$row['day_time']} (raw: {$row['day_date']})");
    } else {
        error_log("Unmapped official_time row: raw='{$row['day_date']}', mapped='$day'");
    }
}

// Debug output
error_log("Final official times array: " . print_r($officialTimes, true));

// Extra fallback: for any day still empty, try to fetch official_time row that has
// day_date exactly equal to the week's date (covers cases where created_at differs).
foreach ($officialTimes as $dayKey => $val) {
    if (!empty($val)) continue;
    $dateToCheck = $weekDates[$dayKey] ?? null;
    if (!$dateToCheck) continue;
    $fallbackSql = "SELECT day_time FROM official_time WHERE users_user_id = ? AND day_date = ? LIMIT 1";
    if ($fs = $conn->prepare($fallbackSql)) {
        $fs->bind_param('is', $user_id, $dateToCheck);
        $fs->execute();
        $fr = $fs->get_result();
        if ($row = $fr->fetch_assoc()) {
            $officialTimes[$dayKey] = $row['day_time'];
            error_log("Fallback filled official_time for $dayKey using exact date $dateToCheck: {$row['day_time']}");
        }
        $fs->close();
    } else {
        error_log("Failed to prepare fallback official_time query: " . $conn->error);
    }
}

// Add this code before the foreach loop for daily entries
$sumGrandTotal = 0; // Initialize the sum variable

// Initialize $entriesByDay array for all days
$entriesByDay = array_fill_keys($days, null);

// Debug logging
error_log("Selected Week: $selectedWeek, Selected Year: $selectedYear");
error_log("Training Period (Week Range): $trainingFrom to $trainingTo");

// Load weekly accomplishments for the specific selected week and year
$weeklySql = "SELECT wa.* 
              FROM weekly_accomplishments wa
              WHERE wa.users_user_id = ? 
              AND YEAR(wa.date_record) = ? 
              AND WEEK(wa.date_record, 1) = ?
              ORDER BY wa.date_record ASC";

if ($stmt = $conn->prepare($weeklySql)) {
    $stmt->bind_param("iii", $user_id, $selectedYear, $selectedWeek);
    $stmt->execute();
    $result = $stmt->get_result();
    
    error_log("Loading data for selected week: " . $selectedWeek . " year: " . $selectedYear);
    
    // Process results
    while ($row = $result->fetch_assoc()) {
        // Get day abbreviation from date
        $dayDate = new DateTime($row['date_record']);
        $day = strtoupper($dayDate->format('D'));
        
        // Calculate hours only if both time_in and time_out exist
        if (!empty($row['time_in']) && !empty($row['time_out'])) {
            $timeIn = strtotime($row['time_in']);
            $timeOut = strtotime($row['time_out']);
            
            if ($timeOut < $timeIn) {
                $timeOut = strtotime('+1 day', $timeOut);
            }

            $totalHours = ($timeOut - $timeIn) / 3600;
            // Cap to 8 hours max and convert to integer hours (no decimals)
            $intHours = (int) floor($totalHours);
            if ($intHours < 0) $intHours = 0;
            if ($intHours > 8) $intHours = 8;
            $row['total_hours'] = $intHours;
            $row['grand_total'] = $row['total_hours'];
            
            $sumGrandTotal += $row['grand_total'];
        } else {
            $row['total_hours'] = 0;
            $row['grand_total'] = 0;
        }
        
        // Store the entry in the array
        $entriesByDay[$day] = $row;
        
        error_log("Processing entry for $day: " . json_encode($row));
    }
    
    $stmt->close();
} else {
    error_log("Failed to prepare statement: " . $conn->error);
}

// Debug final data
error_log("Final entriesByDay array: " . print_r($entriesByDay, true));
error_log("Sum Grand Total: " . $sumGrandTotal);

// If debug mode requested in URL, render a small debug panel so the user can
// see exactly what official_time rows were fetched and how they were mapped.
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<div style='position:fixed; right:10px; top:10px; background:#fff; color:#000; border:1px solid #888; padding:10px; z-index:9999; max-width:420px; font-size:12px;'>";
    echo "<strong>DEBUG: official_time rows</strong><br>";
    echo "<pre style='white-space:pre-wrap; font-size:11px; max-height:240px; overflow:auto;'>" . htmlspecialchars(print_r(isset($rows) ? $rows : 'NO_ROWS', true)) . "</pre>";
    echo "<strong>Mapped officialTimes</strong><br>";
    echo "<pre style='white-space:pre-wrap; font-size:11px; max-height:200px; overflow:auto;'>" . htmlspecialchars(print_r($officialTimes, true)) . "</pre>";
    echo "<strong>entriesByDay</strong><br>";
    echo "<pre style='white-space:pre-wrap; font-size:11px; max-height:200px; overflow:auto;'>" . htmlspecialchars(print_r($entriesByDay, true)) . "</pre>";
    echo "</div>";
}

function safe($v) { return htmlspecialchars((string)$v ?? '', ENT_QUOTES, 'UTF-8'); }

// Update the formatTime function
function formatTime($time) {
    if ($time === null || empty($time) || $time === '00:00:00' || $time === 'null') {
        return '---';
    }
    // Return compact format (no leading zero hour, no space before AM/PM): 8:00AM
    return date('g:iA', strtotime($time));
}
?>

<?php
// Check if this is being loaded via AJAX (embedded in homepage)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Normalize display times: remove duplicate am/pm tokens and trim
function normalizeTimeDisplay($t) {
    if ($t === null) return '';
    $s = trim($t);
    if ($s === '') return '';
    // Remove repeated am/am or pm/pm (case-insensitive)
    $s = preg_replace('/\b(am)\s+\1\b/i', '$1', $s);
    $s = preg_replace('/\b(pm)\s+\1\b/i', '$1', $s);
    // Normalize spacing and casing; produce compact form like 8:00AM
    $s = preg_replace_callback('/(\d{1,2}:\d{2})\s*(am|pm)/i', function($m){ return $m[1] . strtoupper($m[2]); }, $s);
    return $s;
}

if (!$isAjax): ?>
<!DOCTYPE html>
<html>
<head>
    <title>Weekly Accomplishment Report</title>
    <link rel="stylesheet" href="css/viewform.css">
    <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
    <style>
        body {
            padding: 20px 40px;
            margin: 0;
        }
        .viewform-container {
            max-width: 100%;
            margin: 0 auto;
        }
    </style>
    <?php endif; ?>
</head>
<body>
<?php endif; ?>

<?php $isPrint = isset($_GET['print']) && $_GET['print'] == '1'; ?>

<div class="viewform-container">
    <div class="header-container" style="display: flex; justify-content: center; align-items: center; gap: 20px; position: relative;">
  <div class="official-time" style="position: absolute; left: 0; top: 0; font-size: 14px; text-align: left;">
    <p><b>Official Time</b></p>
    <div style="display: flex; gap: 20px;">
        <div>
            <?php 
            foreach ($officialTimes as $day => $time): 
                $displayDay = ucfirst(strtolower($day));
                $raw = trim($time);
                if ($raw === '') {
                    // placeholder: show separator as '---' centered
                    $left = '';
                    $sep = '---';
                    $right = '';
                    $sepClass = 'ot-sep placeholder';
                } else {
                    // split into left and right around first '-'
                    $parts = explode('-', $raw, 2);
                    $left = normalizeTimeDisplay(trim($parts[0] ?? ''));
                    $right = normalizeTimeDisplay(trim($parts[1] ?? ''));
                    $left = htmlspecialchars($left);
                    $right = htmlspecialchars($right);
                    $sep = '-';
                    $sepClass = 'ot-sep';
                }
            ?>
                <p>
                    <span class="ot-day"><?= $displayDay ?>:</span>
                    <span class="ot-time">
                        <span class="ot-left"><?= $left ?></span>
                        <span class="<?= $sepClass ?>"><?= $sep ?></span>
                        <span class="ot-right"><?= $right ?></span>
                    </span>
                </p>
            <?php endforeach; ?>
        </div>
    </div>
  </div>

  <div class="header-logo">
    <img src="img/qcu.png" alt="QCU Logo" style="width: 90px; height: auto;">
  </div>

  <div class="header-text" style="text-align: center;">
    <h3>Republic of the Philippines</h3>
    <h1>Quezon City University</h1>
    <h3>673 Quirino Highway San Bartolome, Novaliches, Quezon City</h3><br>
    <h2>WEEKLY ACCOMPLISHMENT REPORT</h2>
    <h2>(ON-THE-JOB-TRAINING 1)</h2>
  </div>

  <div class="header-logo">
    <img src="img/it.png" alt="IT Logo" style="width: 90px; height: auto;">
  </div>

</div>

<br>
<div class="header-section" style="position: relative;">
  <div id="week-number" style="position: absolute; right: 250px; top: -130px; text-align: center;">
  <div style="min-width: 150px;">
    <!-- Date Range -->
    <div style="font-size: 12px; margin-bottom: 3px;">
      <?php echo htmlspecialchars($weekRange); ?>
    </div>

    <!-- Line + Week No. -->
    <div style="border-top: 1px solid black; padding-top: 2px;">
      Week No.
    </div>
  </div>
</div>

<div class="header-info-grid">
  <div class="info-left">
    <div class="info-row">
        <label>Name</label>
        <span class="sep"> : </span>
        <span class="underline"><?= safe($name) ?></span>
    </div>
    <div class="info-row">
        <label>Company</label>
        <span class="sep"> : </span>
        <span class="underline small company-underline"><?= safe($student['company_name'] ?? '') ?></span>
    </div>
  </div>
  <div class="info-right">
    <div class="info-row">
        <label>Course</label>
        <span class="sep"> : </span>
        <span class="underline small"><?= safe($student['student_course'] ?? '') ?></span>
        <label class="inline-year">Year</label>
        <span class="sep"> : </span>
        <span class="underline tiny"><?= safe($student['student_year'] ?? '') ?></span>
    </div>
    <div class="info-row">
        <label>Training Period</label>
        <span class="sep"> : </span>
        From <span class="underline small"><?= safe($trainingFrom) ?></span> 
        To <span class="underline small"><?= safe($trainingTo) ?></span>
    </div>
  </div>
</div>

  <div class="warning-text"<?php if ($isPrint) echo ' style="position:absolute;top:0;right:0;font-size:11px;max-width:220px;text-align:right;line-height:1.3;margin-top:-160px;background:#fff;padding:2px 6px;border-radius:4px;z-index:10;"'; ?>>
      Any alterations/erasures will consider null and void unless with countersign of immediate supervisor.
  </div>

<!-- Rest of your HTML table and signature sections remain the same -->
<table>
  <tr>
    <th rowspan="2">Date</th>
    <th rowspan="2">Day</th>
    <th colspan="2" class="regular-time">Regular Time</th>
    <th rowspan="2">Task Assigned and Completed</th>
    <th rowspan="2">Total Hours</th>
    <th rowspan="2">Grand Total</th>
    <th rowspan="2">Remarks</th>
  </tr>
  <tr>
    <th class="time-in">Time-In</th>
    <th class="time-out">Time-Out</th>
  </tr>
  

    <?php foreach ($days as $day) {
        // Get the date string safely
        $dateStr = $weekDates[$day] ?? '';
        
        // Get the entry data safely using null coalescing operator
        $entry = $entriesByDay[$day] ?? null;
        
        // Get individual values safely
        $timeIn = $entry['time_in'] ?? null;
        $timeOut = $entry['time_out'] ?? null;
        $taskCompleted = $entry['task_completed'] ?? null;
        $totalHours = $entry['total_hours'] ?? null;
        $grandTotal = $entry['grand_total'] ?? null;
    ?>
    <tr>
        <td><?= empty($dateStr) ? '' : safe(date('m/d/Y', strtotime($dateStr))) ?></td>
        <td><?= $day ?></td>
        <td><?= formatTime($timeIn) ?></td>
        <td><?= formatTime($timeOut) ?></td>
        <td class="task-cell">
            <?= empty($taskCompleted) ? '---' : nl2br(safe($taskCompleted)) ?>
        </td>
    <td><?= $totalHours !== null ? safe(intval($totalHours)) : '---' ?></td>
    <td><?= $grandTotal !== null ? safe(intval($grandTotal)) : '---' ?></td>
        <td></td>
    </tr>
    <?php } ?>
  <tr>
    <td colspan="6" style="text-align: right; font-weight: bold;">GRAND TOTAL</td>
    <td><?= safe(intval($sumGrandTotal)) ?></td>
    <td></td>
  </tr>
</table>

<div class="signature-container"<?php if ($isPrint) echo ' style="margin-top:18px;margin-bottom:0;"'; ?>>
    <div class="signature-block">
        <p class="bold">Prepared by:</p>
        <br><br>
        <?php if (isset($isPrint) && $isPrint): ?>
        <p class="bold" style="margin: 0; border-bottom: none;"><?= htmlspecialchars($name) ?></p>
        <?php else: ?>
        <p class="line-field bold"><?= htmlspecialchars($name) ?></p>
        <?php endif; ?>
        <p>OJT Trainee</p>
    <p>Date: <?php if (isset($isPrint) && $isPrint): ?><span><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php else: ?><span contenteditable="true"><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php endif; ?></p>
    </div>


    <div class="signature-block">
        <p class="bold">Approved by:</p>
        <br><br>
        <?php if (isset($isPrint) && $isPrint): ?>
        <p class="bold" style="margin: 0; border-bottom: none;">
        <?php else: ?>
        <p contenteditable="true" class="line-field bold" data-placeholder="Type name here...">
        <?php endif; ?><?= $approvedName !== '' ? htmlspecialchars($approvedName) : '' ?></p>
        <?php if (isset($isPrint) && $isPrint): ?>
        <p class="" style="margin: 0; border-bottom: none;">
        <?php else: ?>
        <br>
        <p contenteditable="true" class="line-field" data-placeholder="Type title here...">
        <?php endif; ?><?= $approvedTitle !== '' ? htmlspecialchars($approvedTitle) : '' ?></p>
    <p>Date: <?php if (isset($isPrint) && $isPrint): ?><span><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php else: ?><span contenteditable="true"><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php endif; ?></p>
    </div>

    <div class="signature-block">
        <p class="bold">Recorded by:</p>
        <br><br>
        <p class="bold">Isagani M. Tano, PhD-ELM, DIT</p>
        <p>OJT Adviser</p>
    <p>Date: <?php if (isset($isPrint) && $isPrint): ?><span><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php else: ?><span contenteditable="true"><?= htmlspecialchars(date('F d, Y', strtotime($weekDates['FRI']))) ?></span><?php endif; ?></p>
    </div>
</div>

<?php if (!$isAjax): ?>
</body>
</html>
<?php endif; ?>