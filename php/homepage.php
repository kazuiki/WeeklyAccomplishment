<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: login.php");
    exit;
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Determine initial week/year for the logged-in user. If the user is marked
// as new (is_new = 1) use the account creation date as their start week so
// week selectors default to the week where the account was created.
$initialWeek = date('W');
$initialYear = date('Y');
$initialRangeStart = null; // used later for JS range formatting
try {
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        $uStmt = $conn->prepare("SELECT is_new, created_at FROM users WHERE user_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param('i', $uid);
            $uStmt->execute();
            $uStmt->bind_result($db_is_new, $db_created_at);
            if ($uStmt->fetch()) {
                if (!empty($db_is_new) && $db_is_new == 1 && !empty($db_created_at)) {
                    try {
                        $dt = new DateTime($db_created_at);
                        // ISO week/year
                        $initialWeek = (int)$dt->format('W');
                        $initialYear = (int)$dt->format('Y');
                        // compute monday..saturday range for JS (Y-m-d)
                        $monday = clone $dt;
                        // set ISO week day to Monday of that ISO week
                        $monday->setISODate((int)$initialYear, (int)$initialWeek, 1);
                        $sat = clone $monday;
                        $sat->modify('+5 days');
                        $initialRangeStart = $monday->format('Y-m-d');
                        $initialRangeEnd = $sat->format('Y-m-d');
                    } catch (Exception $e) {
                        // ignore and fall back to current week
                    }
                }
            }
            $uStmt->close();
        }
    }
} catch (Exception $e) {
    // swallow errors and fallback to current week
}
// ---------- AJAX: load_today / save_form ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ["status" => "error", "message" => ""];

    $user_id = $_SESSION["user_id"];
    $today = date("Y-m-d");

    if ($_POST["ajax"] === "load_today") {
        // Get selected week from POST
        $selectedWeek = isset($_POST["week"]) ? intval($_POST["week"]) : date('W');
        $selectedYear = isset($_POST["year"]) ? intval($_POST["year"]) : date('Y');
       
        // Check if this is the current week (for edit permissions)
        $currentWeek = date('W');
        $currentYear = date('Y');
        $isCurrentWeek = ($selectedWeek == $currentWeek && $selectedYear == $currentYear);

        // Load weekly accomplishments
        $sql = "SELECT wa.time_in, wa.time_out, wa.task_completed
                FROM weekly_accomplishments wa
                WHERE wa.users_user_id = ?
                AND WEEK(wa.date_record) = ?
                AND YEAR(wa.date_record) = ?";
               
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("iii", $user_id, $selectedWeek, $selectedYear);
            $stmt->execute();
            $result = $stmt->get_result();
           
            $response["status"] = "success";
            $row = $result->fetch_assoc() ?: [];
           
            // Load official times for selected week
            // Compute Monday..Saturday date range for the selected ISO week so
            // we can match rows stored with real dates (day_date = 'YYYY-MM-DD')
            // as well as older rows that used textual day_date mapped via created_at.
            try {
                $mondayDt = new DateTime();
                $mondayDt->setISODate($selectedYear, $selectedWeek);
            } catch (Exception $e) {
                $mondayDt = new DateTime();
            }
            $startDate = $mondayDt->format('Y-m-d');
            $endDt = clone $mondayDt;
            $endDt->modify('+5 days'); // Monday..Saturday inclusive
            $endDate = $endDt->format('Y-m-d');

            // prefer the new date column `day_date_real` (Y-m-d) when present;
            // keep existing textual `day_date` values for legacy rows
            $officialSql = "SELECT official_id, day_date_real, day_date, day_time, created_at
                           FROM official_time
                           WHERE users_user_id = ?
                           AND ((day_date_real BETWEEN ? AND ?) OR (created_at BETWEEN ? AND ?))
                           ORDER BY official_id DESC";

            if ($otStmt = $conn->prepare($officialSql)) {
                $otStmt->bind_param("issss", $user_id, $startDate, $endDate, $startDate, $endDate);
                $otStmt->execute();
                $otResult = $otStmt->get_result();

                while ($otRow = $otResult->fetch_assoc()) {
                    // Prefer the real date column when available
                    $rawDate = $otRow['day_date_real'];
                    $rawText = $otRow['day_date'];
                    if (!empty($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                        $wk = strtolower(date('D', strtotime($rawDate))); // Mon -> mon
                        $day = $wk;
                    } else {
                        // textual values like 'Mon' or 'MON' or 'mon'
                        $day = strtolower((string)$rawText);
                    }

                    // normalize to expected keys and set response fields
                    if (!empty($day)) {
                        $rowKey = "official_" . $day;
                        $row[$rowKey] = $otRow['day_time'];
                        $row[$rowKey . "_id"] = $otRow['official_id'];
                    }
                }
                $otStmt->close();
            }
           
            $response["data"] = $row;
            $response["selected_week"] = $selectedWeek;
            $response["selected_year"] = $selectedYear;
            $response["is_current_week"] = $isCurrentWeek;
           
            $stmt->close();
        }

       
        echo json_encode($response);
        exit();
    }

    if ($_POST["ajax"] === "save_form") {
        try {
            $conn->begin_transaction();
           
            $selectedWeek = isset($_POST["week"]) ? intval($_POST["week"]) : date('W');
            $selectedYear = isset($_POST["year"]) ? intval($_POST["year"]) : date('Y');
           
            // Check if this is the current week (for edit permissions)
            $currentWeek = date('W');
            $currentYear = date('Y');
            $isCurrentWeek = ($selectedWeek == $currentWeek && $selectedYear == $currentYear);
           
            // DEBUG: Log what we received
            error_log("DEBUG - Received data:");
            error_log("Week: $selectedWeek, Year: $selectedYear");
            error_log("Time In: " . ($_POST['time_in'] ?? 'NOT SET'));
            error_log("Time Out: " . ($_POST['time_out'] ?? 'NOT SET'));
            error_log("Task: " . ($_POST['task_completed'] ?? 'NOT SET'));
           
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
                $key = "official_" . $day;
                error_log("$key: " . ($_POST[$key] ?? 'NOT SET'));
            }

            // Detect whether the DB has the day_date_real column (non-destructive check)
            $has_day_date_real = false;
            try {
                $colCheck = $conn->query("SHOW COLUMNS FROM official_time LIKE 'day_date_real'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    $has_day_date_real = true;
                }
            } catch (Exception $ex) {
                error_log('[official_time SAVE] column check failed: ' . $ex->getMessage());
            }
            error_log('[official_time SAVE] has_day_date_real=' . ($has_day_date_real ? '1' : '0'));

            // Save weekly accomplishment first (only if form provided time/task fields)
            $user_id = $_SESSION["user_id"];
            $today = date('Y-m-d');

            if (isset($_POST['time_in']) || isset($_POST['time_out']) || isset($_POST['task_completed'])) {
                // First check if entry exists for today
                $check_sql = "SELECT id FROM weekly_accomplishments
                             WHERE users_user_id = ?
                             AND DATE(date_record) = ?";
           
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("is", $user_id, $today);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
           
                if ($result->num_rows > 0) {
                    // Update existing record
                    $update_sql = "UPDATE weekly_accomplishments
                                 SET time_in = ?,
                                     time_out = ?,
                                     task_completed = ?,
                                     total_hours = TIMESTAMPDIFF(HOUR, CONCAT(date_record, ' ', ?), CONCAT(date_record, ' ', ?)),
                                     total = TIMESTAMPDIFF(HOUR, CONCAT(date_record, ' ', ?), CONCAT(date_record, ' ', ?)),
                                     grand_total = TIMESTAMPDIFF(HOUR, CONCAT(date_record, ' ', ?), CONCAT(date_record, ' ', ?)),
                                     last_updated_at = NOW()
                                 WHERE users_user_id = ?
                                 AND DATE(date_record) = ?";
               
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param("sssssssssss",
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['task_completed'],
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $user_id,
                        $today
                    );
                } else {
                    // Insert new record for today only
                    $insert_sql = "INSERT INTO weekly_accomplishments
                                  (users_user_id, date_record, time_in, time_out, task_completed,
                                   total_hours, total, grand_total, last_updated_at)
                                  VALUES (?, CURDATE(), ?, ?, ?,
                                          TIMESTAMPDIFF(HOUR, CONCAT(CURDATE(), ' ', ?), CONCAT(CURDATE(), ' ', ?)),
                                          TIMESTAMPDIFF(HOUR, CONCAT(CURDATE(), ' ', ?), CONCAT(CURDATE(), ' ', ?)),
                                          TIMESTAMPDIFF(HOUR, CONCAT(CURDATE(), ' ', ?), CONCAT(CURDATE(), ' ', ?)),
                                          NOW())";
               
                    $stmt = $conn->prepare($insert_sql);
                    $stmt->bind_param("isssssssss",
                        $user_id,
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['task_completed'],
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['time_in'],
                        $_POST['time_out'],
                        $_POST['time_in'],
                        $_POST['time_out']
                    );
                }
           
                if (!$stmt->execute()) {
                    throw new Exception("Weekly accomplishment error: " . $stmt->error);
                }
                $stmt->close();
            }
           
            // SAVE OFFICIAL TIMES - store by actual date for the selected ISO week
            // This avoids updating textual 'Mon'/'Tue' rows (which used created_at as the week marker)
            $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            // compute the Monday date for the selected ISO week/year
            try {
                $mondayDt = new DateTime();
                $mondayDt->setISODate($selectedYear, $selectedWeek);
            } catch (Exception $e) {
                // fallback to today if something goes wrong
                $mondayDt = new DateTime();
            }

            // Build the week start/end strings for textual-row lookups
            $startDate = $mondayDt->format('Y-m-d');
            $endDt = clone $mondayDt;
            $endDt->modify('+5 days');
            $endDate = $endDt->format('Y-m-d');

            foreach ($days as $offset => $day) {
                $post_key = "official_" . $day;

                // Always attempt to process every weekday. Use null-coalesce so
                // missing POST keys become empty strings; this ensures Mon..Sat
                // rows are created (or updated) as needed.
                $official_time = trim((string)($_POST[$post_key] ?? ''));

                    // compute the exact date (Y-m-d) for this weekday in the selected ISO week
                    $dayDt = clone $mondayDt;
                    if ($offset > 0) {
                        $dayDt->modify("+{$offset} days");
                    }
                    $dayDateStr = $dayDt->format('Y-m-d');

                    // Determine weekday abbreviation for this date (e.g., MON)
                    $weekdayAbbrev = strtoupper(substr($dayDt->format('D'), 0, 3));

                    // Debug log for each weekday attempt
                    error_log("[official_time SAVE] weekday={$weekdayAbbrev} dayDate={$dayDateStr} posted='{$official_time}' user={$user_id} week={$selectedWeek} year={$selectedYear}");

                    // Choose which DB column to use for exact-date operations
                    $dateColumn = $has_day_date_real ? 'day_date_real' : 'day_date';
                    // If the DB doesn't support day_date_real, store the weekday abbrev into legacy enum
                    $dbDayValue = $has_day_date_real ? $dayDateStr : $weekdayAbbrev;

               
                    $existingExactId = null;
                    $existingTextualId = null; // textual or blank day_date rows
                    if ($has_day_date_real) {
                        $sql1 = "SELECT official_id, created_at FROM official_time WHERE users_user_id = ? AND {$dateColumn} = ? LIMIT 1";
                        $s1 = $conn->prepare($sql1);
                        if ($s1) {
                            $s1->bind_param('is', $user_id, $dbDayValue);
                            if (!$s1->execute()) error_log('[official_time SAVE] sql1 execute failed: ' . $s1->error);
                            $r1 = $s1->get_result();
                            if ($rr = $r1->fetch_assoc()) {
                                $existingExactId = $rr['official_id'];
                                $existingExactCreatedAt = $rr['created_at'] ?? null;
                            }
                            $s1->close();
                        } else {
                            error_log('[official_time SAVE] prepare failed (sql1): ' . $conn->error);
                        }
                    } else {
                        // Legacy enum path: only consider textual matches created in the selected week
                        $sql1 = "SELECT official_id FROM official_time WHERE users_user_id = ? AND day_date = ? AND DATE(created_at) BETWEEN ? AND ? LIMIT 1";
                        $s1 = $conn->prepare($sql1);
                        if ($s1) {
                            $s1->bind_param('isss', $user_id, $dbDayValue, $startDate, $endDate);
                            if (!$s1->execute()) error_log('[official_time SAVE] sql1 (legacy) execute failed: ' . $s1->error);
                            $r1 = $s1->get_result();
                            if ($rr = $r1->fetch_assoc()) $existingExactId = $rr['official_id'];
                            $s1->close();
                        } else {
                            error_log('[official_time SAVE] prepare failed (sql1 legacy): ' . $conn->error);
                        }
                    }

                    // Step 2: if not found, look for textual day rows (e.g., 'MON','Mon') created within the week
                    if ($existingExactId === null) {
                        $sql2 = "SELECT official_id, day_date FROM official_time WHERE users_user_id = ? AND DATE(created_at) BETWEEN ? AND ? LIMIT 10";
                        $s2 = $conn->prepare($sql2);
                        if ($s2) {
                            $s2->bind_param('iss', $user_id, $startDate, $endDate);
                            if (!$s2->execute()) error_log('[official_time SAVE] sql2 execute failed: ' . $s2->error);
                            $r2 = $s2->get_result();
                            while ($rr = $r2->fetch_assoc()) {
                                $dd = trim((string)$rr['day_date']);
                                if ($dd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) {
                                    // textual or blank -> consider textual candidate
                                    $existingTextualId = $rr['official_id'];
                                    break;
                                }
                            }
                            $s2->close();
                        } else {
                            error_log('[official_time SAVE] prepare failed (sql2): ' . $conn->error);
                        }
                    }

                    // Step 3: if no textual candidate found yet, look specifically for blank day_date rows created within the week
                    if ($existingTextualId === null) {
                        $sql3 = "SELECT official_id FROM official_time WHERE users_user_id = ? AND (day_date = '' OR day_date IS NULL) AND DATE(created_at) BETWEEN ? AND ? LIMIT 1";
                        $s3 = $conn->prepare($sql3);
                        if ($s3) {
                            $s3->bind_param('iss', $user_id, $startDate, $endDate);
                            if (!$s3->execute()) error_log('[official_time SAVE] sql3 execute failed: ' . $s3->error);
                            $r3 = $s3->get_result();
                            if ($rr = $r3->fetch_assoc()) $existingTextualId = $rr['official_id'];
                            $s3->close();
                        } else {
                            error_log('[official_time SAVE] prepare failed (sql3): ' . $conn->error);
                        }
                    }

                    error_log('[official_time SAVE] foundExact=' . ($existingExactId ?? 'none') . ' foundTextual=' . ($existingTextualId ?? 'none'));

                    // Decide action based on findings
                    if ($existingExactId !== null) {
                        // Found an exact date row
                        // Defensive: only update exact-date rows for the current week to avoid changing historic data
                        if ($isCurrentWeek) {
                            // Update only the day_time; preserve original created_at so we don't
                            // accidentally change the historical attribution of the row.
                            $update_sql = "UPDATE official_time SET day_time = ? WHERE official_id = ?";
                            $u = $conn->prepare($update_sql);
                            if ($u) {
                                $u->bind_param('si', $official_time, $existingExactId);
                                if (!$u->execute()) {
                                    error_log('[official_time SAVE] update execute failed: ' . $u->error);
                                }
                                $u->close();
                            } else {
                                error_log('[official_time SAVE] prepare failed (update): ' . $conn->error);
                            }
                            error_log("[official_time] Updated existing exact-date official_id=$existingExactId for current week $dayDateStr: $official_time");
                        } else {
                            // Non-current week: exact-date already exists; skip to avoid duplicate
                            error_log("[official_time] Exact-date exists for $dayDateStr (official_id=$existingExactId). Skipping insert to avoid duplicate.");
                        }
                    } else {
                        // No exact-date row found. Insert a new date-specific row.
                        // Insert into the real date column so we can store Y-m-d values
                        // Insert into whichever date column is available; if legacy enum used,
                        // store the weekday abbreviation instead of a full Y-m-d date.
                        $insert_sql = "INSERT INTO official_time (users_user_id, {$dateColumn}, day_time) VALUES (?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param('iss', $user_id, $dbDayValue, $official_time);
                            if (!$insert_stmt->execute()) {
                                error_log('[official_time SAVE] insert execute failed: ' . $insert_stmt->error);
                            }
                            $insert_stmt->close();
                            error_log("Inserted new official time for $dayDateStr: $official_time (textual/blank existingId=" . ($existingTextualId ?? 'none') . ")");
                        } else {
                            error_log('[official_time SAVE] prepare failed (insert): ' . $conn->error);
                        }
                }
            }

            // Add this code to handle company update
            if (isset($_POST['company']) && !empty($_POST['company'])) {
                $updateCompanySql = "UPDATE student_info SET company_name = ? WHERE users_user_id = ?";
                if ($companyStmt = $conn->prepare($updateCompanySql)) {
                    $companyStmt->bind_param("si", $_POST['company'], $user_id);
                    $companyStmt->execute();
                    $companyStmt->close();
                }
            }

            $conn->commit();
            $response["status"] = "success";
            $response["message"] = "✔ Data saved successfully";
            if (!$isCurrentWeek) {
                $response["message"] .= " (Note: Official times can only be edited for current week)";
            }
           
        } catch (Exception $e) {
            $conn->rollback();
            $response["status"] = "error";
            $response["message"] = "❌ Error: " . $e->getMessage();
            error_log("Save form error: " . $e->getMessage());
        }

        echo json_encode($response);
        exit();
    }
}

// ---------- PROFILE UPDATE (multipart POST) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["student_fn"])) {
    $user_id = $_SESSION["user_id"];
   
    $student_fn = trim($_POST["student_fn"]);
    $student_mi = isset($_POST["student_mi"]) ? trim($_POST["student_mi"]) : '';
    $student_ln = trim($_POST["student_ln"]);
    $email = trim($_POST["email"]);
    $student_course = trim($_POST["student_course"]);
    $student_year = trim($_POST["student_year"]);

    // Handle profile picture upload
    $profile_picture = null;
    $profile_picture_type = null;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_extension, $allowed_extensions)) {
            $image_data = file_get_contents($_FILES['profile_picture']['tmp_name']);
            if ($image_data !== false) {
                $profile_picture = $image_data;
                $profile_picture_type = 'image/' . $file_extension;
            }
        }
    }

    // Update users.email
    if ($update_email = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?")) {
        $update_email->bind_param("si", $email, $user_id);
        $update_email->execute();
        $update_email->close();
    }

    // Check/insert/update student_info
    $check_student = $conn->prepare("SELECT student_id FROM student_info WHERE users_user_id = ?");
    $check_student->bind_param("i", $user_id);
    $check_student->execute();
    $check_student->store_result();

    if ($check_student->num_rows > 0) {
        if ($profile_picture !== null && $profile_picture_type !== null) {
            $update_student = $conn->prepare("UPDATE student_info SET student_fn = ?, student_mi = ?, student_ln = ?, student_course = ?, student_year = ?, profile_picture = ?, profile_picture_type = ? WHERE users_user_id = ?");
            $update_student->bind_param("sssssssi", $student_fn, $student_mi, $student_ln, $student_course, $student_year, $profile_picture, $profile_picture_type, $user_id);
        } else {
            $update_student = $conn->prepare("UPDATE student_info SET student_fn = ?, student_mi = ?, student_ln = ?, student_course = ?, student_year = ? WHERE users_user_id = ?");
            $update_student->bind_param("sssssi", $student_fn, $student_mi, $student_ln, $student_course, $student_year, $user_id);
        }
        $update_student->execute();
        $update_student->close();
    } else {
        $insert_student = $conn->prepare("INSERT INTO student_info (users_user_id, student_fn, student_mi, student_ln, student_course, student_year, profile_picture, profile_picture_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_student->bind_param("isssssss", $user_id, $student_fn, $student_mi, $student_ln, $student_course, $student_year, $profile_picture, $profile_picture_type);
        $insert_student->execute();
        $insert_student->close();
    }
    $check_student->close();

    // Mark account as no longer "new" after the user has filled out their profile
    if ($markNotNew = $conn->prepare("UPDATE users SET is_new = 0 WHERE user_id = ?")) {
        $markNotNew->bind_param("i", $user_id);
        $markNotNew->execute();
        $markNotNew->close();
    }

    header("Location: homepage.php?updated=1");
    exit();
}

// ---------- ON PAGE LOAD: prefetch ----------
$user_id = $_SESSION["user_id"];
$date_today = date("Y-m-d");

// Get today's existing record
$existing = null;
if ($pref = $conn->prepare("SELECT time_in, time_out, task_completed FROM weekly_accomplishments WHERE users_user_id = ? AND date_record = ? LIMIT 1")) {
    $pref->bind_param("is", $user_id, $date_today);
    $pref->execute();
    $prefRes = $pref->get_result();
    if ($prefRes && $prefRes->num_rows > 0) {
        $existing = $prefRes->fetch_assoc();
    }
    $pref->close();
}

// Get user profile data
$user_profile = null;
$user_email = null;
$username = null;

if ($email_query = $conn->prepare("SELECT email, username FROM users WHERE user_id = ?")) {
    $email_query->bind_param("i", $user_id);
    $email_query->execute();
    $email_result = $email_query->get_result();
    if ($email_result && $email_result->num_rows > 0) {
        $user_data = $email_result->fetch_assoc();
        $user_email = $user_data['email'];
        $username = $user_data['username'];
    }
    $email_query->close();
}

if ($student_query = $conn->prepare("SELECT student_fn, student_mi, student_ln, student_course, student_year FROM student_info WHERE users_user_id = ?")) {
    $student_query->bind_param("i", $user_id);
    $student_query->execute();
    $student_result = $student_query->get_result();
    if ($student_result && $student_result->num_rows > 0) {
        $user_profile = $student_result->fetch_assoc();
    }
    $student_query->close();
}

// Fetch official times
$official_times = [
    'MON' => '', 'TUE' => '', 'WED' => '', 'THU' => '', 'FRI' => '', 'SAT' => ''
];

$current_week = date('W');
$current_year = date('Y');

// compute Monday..Saturday date range for current week
try {
    $mondayDt = new DateTime();
    $mondayDt->setISODate($current_year, $current_week);
} catch (Exception $e) {
    $mondayDt = new DateTime();
}
$startDate = $mondayDt->format('Y-m-d');
$endDt = clone $mondayDt;
$endDt->modify('+5 days');
$endDate = $endDt->format('Y-m-d');

if ($oft = $conn->prepare("SELECT day_date, day_time, created_at FROM official_time WHERE users_user_id = ? AND ((day_date BETWEEN ? AND ?) OR (created_at BETWEEN ? AND ?)) ORDER BY official_id DESC")) {
    $oft->bind_param("issss", $user_id, $startDate, $endDate, $startDate, $endDate);
    $oft->execute();
    $result = $oft->get_result();
    while ($row = $result->fetch_assoc()) {
        $raw = $row['day_date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $day = strtoupper(date('D', strtotime($raw)));
        } else {
            // If textual day stored, still include it when created_at falls in the week
            $day = strtoupper(substr($raw, 0, 3));
        }
        if (array_key_exists($day, $official_times)) {
            $official_times[$day] = htmlspecialchars($row['day_time']);
        }
    }
    $oft->close();
}

// Fetch company and training period
$company_name = '';
if ($infoq = $conn->prepare("SELECT company_name FROM student_info WHERE users_user_id = ? LIMIT 1")) {
    $infoq->bind_param("i", $user_id);
    $infoq->execute();
    $infoRes = $infoq->get_result();
    if ($infoRes && $infoRes->num_rows > 0) {
        $row = $infoRes->fetch_assoc();
        $company_name = $row['company_name'] ?? '';
    }
    $infoq->close();
}

// Check profile picture
$has_profile_picture = false;
$current_picture_type = '';
if ($pic_check = $conn->prepare("SELECT profile_picture, profile_picture_type FROM student_info WHERE users_user_id = ?")) {
    $pic_check->bind_param("i", $user_id);
    $pic_check->execute();
    $pic_result = $pic_check->get_result();
    if ($pic_result && $pic_result->num_rows > 0) {
        $pic_data = $pic_result->fetch_assoc();
        if (!empty($pic_data['profile_picture'])) {
            $has_profile_picture = true;
            $current_picture_type = $pic_data['profile_picture_type'];
        }
    }
    $pic_check->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Quezon City University - OJT Activity Log</title>
        <link rel="stylesheet" href="css/homepage.css">
        <!-- Font Awesome for icons in the sidebar -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    /* Floating Printable View button */
                    .floating-print-btn {
                        position: fixed;
                        right: 24px;
                        bottom: 24px;
                        display: none; /* hidden by default */
                        align-items: center;
                        gap: 10px;
                        padding: 10px 14px;
                        background: #2c5e8f;
                        color: #fff;
                        border: none;
                        border-radius: 28px;
                        box-shadow: 0 10px 24px rgba(0,0,0,0.2);
                        cursor: pointer;
                        z-index: 5000; /* keep above overlays */
                        font-weight: 600;
                        letter-spacing: .2px;
                        user-select: none;
                        outline: none;
                        transition: box-shadow 0.2s ease;
                        will-change: transform;
                    }
                    .floating-print-btn img {
                        width: 20px;
                        height: 20px;
                        object-fit: contain;
                        filter: brightness(0) invert(1);
                    }
                    .floating-print-btn:hover { box-shadow: 0 14px 30px rgba(0,0,0,0.25); }
                    .floating-print-btn:active { box-shadow: 0 10px 24px rgba(0,0,0,0.2); }
                    @media (max-width: 600px) {
                        .floating-print-btn { right: 16px; bottom: 16px; padding: 9px 12px; }
                    }
                </style>
</head>
<body>
  <div class="main-layout">
        <!-- Hamburger removed -->

    <!-- Edit Profile Modal (placed outside userProfileModal to avoid stacking) -->
    <div class="modal" id="editProfileModal" style="display:none;">
        <div class="modal-content edit-profile-modal">
            <span class="close" onclick="closeEditProfileModal()">&times;</span>
            <h2>Edit Profile</h2>
            <form id="editProfileForm" method="POST" action="homepage.php" enctype="multipart/form-data">
                <div class="form-group file-input">
                    <img src="get_profile_image.php" alt="Current Profile" class="current-image" id="currentProfileImage">
                    <label for="profile_picture">Choose Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" accept="image/*" <?= $has_profile_picture ? '' : 'required' ?> onchange="updateFileName(this)">
                    <div class="error-notification" id="profileImageError"></div>
                    <div class="file-name" id="fileName">
                        <?php
                        if ($has_profile_picture) {
                            $extension = str_replace('image/', '', $current_picture_type);
                            echo "Current profile picture.$extension";
                        } else {
                            echo 'No file chosen';
                        }
                        ?>
                    </div>
                </div>
                <div class="form-group"><label for="student_fn">First Name:</label><input type="text" id="student_fn" name="student_fn" value="<?= htmlspecialchars($user_profile['student_fn'] ?? '') ?>" required></div>
                <div class="form-group"><label for="student_mi">Middle Initial:</label><input type="text" id="student_mi" name="student_mi" value="<?= htmlspecialchars($user_profile['student_mi'] ?? '') ?>" maxlength="1"></div>
                <div class="form-group"><label for="student_ln">Last Name:</label><input type="text" id="student_ln" name="student_ln" value="<?= htmlspecialchars($user_profile['student_ln'] ?? '') ?>" required></div>
                <div class="form-group"><label for="email">Email:</label><input type="email" id="email" name="email" value="<?= htmlspecialchars($user_email ?? '') ?>" required></div>
                <div class="form-group"><label for="student_course">Course:</label><input type="text" id="student_course" name="student_course" value="<?= htmlspecialchars($user_profile['student_course'] ?? '') ?>" required></div>
                <div class="form-group"><label for="student_year">Year:</label>
                  <select id="student_year" name="student_year" required>
                    <option value="1st Year" <?= ($user_profile['student_year'] ?? '') == '1st Year' ? 'selected' : '' ?>>1st Year</option>
                    <option value="2nd Year" <?= ($user_profile['student_year'] ?? '') == '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3rd Year" <?= ($user_profile['student_year'] ?? '') == '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                    <option value="4th Year" <?= ($user_profile['student_year'] ?? '') == '4th Year' ? 'selected' : '' ?>>4th Year</option>
                  </select>
                </div>
                <div class="modal-buttons">
                  <button type="submit" class="save-btn" id="editProfileSaveBtn">Save Changes</button>
                  <button type="button" class="cancel-btn" onclick="closeEditProfileModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Left Sidebar (compact, expands on hover/click) -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="img/qcu.png" alt="QCU Logo" class="sidebar-qcu-logo">
            <span class="sidebar-qcu-label">Quezon City University</span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="#" class="active" id="homeButton"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
            <li><a href="#" id="fillButton"><i class="fas fa-edit"></i> <span>Fill Out</span></a></li>
            <li><a href="#" id="weekButton"><i class="fas fa-pen"></i> <span>Week Select</span></a></li>
            <li><a href="#" id="scheduleButton"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
            <li class="logout-item"><a href="#logout" onclick="openModal()"><i class="fas fa-sign-out-alt"></i> <span>Log Out</span></a></li>
        </ul>
    </div>
    <div class="overlay" id="sidebarOverlay" style="display:none;"></div>

    

        <!-- Scheduling Modal (empty content for now) -->
        <div class="modal" id="scheduleModal">
            <div class="modal-content form-container">
                <!-- X removed per user request -->
                <h2>Scheduling Form</h2>
                <div id="scheduleModalBody">
                    <div class="form-section">
                        <div class="section-title">Quick Schedule Setup</div>
                        <div class="quick-actions">
                            <button class="quick-action-btn" id="same-all-days">✓ Same schedule all days</button>
                            <button class="quick-action-btn" id="weekdays-only">📅 Weekdays only</button>
                            <button class="quick-action-btn" id="weekend-only">🌞 Weekend only</button>
                            <button class="quick-action-btn" id="clear-all">🗑️ Clear all</button>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">Daily Schedule</div>
                        <div class="days-container">
                            <?php foreach (['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday'] as $dayKey => $dayLabel): ?>
                                <div class="day-group" id="<?= $dayKey ?>">
                                    <div class="day-label"><?= $dayLabel ?></div>
                                    <div class="time-inputs">
                                        <div class="time-input">
                                            <label>Start Time</label>
                                            <select id="official-<?= $dayKey ?>-am" class="official-select start-hour">
                                                <option value="">-- Select --</option>
                                            </select>
                                        </div>
                                        <div class="time-input">
                                            <label>End Time</label>
                                            <select id="official-<?= $dayKey ?>-pm" class="official-select end-hour">
                                                <option value="">-- Select --</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="time-display" id="<?= $dayKey ?>-display">Not set</div>
                                    <!-- hidden input preserves server field name/ID expected by PHP -->
                                    <input type="hidden" id="official-<?= $dayKey ?>" name="official_<?= $dayKey ?>" value="<?= htmlspecialchars($official_times[strtoupper($dayKey)] ?? '') ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top:12px;">
                        <div class="form-group full-width">
                            <label for="company">Company:</label>
                            <input type="text" id="company" name="company" value="<?= htmlspecialchars($company_name) ?>">
                        </div>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;">
                    <button class="submit-btn primary-save" id="scheduleSubmit">Submit</button>
                    <button class="btn cancel-red-outline" onclick="closeScheduleModal()">Close</button>
                </div>
            </div>
        </div>

    <!-- Main Content Area -->
    <div class="main-content">
      <div class="content-header">
        <h1>OJT Activity Log</h1>
                <!-- new-entry-buttons moved to sidebar for vertical layout -->
                <div class="header-profile">
                    <img src="get_profile_image.php" alt="Profile" class="header-profile-img" onerror="this.src='img/user-edit.png'">
                    <div class="header-profile-info">
                        <button id="headerFullnameBtn" class="header-fullname-btn" type="button" aria-haspopup="dialog" aria-controls="userProfileModal">
                            <?php
                                $fn = htmlspecialchars($user_profile['student_fn'] ?? '');
                                $mi = htmlspecialchars($user_profile['student_mi'] ?? '');
                                $ln = htmlspecialchars($user_profile['student_ln'] ?? '');
                                $full = trim($fn . ($mi ? ' ' . $mi : '') . ' ' . $ln);
                                // Show full name if available, otherwise show username as fallback
                                echo $full ?: htmlspecialchars($username ?? 'User');
                            ?>
                        </button>
                        <div class="header-email"><?= htmlspecialchars($user_email ?? $user_profile['student_email'] ?? '') ?></div>
                    </div>
                </div>
      </div>

      <div class="content-body">
        <?php // top-level success message removed per user request ?>

        <div id="viewform-container"></div>
      </div>
    </div>
  </div>

    <!-- Floating button (no function yet) -->
    <button id="printableViewBtn" class="floating-print-btn" type="button" title="Printable View">
        <img src="img/printer.png" alt="Printer"> Printable View
    </button>

    <!-- User Profile Modal -->
    <div class="modal" id="userProfileModal" style="display:none;">
        <div class="modal-content profile-modal" role="dialog" aria-labelledby="userProfileTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="userProfileTitle">User Profile</h2>
                <button class="close-button" onclick="closeUserProfileModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="details-section">
                    <div class="profile-section">
                        <div class="profile-picture-container">
                            <img src="get_profile_image.php" alt="Profile" class="profile-picture" onerror="this.src='img/user-edit.png'">
                            <!-- Edit overlay button placed over the profile picture -->
                            <button id="userProfileEditBtn" class="profile-edit-overlay" type="button" aria-haspopup="dialog" aria-controls="editProfileModal" title="Edit Profile">
                                <img src="img/user-edit.png" alt="Edit" />
                            </button>
                        </div>
                        <div class="profile-info">
                            <h3 class="user-name"><?php 
                                $fullName = trim(($user_profile['student_fn'] ?? '') . ' ' . ($user_profile['student_mi'] ?? '') . ' ' . ($user_profile['student_ln'] ?? ''));
                                echo htmlspecialchars($fullName ?: ($username ?? 'User'));
                            ?></h3>
                            <p class="user-email"><?= htmlspecialchars($user_email ?? $user_profile['student_email'] ?? '') ?></p>
                        </div>
                    </div>
                    <div class="detail-row"><div class="detail-label">First Name</div><div class="detail-value"><?= htmlspecialchars($user_profile['student_fn'] ?? '') ?></div></div>
                    <div class="detail-row"><div class="detail-label">Last Name</div><div class="detail-value"><?= htmlspecialchars($user_profile['student_ln'] ?? '') ?></div></div>
                    <div class="detail-row"><div class="detail-label">Middle Initial</div><div class="detail-value"><?= htmlspecialchars($user_profile['student_mi'] ?? '') ?></div></div>
                    <div class="detail-row"><div class="detail-label">Email Account</div><div class="detail-value"><?= htmlspecialchars($user_email ?? $user_profile['student_email'] ?? '') ?></div></div>
                    <div class="detail-row"><div class="detail-label">Course</div><div class="detail-value"><?= htmlspecialchars($user_profile['student_course'] ?? '') ?></div></div>
                    <div class="detail-row"><div class="detail-label">Year</div><div class="detail-value"><?= htmlspecialchars($user_profile['student_year'] ?? '') ?></div></div>
                </div>
            </div>
        </div>
        <!-- editProfileModal relocated below to avoid stacking/modals inside modals -->
    </div>

    <!-- Logout Modal -->
<div class="modal" id="logoutModal">
    <div class="modal-content logout-modal">
        <h2 class="logout-title">Are you sure you want to logout?</h2>
        <div class="modal-buttons logout-confirm">
            <button class="btn cancel-outline" onclick="closeModal()">Cancel</button>
            <button class="btn confirm-filled" onclick="logout()">Confirm</button>
        </div>
    </div>
</div>

    <!-- AFK Modal -->
<div class="modal" id="afkModal">
    <div class="modal-content logout-modal">
        <h2 class="logout-title">Are you still there?</h2>
        <p style="text-align: center; margin: 15px 0; color: #666;">You've been inactive for a while.</p>
        <div class="modal-buttons logout-confirm">
            <button class="btn confirm-filled" onclick="goBackToLogin()">Go back to Login</button>
        </div>
    </div>
</div>

  <!-- Week Select Modal -->
  <div class="modal" id="weekSelectModal">
    <div class="modal-content">
      <h2>Select Week to View</h2>
      <div class="week-select">
        <select id="weekDropdown" onchange="selectWeekDropdown(this.value)">
          <option value="" disabled selected>-- Select Week --</option>
          <?php
          // Compute initial week range from users.created_at when available.
          // If not available, fall back to any server-provided $initialRangeStart, then to earliest recorded date, then to project start.
          $projectStart = '2025-09-08';
          $startDate = $projectStart;

          // Initialize server-side variables used by JS if not already set
          if (!isset($initialRangeStart)) $initialRangeStart = null;
          if (!isset($initialRangeEnd)) $initialRangeEnd = null;
          if (!isset($initialWeek)) $initialWeek = null;
          if (!isset($initialYear)) $initialYear = null;

          if (isset($conn) && isset($_SESSION['user_id']) && $_SESSION['user_id']) {
              try {
                  $uId = intval($_SESSION['user_id']);
                  $uSql = "SELECT created_at FROM users WHERE user_id = ? LIMIT 1";
                  if ($uStmt = $conn->prepare($uSql)) {
                      $uStmt->bind_param('i', $uId);
                      $uStmt->execute();
                      $uRes = $uStmt->get_result();
                      if ($uRow = $uRes->fetch_assoc()) {
                          $createdAt = !empty($uRow['created_at']) ? $uRow['created_at'] : null;
                          if ($createdAt) {
                              try {
                                  $dt = new DateTime($createdAt, new DateTimeZone('Asia/Manila'));
                                  // Monday of that ISO week
                                  $dt->modify('monday this week');
                                  $initialRangeStart = $dt->format('Y-m-d');

                                  // Compute Saturday of that week for end range
                                  $dtEnd = clone $dt;
                                  $dtEnd->modify('+5 days');
                                  $initialRangeEnd = $dtEnd->format('Y-m-d');

                                  $initialWeek = intval($dt->format('W'));
                                  $initialYear = intval($dt->format('Y'));
                              } catch (Exception $e) {
                                  // ignore and fallback below
                              }
                          }
                      }
                      $uStmt->close();
                  }

                  // If still no $initialRangeStart, try earliest weekly_accomplishments for this user
                  if (!$initialRangeStart) {
                      $minSql = "SELECT MIN(date_record) AS min_date FROM weekly_accomplishments WHERE users_user_id = ?";
                      if ($minStmt = $conn->prepare($minSql)) {
                          $minStmt->bind_param('i', $uId);
                          $minStmt->execute();
                          $minRes = $minStmt->get_result();
                          if ($minRow = $minRes->fetch_assoc()) {
                              if (!empty($minRow['min_date'])) {
                                  $initialRangeStart = substr($minRow['min_date'], 0, 10);
                                  $dtmp = new DateTime($initialRangeStart);
                                  $dtmp->modify('monday this week');
                                  $initialRangeStart = $dtmp->format('Y-m-d');
                                  $initialWeek = intval($dtmp->format('W'));
                                  $initialYear = intval($dtmp->format('Y'));
                                  $dtmpEnd = clone $dtmp;
                                  $dtmpEnd->modify('+5 days');
                                  $initialRangeEnd = $dtmpEnd->format('Y-m-d');
                              }
                          }
                          $minStmt->close();
                      }
                  }
              } catch (Exception $e) {
                  // fall back to project start
              }
          }

          // final fallbacks
          if ($initialRangeStart) {
              $startDate = $initialRangeStart;
          } else {
              // attempt to use any pre-existing server-provided start value
              $startDate = isset($initialRangeStart) && $initialRangeStart ? $initialRangeStart : $projectStart;
          }
          $today = new DateTime('now', new DateTimeZone('Asia/Manila'));
          $thisWeekStart = clone $today;
          $thisWeekStart->modify('monday this week');
          $weekCount = 0;
          $weekStart = clone $thisWeekStart;

          // If the server provided an explicit initial week (for new accounts),
          // compute the weekStart for that date so the dropdown includes it.
          if (isset($initialRangeStart) && $initialRangeStart) {
              $earliest = new DateTime($initialRangeStart);
              // ensure we compare weeks aligned to Monday
              $earliest->modify('monday this week');
          } else {
              $earliest = new DateTime($startDate);
          }

          while ($weekStart >= $earliest) {
              $weekEnd = clone $weekStart;
              $weekEnd->modify('+5 days');
              $dateRange = $weekStart->format('M d') . ' - ' . $weekEnd->format('M d, Y');
             
              if ($weekCount === 0) {
                  $label = "This Week (" . $dateRange . ")";
              } elseif ($weekCount === 1) {
                  $label = "Last Week (" . $dateRange . ")";
              } else {
                  $label = $weekCount . " Weeks Ago (" . $dateRange . ")";
              }
              ?>
              <?php
              // Mark the option selected if it matches the initial week passed to JS
              $optWeek = $weekStart->format('W');
              $optYear = $weekStart->format('Y');
              $selected = ($optWeek == $initialWeek && $optYear == $initialYear) ? 'selected' : '';
              ?>
              <option value="<?= $optWeek ?>"
                      data-range="<?= $dateRange ?>"
                      data-year="<?= $optYear ?>" <?= $selected ?>>
                  <?= $label ?>
              </option>
              <?php
              $weekStart->modify('-1 week');
              $weekCount++;
          }
          ?>
        </select>
      </div>
      <div style="display:flex; justify-content:center; margin-top:20px;">
        <button class="btn cancel-red-outline" onclick="closeWeekModal()">Exit</button>
      </div>
    </div>
  </div>

  <!-- Fill Out Form Modal -->
  <div class="modal" id="fillOutModal">
        <div class="modal-content form-container">
            <!-- X removed per request; use Close button below -->
      <h2>Fill Out Form</h2>
      <div id="currentDateTime"><?php echo date('l, F j, Y | h:i:s A'); ?></div>

      <form id="accomplishmentForm" method="post" onsubmit="return false;">
                <!-- Official Time and Company moved to Scheduling modal -->

                <!-- Training period fields removed per user request -->

        <div class="form-row">
          <div class="form-group">
            <label for="time-in">Time-In:</label>
            <input type="time" id="time-in" name="time_in" required value="<?= htmlspecialchars($existing['time_in'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="time-out">Time-Out:</label>
            <input type="time" id="time-out" name="time_out" required value="<?= htmlspecialchars($existing['time_out'] ?? '') ?>">
          </div>
        </div>
                <!-- Total hours computed display removed per request -->

        <div class="form-row">
          <div class="form-group full-width">
            <label for="task">Task Assigned and Completed:</label>
            <textarea id="task" name="task_completed" rows="4" required><?= htmlspecialchars($existing['task_completed'] ?? '') ?></textarea>
          </div>
        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;">
                            <button type="submit" id="submit-button" class="submit-btn primary-save">Submit</button>
                            <button class="btn cancel-red-outline" onclick="closeFillModal()">Close</button>
                        </div>
      </form>
      <p id="formMessage"></p>
    </div>
  </div>

  <script>
    // Enhanced Homepage Animations and Live Effects
    document.addEventListener('DOMContentLoaded', function() {
        initializePageAnimations();
        setupEnhancedInteractions();
        initializeCurrentWeekRange();
    });

    // Initialize page loading animations - Faster speeds
    function initializePageAnimations() {
        // Animate page elements on load with faster speeds
        const animatedElements = [
            { selector: '.sidebar', delay: 0, animation: 'slideInLeft' },
            { selector: '.content-header', delay: 100, animation: 'slideInRight' },
            { selector: '.content-body', delay: 200, animation: 'fadeInUp' },
            { selector: '#viewform-container', delay: 300, animation: 'scaleIn' }
        ];

        animatedElements.forEach(({ selector, delay, animation }) => {
            const element = document.querySelector(selector);
            if (element) {
                element.style.opacity = '0';
                element.style.animation = 'none';
                
                setTimeout(() => {
                    element.style.animation = `${animation} 0.4s ease-out forwards`;
                }, delay);
            }
        });

        // Add staggered animation for sidebar menu items - Faster
        const menuItems = document.querySelectorAll('.sidebar-menu li');
        menuItems.forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateX(-20px)';
            
            setTimeout(() => {
                item.style.transition = 'all 0.3s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateX(0)';
            }, 400 + (index * 50));
        });
    }

    // Setup enhanced interactions
    function setupEnhancedInteractions() {
        // Enhanced sidebar hover effects
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.addEventListener('mouseenter', function() {
                this.style.boxShadow = '6px 0 40px rgba(44, 94, 143, 0.4)';
                this.style.transform = 'translateX(2px)';
            });
            
            sidebar.addEventListener('mouseleave', function() {
                this.style.boxShadow = '';
                this.style.transform = 'translateX(0)';
            });
        }

        // Enhanced button interactions
        const buttons = document.querySelectorAll('button, .btn');
        buttons.forEach(button => {
            // Skip modal buttons to prevent position changes
            if (button.closest('#userProfileModal') || 
                button.classList.contains('close-button') || 
                button.classList.contains('profile-edit-overlay') ||
                button.id === 'printableViewBtn') {
                return;
            }

            // Add ripple effect
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255, 255, 255, 0.5);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.3s linear;
                    pointer-events: none;
                    z-index: 1;
                `;
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 300);
            });

            // Enhanced hover effects
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 8px 25px rgba(0, 0, 0, 0.2)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '';
            });
        });

        // Enhanced input field interactions
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.style.transform = 'scale(1.02)';
                this.style.boxShadow = '0 0 20px rgba(90, 155, 213, 0.3)';
                this.style.borderColor = 'var(--primary-main)';
                
                // Add floating label animation if needed
                const label = this.previousElementSibling;
                if (label && label.tagName === 'LABEL') {
                    label.style.color = 'var(--primary-main)';
                    label.style.transform = 'translateY(-2px)';
                }
            });
            
            input.addEventListener('blur', function() {
                this.style.transform = 'scale(1)';
                this.style.boxShadow = '';
                this.style.borderColor = '';
                
                const label = this.previousElementSibling;
                if (label && label.tagName === 'LABEL') {
                    label.style.color = '';
                    label.style.transform = 'translateY(0)';
                }
            });
            
            // Add typing animation
            input.addEventListener('input', function() {
                this.style.animation = 'inputGlow 0.3s ease';
                setTimeout(() => {
                    this.style.animation = '';
                }, 300);
            });
        });

        // Add scroll animations
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeInUp 0.3s ease-out forwards';
                    entry.target.style.opacity = '1';
                }
            });
        }, { threshold: 0.1 });

        // Observe elements that should animate on scroll
        const observeElements = document.querySelectorAll('.form-container, .stat-card, .chart-container');
        observeElements.forEach(el => {
            el.style.opacity = '0';
            observer.observe(el);
        });
    }

    // Enhanced modal animations - Faster speeds
    function showModalWithAnimation(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.style.display = 'flex';
        modal.style.opacity = '0';
        modal.style.backdropFilter = 'blur(0px)';
        
        requestAnimationFrame(() => {
            modal.style.transition = 'all 0.15s ease';
            modal.style.opacity = '1';
            modal.style.backdropFilter = 'blur(8px)';
            
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.transform = 'scale(0.8) translateY(-30px)';
                modalContent.style.opacity = '0';
                
                setTimeout(() => {
                    modalContent.style.transition = 'all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)';
                    modalContent.style.transform = 'scale(1) translateY(0)';
                    modalContent.style.opacity = '1';
                }, 30);
            }
        });
    }

    function hideModalWithAnimation(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        const modalContent = modal.querySelector('.modal-content');
        if (modalContent) {
            modalContent.style.transition = 'all 0.15s ease';
            modalContent.style.transform = 'scale(0.8) translateY(-20px)';
            modalContent.style.opacity = '0';
        }
        
        modal.style.transition = 'all 0.15s ease';
        modal.style.opacity = '0';
        modal.style.backdropFilter = 'blur(0px)';
        
        setTimeout(() => {
            modal.style.display = 'none';
        }, 200);
    }

    // Add CSS animations
    const animationStyles = document.createElement('style');
    animationStyles.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        @keyframes inputGlow {
            0%, 100% { 
                box-shadow: 0 0 5px rgba(90, 155, 213, 0.3); 
            }
            50% { 
                box-shadow: 0 0 20px rgba(90, 155, 213, 0.6); 
            }
        }
        
        .animate-on-hover:hover {
            transform: translateY(-1px);
            transition: transform 0.1s ease;
        }
        
        .pulse-on-click {
            transform: scale(0.98);
            transition: transform 0.1s ease;
        }
        
        @keyframes buttonPress {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(0.95); }
        }
        
        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmerEffect 1.5s infinite;
        }
        
        .success-flash {
            animation: successFlash 0.6s ease;
        }
        
        @keyframes successFlash {
            0%, 100% { background-color: transparent; }
            50% { background-color: rgba(40, 167, 69, 0.2); }
        }
    `;
    document.head.appendChild(animationStyles);

    // Global variables to track current week state
    let currentWeekState = {
        week: <?= intval($initialWeek) ?>,
        year: <?= intval($initialYear) ?>,
        range: '',
        isCurrentWeek: true
    };

    // If server provided an explicit range start/end (for new users), expose it
    const serverWeekRange = {
        start: <?= isset($initialRangeStart) ? json_encode($initialRangeStart) : 'null' ?>,
        end: <?= isset($initialRangeEnd) ? json_encode($initialRangeEnd) : 'null' ?>
    };

    // Initialize current week range on page load
    function initializeCurrentWeekRange() {
        // If server supplied an explicit week range (start/end as Y-m-d), use it
        if (serverWeekRange && serverWeekRange.start && serverWeekRange.end) {
            const m = new Date(serverWeekRange.start + 'T00:00:00');
            const s = new Date(serverWeekRange.end + 'T00:00:00');
            currentWeekState.range = `${m.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${s.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
            return;
        }

        const today = new Date();
        const monday = new Date(today);
        monday.setDate(today.getDate() - (today.getDay() - 1));
        const saturday = new Date(monday);
        saturday.setDate(monday.getDate() + 5);
       
        currentWeekState.range = `${monday.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric'
        })} - ${saturday.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        })}`;
    }

    // Update official time fields based on current week status
    function updateOfficialTimeFields(isCurrentWeek) {
        const officialTimeGrid = document.getElementById('officialTimeGrid');
        const inputs = officialTimeGrid.querySelectorAll('input');
       
        // Always enable editing for official times
        inputs.forEach(input => {
            input.disabled = false;
            input.parentElement.classList.remove('readonly');
            input.style.backgroundColor = '';
            input.style.cursor = '';
            input.style.opacity = '';
        });
       
        currentWeekState.isCurrentWeek = isCurrentWeek;
    }

    // --- FillOut modal time helpers ------------------------------------
    function parseTimeToMinutes(t) {
        if (!t || t.indexOf(':') === -1) return null;
        const parts = t.split(':').map(x => parseInt(x, 10));
        if (isNaN(parts[0]) || isNaN(parts[1])) return null;
        return parts[0] * 60 + parts[1];
    }

    function minutesToTimeString(minutes) {
        minutes = ((minutes % (24*60)) + (24*60)) % (24*60); // normalize
        const hh = Math.floor(minutes / 60);
        const mm = minutes % 60;
        return String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
    }

    function formatDurationDisplay(totalMinutes) {
        if (totalMinutes === null || isNaN(totalMinutes)) return '0:00';
        const capped = totalMinutes >= 480; // 8 hours
        const minutes = Math.min(totalMinutes, 480);
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return (capped ? '8:00' : (h + ':' + String(m).padStart(2, '0')));
    }


    // Before submitting, normalize time-out when needed so server TIMESTAMPDIFF(HOUR,...) stores correct capped value.
    // If computed duration >= 8 hours, set time-out = time-in + 8:00. Also normalize overnight by allowing next-day times.
    function enforceTimesBeforeSubmit() {
        const timeInEl = document.getElementById('time-in');
        const timeOutEl = document.getElementById('time-out');
        const displayEl = document.getElementById('computedTotal');
        // displayEl is optional; we still need time inputs
        if (!timeInEl || !timeOutEl) return;

        const tin = parseTimeToMinutes(timeInEl.value);
        const tout = parseTimeToMinutes(timeOutEl.value);
        if (tin === null || tout === null) return;

        let adjustedTout = tout;
        if (adjustedTout <= tin) adjustedTout = tout + 24*60; // next day

        const duration = adjustedTout - tin;

        if (duration >= 480) {
            // set time-out to exactly time-in + 8 hours (mod 24h) so DB TIMESTAMPDIFF(HOUR,...) yields 8
            const newTout = tin + 480;
            timeOutEl.value = minutesToTimeString(newTout);
            // update display to 8:00 if present
            if (displayEl) {
                displayEl.textContent = '8:00';
                displayEl.dataset.duration = String(480);
                displayEl.dataset.capped = '1';
            }
        } else {
            // ensure display shows normalized duration (handles overnight)
            if (displayEl) {
                displayEl.textContent = formatDurationDisplay(duration);
                displayEl.dataset.duration = String(duration);
                displayEl.dataset.capped = '0';
            }
        }
    }

    // Note: live computed total display removed per user request. Enforcement still runs on submit.

    // Helper: parse stored official string into {am: 'HH:MM', pm: 'HH:MM'} (values in 24h time for <input[type=time])
    function parseOfficialString(raw) {
        // Expecting formats like '8:00 am - 5:00 pm' or '08:00 - 17:00' or '---'
        if (!raw || raw.trim() === '' || raw.trim() === '---') return {am: '', pm: ''};
        const parts = raw.split('-').map(s => s.trim());
        if (parts.length >= 2) {
            const am = to24HourValue(parts[0]);
            const pm = to24HourValue(parts[1]);
            return {am: am || '', pm: pm || ''};
        }
        return {am: '', pm: ''};
    }

    // Helper: combine AM/PM picker values (HH:MM) into stored format 'h:mm am - h:mm pm' if possible
    function formatOfficialString(amVal, pmVal) {
        if ((!amVal || amVal.trim() === '') && (!pmVal || pmVal.trim() === '')) return '';
        const amText = amVal ? to12HourText(amVal) : '';
        const pmText = pmVal ? to12HourText(pmVal) : '';
        if (amText && pmText) return `${amText} - ${pmText}`;
        if (amText) return `${amText} - `;
        return ` - ${pmText}`;
    }

    // Convert strings like '8:00 am' or '08:00' to 'HH:MM' 24-hour format for <input type=time>
    function to24HourValue(str) {
        if (!str) return '';
        str = str.trim();
        // if already HH:MM (24h), return
        if (/^\d{1,2}:\d{2}$/.test(str)) {
            const parts = str.split(':');
            let hh = parseInt(parts[0], 10);
            const mm = parts[1].padStart(2, '0');
            if (hh < 10) hh = '0' + hh;
            return `${hh}:${mm}`;
        }
        // if like '8:00 am' or '5:00 pm'
        const m = str.match(/(\d{1,2}:\d{2})\s*(am|pm)/i);
        if (m) {
            let hh = parseInt(m[1].split(':')[0], 10);
            const mm = m[1].split(':')[1];
            const ampm = m[2].toLowerCase();
            if (ampm === 'pm' && hh !== 12) hh = hh + 12;
            if (ampm === 'am' && hh === 12) hh = 0;
            const hhStr = hh < 10 ? '0' + hh : '' + hh;
            return `${hhStr}:${mm}`;
        }
        return '';
    }

    // Convert 'HH:MM' (24h) into 'h:mm am/pm'
    function to12HourText(hhmm) {
        if (!hhmm) return '';
        hhmm = hhmm.toString().trim();
        // If input already contains am/pm, normalize and return
        const m = hhmm.match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/i);
        if (m) {
            let hh = parseInt(m[1], 10);
            const mm = m[2];
            const ampm = m[3].toUpperCase();
            // Convert hour to 12-hour display
            let displayHour = hh % 12; if (displayHour === 0) displayHour = 12;
            return `${displayHour}:${mm} ${ampm}`;
        }
        // If it's plain 24h 'HH:MM'
        if (/^\d{1,2}:\d{2}$/.test(hhmm)) {
            const parts = hhmm.split(':');
            let hh = parseInt(parts[0], 10);
            const mm = parts[1];
            const ampm = hh >= 12 ? 'PM' : 'AM';
            let displayHour = hh % 12; if (displayHour === 0) displayHour = 12;
            return `${displayHour}:${mm} ${ampm}`;
        }
        // fallback: return original
        return hhmm;
    }

    // --- Schedule modal helpers (populate selects and quick-action buttons) ---
    function generateTimeOptions12() {
        const times = ['Not Working'];
        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += 30) {
                let displayHour = h % 12; if (displayHour === 0) displayHour = 12;
                const ampm = h < 12 ? 'AM' : 'PM';
                const minute = m === 0 ? '00' : String(m).padStart(2, '0');
                times.push(`${displayHour}:${minute} ${ampm}`);
            }
        }
        return times;
    }

    function populateScheduleSelects() {
        const options = generateTimeOptions12();
        document.querySelectorAll('.official-select').forEach(sel => {
            // keep any existing selection
            const current = sel.value || '';
            sel.innerHTML = '<option value="">-- Select --</option>';
            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o;
                opt.textContent = o;
                if (o === current) opt.selected = true;
                sel.appendChild(opt);
            });
        });
    }

    function updateDayDisplay(dayId) {
        const start = document.getElementById(`${dayId} .start-hour`) || document.getElementById(`official-${dayId}-am`);
        const end = document.getElementById(`${dayId} .end-hour`) || document.getElementById(`official-${dayId}-pm`);
        const display = document.getElementById(`${dayId}-display`);
        const sVal = start ? start.value : '';
        const eVal = end ? end.value : '';
        if ((sVal && sVal !== '') && (eVal && eVal !== '')) {
            if (sVal === 'Not Working' || eVal === 'Not Working') {
                display.textContent = 'Not Working';
                display.style.color = '#e74c3c';
            } else {
                display.textContent = `${sVal} to ${eVal}`;
                display.style.color = '#2ecc71';
            }
        } else {
            display.textContent = 'Not set';
            display.style.color = '#7f8c8d';
        }
    }

    function attachScheduleListeners() {
        // fill selects
        populateScheduleSelects();

        // populate with existing hidden values if present
        ['mon','tue','wed','thu','fri','sat'].forEach(day => {
            const hidden = document.getElementById(`official-${day}`);
            if (hidden && hidden.value && hidden.value.trim() !== '' && hidden.value.trim() !== '---') {
                const parts = parseOfficialString(hidden.value);
                const am = document.getElementById(`official-${day}-am`);
                const pm = document.getElementById(`official-${day}-pm`);
                if (am) am.value = to12HourText(parts.am) || '';
                if (pm) pm.value = to12HourText(parts.pm) || '';
            }

            const amSel = document.getElementById(`official-${day}-am`);
            const pmSel = document.getElementById(`official-${day}-pm`);
            if (amSel) amSel.addEventListener('change', () => { updateDayDisplay(day); updateHidden(day); });
            if (pmSel) pmSel.addEventListener('change', () => { updateDayDisplay(day); updateHidden(day); });
            updateDayDisplay(day);
        });

        // Quick action buttons
        const sameBtn = document.getElementById('same-all-days');
        const weekdaysBtn = document.getElementById('weekdays-only');
        const weekendBtn = document.getElementById('weekend-only');
        const clearBtn = document.getElementById('clear-all');

        if (sameBtn) sameBtn.addEventListener('click', (e) => { e.preventDefault(); setSameScheduleAllDays(); });
        if (weekdaysBtn) weekdaysBtn.addEventListener('click', (e) => { e.preventDefault(); setWeekdaysOnly(); });
        if (weekendBtn) weekendBtn.addEventListener('click', (e) => { e.preventDefault(); setWeekendOnly(); });
        if (clearBtn) clearBtn.addEventListener('click', (e) => { e.preventDefault(); clearAllSchedules(); });
    }

    function updateHidden(day) {
        const am = document.getElementById(`official-${day}-am`);
        const pm = document.getElementById(`official-${day}-pm`);
        const hidden = document.getElementById(`official-${day}`);
        if (!hidden) return;
        // convert select values (12-hr text) to stored format (keep as-is)
        const combined = formatOfficialString(am ? am.value : '', pm ? pm.value : '');
        hidden.value = combined.trim() === '' ? '---' : combined;
    }

    // Quick action implementations
    function setSameScheduleAllDays() {
        // copy monday values into other days
        const monStart = document.getElementById('official-mon-am') ? document.getElementById('official-mon-am').value : '';
        const monEnd = document.getElementById('official-mon-pm') ? document.getElementById('official-mon-pm').value : '';
    if (!monStart && !monEnd) { /* nothing to copy */ return; }
        ['tue','wed','thu','fri','sat'].forEach(day => {
            const a = document.getElementById(`official-${day}-am`);
            const b = document.getElementById(`official-${day}-pm`);
            if (a) { a.value = monStart; }
            if (b) { b.value = monEnd; }
            updateDayDisplay(day);
            updateHidden(day);
        });
    // feedback: no confirmation popup (inline updates applied)
    }

    function setWeekdaysOnly() {
        const start = '9:00 AM';
        const end = '5:00 PM';
        ['mon','tue','wed','thu','fri'].forEach(day => {
            const a = document.getElementById(`official-${day}-am`);
            const b = document.getElementById(`official-${day}-pm`);
            if (a) a.value = start;
            if (b) b.value = end;
            updateDayDisplay(day);
            updateHidden(day);
        });
    // feedback: no confirmation popup (inline updates applied)
    }

    function setWeekendOnly() {
        const start = '10:00 AM';
        const end = '2:00 PM';
        const day = 'sat';
        const a = document.getElementById(`official-${day}-am`);
        const b = document.getElementById(`official-${day}-pm`);
        if (a) a.value = start;
        if (b) b.value = end;
        updateDayDisplay(day);
        updateHidden(day);
    // feedback: no confirmation popup (inline updates applied)
    }

    function clearAllSchedules() {
        ['mon','tue','wed','thu','fri','sat'].forEach(day => {
            const a = document.getElementById(`official-${day}-am`);
            const b = document.getElementById(`official-${day}-pm`);
            const hidden = document.getElementById(`official-${day}`);
            if (a) a.value = '';
            if (b) b.value = '';
            if (hidden) hidden.value = '';
            updateDayDisplay(day);
        });
    // feedback: no confirmation popup (inline updates applied)
    }

    // Modal functions
    function openModal() {
        document.getElementById("logoutModal").style.display = "flex";
    }

    function closeModal() {
        document.getElementById("logoutModal").style.display = "none";
    }

    // Track whether the edit modal was opened from the user profile modal
    let _editOpenedFromUserProfile = false;

    function openEditProfileModal() {
        const em = document.getElementById("editProfileModal");
        if (em) em.style.display = "flex";
    }

    function closeEditProfileModal() {
        const em = document.getElementById("editProfileModal");
        if (em) em.style.display = "none";
        // If the edit modal was opened from the user profile modal, restore it
        if (_editOpenedFromUserProfile) {
            const upm = document.getElementById('userProfileModal');
            if (upm) upm.style.display = 'flex';
            _editOpenedFromUserProfile = false;
        }
    }

    function openFillModal() {
        const modal = document.getElementById("fillOutModal");
        if (modal) {
            modal.style.display = "flex";
            loadTodayRecord(); // Load today's data
           
            // Reset form message
            const msg = document.getElementById("formMessage");
            if (msg) msg.textContent = "";
           
            // Enable submit button
            const submitBtn = document.getElementById("submit-button");
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    function closeFillModal() {
        document.getElementById("fillOutModal").style.display = "none";
    }

    function openWeekModal() {
        document.getElementById("weekSelectModal").style.display = "flex";
    }

    function closeWeekModal() {
        document.getElementById("weekSelectModal").style.display = "none";
    }

    // User Profile modal helpers (keep behavior only; do not change existing functionality)
    function openUserProfileModal() {
        const m = document.getElementById('userProfileModal');
        if (m) m.style.display = 'flex';
    }

    // Open edit modal from the user profile overlay button and hide the profile modal
    document.addEventListener('DOMContentLoaded', function () {
        const editBtn = document.getElementById('userProfileEditBtn');
        if (editBtn) {
            editBtn.addEventListener('click', function (e) {
                // mark that the edit modal was opened from the user profile and open it
                _editOpenedFromUserProfile = true;
                openEditProfileModal();
                const upm = document.getElementById('userProfileModal');
                if (upm) upm.style.display = 'none';
            });

            // keyboard support (Enter / Space)
            editBtn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    editBtn.click();
                }
            });
        }

        // If the edit form was submitted and we set a flag, reopen the User Profile modal on page load
        try {
            if (localStorage.getItem('reopenUserProfile') === '1') {
                localStorage.removeItem('reopenUserProfile');
                const upm = document.getElementById('userProfileModal');
                if (upm) upm.style.display = 'flex';
            }
        } catch (err) {
            // ignore storage errors
        }

        // Hook the edit profile form submit to set the reopen flag when saving
        const editForm = document.getElementById('editProfileForm');
        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                try {
                    // If the edit modal was opened from the user profile, set flag so it reopens after reload
                    if (typeof _editOpenedFromUserProfile !== 'undefined' && _editOpenedFromUserProfile) {
                        localStorage.setItem('reopenUserProfile', '1');
                    }
                } catch (err) { /* ignore */ }
            });
        }
    });

    function closeUserProfileModal() {
        const m = document.getElementById('userProfileModal');
        if (m) m.style.display = 'none';
    }

    // Wire header fullname button to open the profile modal (keyboard accessible)
    document.addEventListener('DOMContentLoaded', function() {
        const headerFullnameBtn = document.getElementById('headerFullnameBtn');
        if (headerFullnameBtn) {
            headerFullnameBtn.addEventListener('click', function() { openUserProfileModal(); });
            headerFullnameBtn.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openUserProfileModal(); } });
        }
    });

    // Load today's record for the form
    function loadTodayRecord(selectedWeek = null, selectedYear = null) {
        const formData = new FormData();
        formData.append('ajax', 'load_today');
        if (selectedWeek) formData.append('week', selectedWeek);
        if (selectedYear) formData.append('year', selectedYear);

        fetch(window.location.pathname, {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then (data => {
            if (data.status === "success") {
                // Update time inputs
                document.getElementById("time-in").value = data.data.time_in || '';
                document.getElementById("time-out").value = data.data.time_out || '';
                document.getElementById("task").value = data.data.task_completed || '';

                // Update official times (populate hidden input and new AM/PM pickers)
                ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'].forEach(day => {
                    const hidden = document.getElementById(`official-${day}`);
                    const amPicker = document.getElementById(`official-${day}-am`);
                    const pmPicker = document.getElementById(`official-${day}-pm`);
                    const raw = data.data[`official_${day}`] || '';
                    if (hidden) hidden.value = raw;
                    const parts = parseOfficialString(raw);
                    if (amPicker) amPicker.value = parts.am;
                    if (pmPicker) pmPicker.value = parts.pm;
                    console.log(`Loaded official_${day}:`, raw, parts);
                });

                // Update official time fields based on current week status
                if (data.hasOwnProperty('is_current_week')) {
                    updateOfficialTimeFields(data.is_current_week);
                }
            }
        })
        .catch(err => console.error("Error loading record:", err));
    }

    // Week selection
    function selectWeekDropdown(weekNum) {
        const dropdown = document.getElementById('weekDropdown');
        const selected = dropdown.options[dropdown.selectedIndex];
        const range = selected.getAttribute('data-range');
        const year = selected.getAttribute('data-year');
       
        // Update current state
        currentWeekState.week = weekNum;
        currentWeekState.year = year;
        currentWeekState.range = range;
       
        // Load the selected week
        loadViewForm(weekNum, range, year);
        closeWeekModal();
    }

    // Load viewform without resetting current state
    function loadViewForm(week, range, year) {
        const container = document.getElementById('viewform-container');
        if (!container) return;

        // Show printable button when viewform is being shown
        try {
            const pbtn = document.getElementById('printableViewBtn');
            if (pbtn) pbtn.style.display = 'inline-flex';
        } catch (e) { /* ignore */ }

        

        // Ensure there's an inner wrapper we can safely replace without touching
        // the outer container's layout or event listeners.
        let inner = container.querySelector('.viewform-inner');
        if (!inner) {
            inner = document.createElement('div');
            inner.className = 'viewform-inner';
            inner.innerHTML = container.innerHTML;
            container.innerHTML = '';
            container.appendChild(inner);
        }

        // Immediately fetch and replace content asynchronously while keeping current UI visible
        const previousHtml = inner.innerHTML; // keep a backup in case of errors

        // Start fetch in background; do not hide the existing content so the view appears instantly
        fetch(`viewform.php?week=${encodeURIComponent(week)}&range=${encodeURIComponent(range)}&year=${encodeURIComponent(year)}&t=${new Date().getTime()}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(html => {
                // Parse fetched html off-DOM to extract the inner fragment if present
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Collect stylesheet links and inline styles from the fragment and inject them into head.
                // For external stylesheets, wait for them to load before inserting the content to avoid FOUC.
                try {
                    const styleNodes = Array.from(doc.querySelectorAll('link[rel="stylesheet"], style'));
                    const loadPromises = [];

                    styleNodes.forEach(node => {
                        if (node.tagName.toLowerCase() === 'link') {
                            const href = node.getAttribute('href');
                            if (href) {
                                // Avoid adding duplicates
                                if (!document.head.querySelector(`link[href="${href}"]`)) {
                                    const link = document.createElement('link');
                                    link.rel = 'stylesheet';
                                    link.href = href;
                                    const p = new Promise(resolve => {
                                        link.onload = () => resolve();
                                        link.onerror = () => resolve();
                                        // still resolve on error so we don't hang
                                    });
                                    loadPromises.push(p);
                                    document.head.appendChild(link);
                                }
                            }
                        } else if (node.tagName.toLowerCase() === 'style') {
                            const cssText = node.textContent || '';
                            // avoid duplicates by checking if an identical style block exists
                            let exists = false;
                            document.head.querySelectorAll('style').forEach(s => {
                                if ((s.textContent || '').trim() === cssText.trim()) exists = true;
                            });
                            if (!exists && cssText.trim().length > 0) {
                                const style = document.createElement('style');
                                style.textContent = cssText;
                                document.head.appendChild(style);
                            }
                        }
                    });

                    // Determine the HTML to insert (prefer a .viewform-inner wrapper if returned)
                    let newInner = doc.querySelector('.viewform-inner');
                    let newHtml = '';
                    if (newInner) {
                        newHtml = newInner.innerHTML;
                    } else if (doc.body && doc.body.innerHTML.trim()) {
                        newHtml = doc.body.innerHTML;
                    } else {
                        newHtml = html;
                    }

                    // Replace inner content when fetched and when styles have (likely) loaded.
                    // We still wait briefly for styles to reduce flash, but we won't hide existing UI.
                    const timeout = new Promise(resolve => setTimeout(resolve, 300));
                    Promise.all([Promise.all(loadPromises), timeout]).then(() => {
                        // Replace inner content now that styles are (likely) applied
                        inner.innerHTML = newHtml;
                        // Execute any scripts present in the fetched fragment
                        try {
                            const scriptNodes = Array.from(doc.querySelectorAll('script'));
                            const externalSrcs = [];
                            const inlineScripts = [];

                            scriptNodes.forEach(node => {
                                const src = node.getAttribute && node.getAttribute('src');
                                if (src) {
                                    externalSrcs.push(src);
                                } else {
                                    inlineScripts.push(node.textContent || node.innerText || '');
                                }
                            });

                            // Helper: load external scripts sequentially to guarantee order
                            function loadExternalSequential(sources) {
                                return new Promise((resolve) => {
                                    const list = sources.slice();
                                    function next() {
                                        if (list.length === 0) return resolve();
                                        const src = list.shift();
                                        // Skip if already present
                                        if (document.querySelector(`script[src="${src}"]`)) {
                                            return next();
                                        }
                                        const s = document.createElement('script');
                                        s.src = src;
                                        s.async = false;
                                        s.onload = () => next();
                                        s.onerror = () => next();
                                        document.body.appendChild(s);
                                    }
                                    next();
                                });
                            }

                            loadExternalSequential(externalSrcs).then(() => {
                                // After externals loaded, run inline scripts
                                inlineScripts.forEach(code => {
                                    try {
                                        const s = document.createElement('script');
                                        s.type = 'text/javascript';
                                        s.text = code;
                                        document.body.appendChild(s);
                                    } catch (e) {
                                        // ignore
                                    }
                                });
                            });
                        } catch (e) {
                            console.warn('Failed to execute scripts from viewform fragment:', e);
                        }

                        // Note: keep UI visible during load; content was replaced when ready
                    });
                } catch (e) {
                    console.warn('Failed to inject styles from viewform:', e);
                    inner.innerHTML = newHtml || html;
                }
            })
            .catch(err => {
                console.error('Error loading viewform:', err);
                // restore previous content to avoid leaving blank/un-styled area
                inner.innerHTML = previousHtml || '<div class="error">Error loading data</div>';
                inner.style.visibility = '';
            });
    }

    // Load analytics dashboard into the view area (used by Home/Dashboard)
    function loadAnalytics() {
        const container = document.getElementById('viewform-container');
        if (!container) {
            // If there's no viewform container, navigate to analytics.php as a fallback
            window.location.href = 'analytics.php';
            return;
        }

        // Hide printable button on analytics/dashboard
        try {
            const pbtn = document.getElementById('printableViewBtn');
            if (pbtn) pbtn.style.display = 'none';
        } catch (e) { /* ignore */ }

        

        // Similar safe replace used by loadViewForm
        let inner = container.querySelector('.viewform-inner');
        if (!inner) {
            inner = document.createElement('div');
            inner.className = 'viewform-inner';
            inner.innerHTML = container.innerHTML;
            container.innerHTML = '';
            container.appendChild(inner);
        }

        const previousHtml = inner.innerHTML;
        fetch('analytics.php?t=' + Date.now())
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Inject any stylesheet links or style blocks from fetched fragment into the main document
                try {
                    const styleNodes = doc.querySelectorAll('link[rel="stylesheet"], style');
                    styleNodes.forEach(node => {
                        if (node.tagName.toLowerCase() === 'link') {
                            const href = node.getAttribute('href');
                            if (href && !document.head.querySelector(`link[href="${href}"]`)) {
                                const link = document.createElement('link');
                                link.rel = 'stylesheet';
                                link.href = href;
                                document.head.appendChild(link);
                            }
                        } else if (node.tagName.toLowerCase() === 'style') {
                            const cssText = node.textContent || '';
                            let exists = false;
                            document.head.querySelectorAll('style').forEach(s => {
                                if ((s.textContent || '').trim() === cssText.trim()) exists = true;
                            });
                            if (!exists && cssText.trim().length > 0) {
                                const style = document.createElement('style');
                                style.textContent = cssText;
                                document.head.appendChild(style);
                            }
                        }
                    });
                } catch (e) { console.warn('Failed to inject styles from analytics:', e); }

                // Prefer a server-provided wrapper with class 'viewform-inner' or fallback to body
                const newInner = doc.querySelector('.viewform-inner');
                const fragmentHtml = newInner ? newInner.innerHTML : (doc.body && doc.body.innerHTML.trim() ? doc.body.innerHTML : html);

                // Replace inner HTML first
                inner.innerHTML = fragmentHtml;

                // Execute any scripts that came with the fetched fragment.
                // Strategy: collect external scripts and inline scripts separately.
                // Load external scripts sequentially, then execute inline scripts.
                try {
                    const scriptNodes = Array.from(doc.querySelectorAll('script'));
                    const externalSrcs = [];
                    const inlineScripts = [];

                    scriptNodes.forEach(node => {
                        const src = node.getAttribute && node.getAttribute('src');
                        if (src) {
                            externalSrcs.push(src);
                        } else {
                            inlineScripts.push({ code: node.textContent || node.innerText || '', type: node.type || 'text/javascript' });
                        }
                    });

                    // Helper: load external scripts sequentially to guarantee order
                    function loadExternalSequential(sources) {
                        return new Promise((resolve) => {
                            const list = sources.slice();
                            function next() {
                                if (list.length === 0) return resolve();
                                const src = list.shift();
                                // Skip if already present
                                if (document.querySelector(`script[src="${src}"]`)) return next();
                                const s = document.createElement('script');
                                s.src = src;
                                s.async = false; // try to preserve order
                                s.onload = () => next();
                                s.onerror = () => {
                                    console.warn('Failed loading script:', src);
                                    next();
                                };
                                document.head.appendChild(s);
                            }
                            next();
                        });
                    }

                    loadExternalSequential(externalSrcs).then(() => {
                        // After externals loaded, run inline scripts
                        inlineScripts.forEach(item => {
                            try {
                                const s = document.createElement('script');
                                s.type = item.type;
                                s.text = item.code;
                                document.body.appendChild(s);
                            } catch (e) {
                                console.warn('Failed to execute inline script from fragment', e);
                            }
                        });
                    });
                } catch (e) {
                    console.warn('Failed to execute scripts from analytics fragment:', e);
                }
            })
            .catch(err => {
                console.error('Error loading analytics:', err);
                // revert to previous content to avoid blank area
                inner.innerHTML = previousHtml || '<div class="error">Error loading dashboard</div>';
                // as last resort, navigate to analytics.php so the user can see the page
                // but only do this if the fetch consistently fails
            });
    }

    // Refresh current viewform without changing week
    function refreshCurrentViewForm() {
        loadViewForm(currentWeekState.week, currentWeekState.range, currentWeekState.year);
    }

    // Add getWeek method to Date prototype
    Date.prototype.getWeek = function() {
        const d = new Date(Date.UTC(this.getFullYear(), this.getMonth(), this.getDate()));
        const dayNum = d.getUTCDay() || 7;
        d.setUTCDate(d.getUTCDate() + 4 - dayNum);
        const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
        return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    };

    // Form submission with smooth updates - SIMPLIFIED VERSION
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize current week range
        initializeCurrentWeekRange();
       
    // Load initial dashboard (analytics) instead of viewform
    try { loadAnalytics(); } catch (e) { console.warn('loadAnalytics failed, falling back to viewform', e); loadViewForm(currentWeekState.week, currentWeekState.range, currentWeekState.year); }

        // Button event listeners
            document.getElementById('fillButton').addEventListener('click', function(e) {
            e.preventDefault();
            openFillModal();
        });
       
        document.getElementById('weekButton').addEventListener('click', function(e) {
            e.preventDefault();
            openWeekModal();
        });

        // Schedule button wiring
        const scheduleBtn = document.getElementById('scheduleButton');
        if (scheduleBtn) {
            scheduleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openScheduleModal();
            });
        }

        // Sidebar nav active highlight (Home, Fill Out, Week Select, Schedule)
        const sidebarItems = ['homeButton','fillButton','weekButton','scheduleButton'];
        sidebarItems.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('click', function() {
                try { localStorage.setItem('activeSidebar', id); } catch (err) {}
                sidebarItems.forEach(i => document.getElementById(i) && document.getElementById(i).classList.remove('active-nav'));
                el.classList.add('active-nav');
            });
        });

        // Restore active sidebar item from localStorage; default to homeButton (Dashboard)
        try {
            const active = localStorage.getItem('activeSidebar');
            if (active && document.getElementById(active)) {
                document.getElementById(active).classList.add('active-nav');
            } else {
                const home = document.getElementById('homeButton');
                if (home) {
                    home.classList.add('active-nav');
                    try { localStorage.setItem('activeSidebar', 'homeButton'); } catch (e) {}
                }
            }
        } catch (err) {}

        // Ensure clicking the Dashboard/Home button loads the dashboard (viewform)
        (function() {
            const home = document.getElementById('homeButton');
            if (!home) return;
            home.addEventListener('click', function(e) {
                e.preventDefault();
                try { localStorage.setItem('activeSidebar', 'homeButton'); } catch (e) {}
                // mark active visually
                ['homeButton','fillButton','weekButton','scheduleButton'].forEach(i => {
                    const el = document.getElementById(i);
                    if (el) el.classList.remove('active-nav');
                });
                home.classList.add('active-nav');
                // load dashboard content (analytics)
                try { loadAnalytics(); } catch (e) { console.warn('loadAnalytics failed, falling back to viewform', e); loadViewForm(currentWeekState.week, currentWeekState.range, currentWeekState.year); }
            });
        })();

        // Printable View button -> open viewform in new tab with filled approved fields
        (function() {
            const btn = document.getElementById('printableViewBtn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                try {
                    const container = document.getElementById('viewform-container');
                    const scope = container ? container : document;
                    const nameEl = scope.querySelector('.viewform-inner .line-field.bold[contenteditable="true"], .line-field.bold[contenteditable="true"]');
                    const titleEl = scope.querySelector('.viewform-inner .line-field[contenteditable="true"]:not(.bold), .line-field[contenteditable="true"]:not(.bold)');
                    const approvedName = nameEl ? nameEl.textContent.trim() : '';
                    const approvedTitle = titleEl ? titleEl.textContent.trim() : '';

                    const url = `viewform.php?week=${encodeURIComponent(currentWeekState.week)}&year=${encodeURIComponent(currentWeekState.year)}&range=${encodeURIComponent(currentWeekState.range)}&approved_name=${encodeURIComponent(approvedName)}&approved_title=${encodeURIComponent(approvedTitle)}&print=1`;
                    window.open(url, '_blank');
                } catch (err) {
                    console.error('Printable view error:', err);
                    // Fallback: open with only week params
                    const url = `viewform.php?week=${encodeURIComponent(currentWeekState.week)}&year=${encodeURIComponent(currentWeekState.year)}&range=${encodeURIComponent(currentWeekState.range)}&print=1`;
                    window.open(url, '_blank');
                }
            });
        })();

        // Scheduling modal submit button - reuse same save_form flow for official times/company
        const scheduleSubmitBtn = document.getElementById('scheduleSubmit');
        if (scheduleSubmitBtn) {
            scheduleSubmitBtn.addEventListener('click', function(e) {
                e.preventDefault();

                const msg = document.getElementById("formMessage");
                const submitBtn = document.getElementById('submit-button');

                let formData = new FormData();
                formData.append('ajax', 'save_form');
                formData.append('week', currentWeekState.week);
                formData.append('year', currentWeekState.year);

                // Read values from AM/PM pickers and set hidden inputs (preserve server field names)
                ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'].forEach(day => {
                    const am = document.getElementById(`official-${day}-am`);
                    const pm = document.getElementById(`official-${day}-pm`);
                    const hidden = document.getElementById(`official-${day}`);
                    const combined = formatOfficialString(am ? am.value : '', pm ? pm.value : '');
                    if (hidden) hidden.value = combined;
                    const valueToSend = combined.trim() === '' ? '---' : combined;
                    formData.append(`official_${day}`, valueToSend);
                });

                // Preserve current day's accomplishment data
                // Ensure times are enforced/normalized as well
                try { enforceTimesBeforeSubmit(); } catch (err) { console.warn('enforceTimesBeforeSubmit failed', err); }
                const timeIn = document.getElementById('time-in');
                const timeOut = document.getElementById('time-out');
                const task = document.getElementById('task');

                if (timeIn && timeIn.value) formData.append('time_in', timeIn.value);
                if (timeOut && timeOut.value) formData.append('time_out', timeOut.value);
                if (task && task.value) formData.append('task_completed', task.value);

                // Add company data
                const companyInput = document.getElementById('company');
                if (companyInput) formData.append('company', companyInput.value);

                if (submitBtn) submitBtn.disabled = true;
                if (msg) { msg.style.color = 'blue'; msg.textContent = 'Saving data...'; }

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (msg) { msg.style.color = 'green'; msg.textContent = data.message; }
                        setTimeout(() => {
                            closeScheduleModal();
                            refreshCurrentViewForm();
                        }, 800);
                    } else {
                        if (msg) { msg.style.color = 'red'; msg.textContent = data.message || 'Error saving data'; }
                        if (submitBtn) submitBtn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error('Schedule submit error:', err);
                    if (msg) { msg.style.color = 'red'; msg.textContent = 'Error saving data. Please try again.'; }
                    if (submitBtn) submitBtn.disabled = false;
                });
            });
        }

        // Enhanced Form submission with animations
        const accomplishmentForm = document.getElementById("accomplishmentForm");
        if (accomplishmentForm) {
            accomplishmentForm.addEventListener("submit", function(e) {
                e.preventDefault();
               
                const submitBtn = document.getElementById('submit-button');
                const msg = document.getElementById("formMessage");
                
                // Add loading animation to submit button
                if (submitBtn) {
                    submitBtn.style.position = 'relative';
                    submitBtn.style.overflow = 'hidden';
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = `
                        <span style="opacity: 0.7;">Saving...</span>
                        <div style="
                            position: absolute;
                            top: 50%;
                            right: 15px;
                            transform: translateY(-50%);
                            width: 16px;
                            height: 16px;
                            border: 2px solid rgba(255, 255, 255, 0.3);
                            border-top: 2px solid #ffffff;
                            border-radius: 50%;
                            animation: spin 0.8s linear infinite;
                        "></div>
                    `;
                }
                
                // Simple loading feedback instead of heavy shimmer
                accomplishmentForm.style.opacity = '0.7';
                accomplishmentForm.style.transition = 'opacity 0.15s ease';
                
               // enforce/normalize times (cap at 8h, handle overnight) before sending
               try { enforceTimesBeforeSubmit(); } catch (err) { console.warn('enforceTimesBeforeSubmit failed', err); }
               
                // Create simple form data with animation feedback
                let formData = new FormData();
                formData.append('ajax', 'save_form');
                formData.append('week', currentWeekState.week);
                formData.append('year', currentWeekState.year);
                formData.append('time_in', document.getElementById('time-in').value);
                formData.append('time_out', document.getElementById('time-out').value);
                formData.append('task_completed', document.getElementById('task').value);
               
                // Add official times - MANUALLY (always send for any week)
                ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'].forEach(day => {
                    const am = document.getElementById(`official-${day}-am`);
                    const pm = document.getElementById(`official-${day}-pm`);
                    const hidden = document.getElementById(`official-${day}`);
                    const combined = formatOfficialString(am ? am.value : '', pm ? pm.value : '');
                    if (hidden) hidden.value = combined;
                    formData.append(`official_${day}`, combined);
                    console.log(`Adding official_${day}:`, combined);
                });

                // Debug: Show all form data
                console.log("Form Data being sent:");
                for (let pair of formData.entries()) {
                    console.log(pair[0] + ': ' + pair[1]);
                }

                if (msg) {
                    msg.style.color = "#2196F3";
                    msg.style.opacity = "1";
                    msg.style.transform = "scale(1.02)";
                    msg.style.transition = "all 0.3s ease";
                    msg.textContent = "Saving data...";
                }

                fetch(window.location.pathname, {
                    method: "POST",
                    body: formData
                })
                .then(res => {
                    console.log("Response status:", res.status);
                    return res.json();
                })
                .then(data => {
                    console.log("Server response:", data);
                    
                    // Restore form opacity
                    accomplishmentForm.style.opacity = '1';
                    
                    if (data.status === "success") {
                        // Success animation
                        if (msg) {
                            msg.style.color = "#4CAF50";
                            msg.style.animation = "successFlash 0.3s ease";
                            msg.textContent = data.message;
                        }
                        
                        if (submitBtn) {
                            submitBtn.style.background = "linear-gradient(135deg, #4CAF50, #45a049)";
                            submitBtn.innerHTML = "✓ Saved!";
                            submitBtn.style.animation = "pulse 0.6s ease";
                        }
                        
                        // Add success effect to form
                        accomplishmentForm.style.animation = "successFlash 0.6s ease";
                        
                        setTimeout(() => {
                            closeFillModal();
                            refreshCurrentViewForm();
                        }, 1200);
                    } else {
                        // Error animation
                        if (msg) {
                            msg.style.color = "#f44336";
                            msg.style.animation = "shake 0.5s ease";
                            msg.textContent = data.message || "Error saving data";
                        }
                        
                        if (submitBtn) {
                            submitBtn.style.background = "#f44336";
                            submitBtn.innerHTML = "✗ Error - Try Again";
                            submitBtn.style.animation = "shake 0.5s ease";
                            submitBtn.disabled = false;
                            
                            // Reset button after delay
                            setTimeout(() => {
                                submitBtn.style.background = "";
                                submitBtn.innerHTML = "Submit";
                                submitBtn.style.animation = "";
                            }, 3000);
                        }
                        
                        // Add error shake to form
                        accomplishmentForm.style.animation = "shake 0.5s ease";
                        setTimeout(() => {
                            accomplishmentForm.style.animation = "";
                        }, 500);
                    }
                })
                .catch(err => {
                    console.error("Submit error:", err);
                    
                    // Restore form opacity
                    accomplishmentForm.style.opacity = '1';
                    
                    // Network error animation
                    if (msg) {
                        msg.style.color = "#ff5722";
                        msg.style.animation = "shake 0.5s ease";
                        msg.textContent = "Network error. Please try again.";
                    }
                    
                    if (submitBtn) {
                        submitBtn.style.background = "#ff5722";
                        submitBtn.innerHTML = "✗ Network Error";
                        submitBtn.style.animation = "shake 0.5s ease";
                        submitBtn.disabled = false;
                        
                        // Reset button after delay
                        setTimeout(() => {
                            submitBtn.style.background = "";
                            submitBtn.innerHTML = "Submit";
                            submitBtn.style.animation = "";
                        }, 3000);
                    }
                    
                    accomplishmentForm.style.animation = "shake 0.5s ease";
                    setTimeout(() => {
                        accomplishmentForm.style.animation = "";
                    }, 500);
                });
            });
        }

        // Update date time
        function updateDateTime() {
            const now = new Date();
            const dateStr = now.toLocaleDateString(undefined, {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });
            const timeStr = now.toLocaleTimeString();
            const el = document.getElementById("currentDateTime");
            if (el) el.textContent = dateStr + " | " + timeStr;
        }
        setInterval(updateDateTime, 1000);
        updateDateTime();

        // Persist sidebar state (open/closed) using localStorage for the new `.sidebar`
        (function() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return;
            const isActive = localStorage.getItem('sidebarActive') === 'true';
            if (isActive) sidebar.classList.add('active');
            // Save state when user toggles via our other controls (click handlers set localStorage)
            // No hamburger control is used.
        })();

        // Background click-to-close behavior disabled for all modals per user request.
        // Only modal Close/Cancel buttons will dismiss their respective modals.
    });

    // Schedule modal functions
    function openScheduleModal() {
        const modal = document.getElementById('scheduleModal');
        if (modal) modal.style.display = 'flex';
        // Ensure schedule selects and listeners are attached and values loaded
        try { attachScheduleListeners(); } catch (e) { console.error('attachScheduleListeners error', e); }
        // Load today's record (will populate hidden inputs and then selects)
        try { loadTodayRecord(currentWeekState.week, currentWeekState.year); } catch (e) { console.error('loadTodayRecord error', e); }
    }

    

    function closeScheduleModal() {
        const modal = document.getElementById('scheduleModal');
        if (modal) modal.style.display = 'none';
    }

    // Image validation and preview
    function validateProfileImage(input) {
        const maxSize = 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png'];
        const errEl = document.getElementById('profileImageError');

        if (!input.files || !input.files[0]) return true;

        const file = input.files[0];
       
        if (file.size > maxSize) {
            errEl.textContent = 'Image size must be less than 1MB';
            input.value = '';
            return false;
        }
       
        if (!allowedTypes.includes(file.type)) {
            errEl.textContent = 'Only JPG and PNG images are allowed';
            input.value = '';
            return false;
        }

        errEl.textContent = '';
        return true;
    }

    function updateFileName(input) {
        if (!validateProfileImage(input)) return;

        const fileName = document.getElementById('fileName');
        const currentImage = document.getElementById('currentProfileImage');
       
        if (input.files && input.files[0]) {
            fileName.textContent = input.files[0].name;
            const reader = new FileReader();
            reader.onload = function(e) {
                currentImage.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function logout() {
        // First update the session status
        fetch('Logout.php', {
            method: 'POST',
            credentials: 'same-origin' // This is important for session handling
        })
        .then(response => {
            if (response.ok) {
                // Only redirect after successful logout
                window.location.href = 'Login.php';
            } else {
                console.error('Logout failed');
            }
        })
        .catch(error => {
            console.error('Error during logout:', error);
        });
    }

  // Ensure logout confirm button is clickable and has handler
  (function() {
    function enableLogoutConfirm() {
      const modal = document.getElementById('logoutModal');
      const confirmBtn = modal ? modal.querySelector('.confirm-filled') : null;
      if (!modal || !confirmBtn) return;

      // Make sure modal and ancestors accept pointer events
      let el = modal;
      while (el && el !== document.body) {
        el.style.pointerEvents = 'auto';
        el = el.parentElement;
      }

      // Ensure button looks enabled and is not disabled
      confirmBtn.disabled = false;
      confirmBtn.style.pointerEvents = 'auto';
      confirmBtn.style.cursor = 'pointer';

      // Attach click handler only once
      if (!confirmBtn.__logoutAttached) {
        confirmBtn.addEventListener('click', function (e) {
          // defensive: close modal then call logout
          try {
            closeModal(); // existing function closes logout modal
          } catch (err) { /* ignore */ }
          try { logout(); } catch (err) { console.error('logout() error', err); }
        });
        confirmBtn.__logoutAttached = true;
      }
    }

    // Run after DOM ready and also when modals are opened
    document.addEventListener('DOMContentLoaded', enableLogoutConfirm);
    // Observe DOM for modal insertion/display changes (in case other scripts toggle)
    const obs = new MutationObserver(() => enableLogoutConfirm());
    obs.observe(document.body, { childList: true, subtree: true });
  })();

  // AFK Timer - Auto logout after inactivity
  (function() {
    const AFK_TIMEOUT = 2 * 60 * 1000; // 1 minute in milliseconds (you can adjust this)
    let afkTimer;
    let afkModalShown = false;

    function resetAfkTimer() {
      // Don't reset timer if AFK modal is already shown - user must click the button
      if (afkModalShown) {
        return;
      }
      
      // Clear existing timer
      clearTimeout(afkTimer);

      // Set new timer
      afkTimer = setTimeout(function() {
        showAfkModal();
      }, AFK_TIMEOUT);
    }

    function showAfkModal() {
      afkModalShown = true;
      // Set sessionStorage flag so AFK modal persists on refresh
      sessionStorage.setItem('afkModalActive', 'true');
      const afkModal = document.getElementById('afkModal');
      if (afkModal) {
        afkModal.style.display = 'flex';
      }
    }

    function closeAfkModal() {
      const afkModal = document.getElementById('afkModal');
      if (afkModal) {
        afkModal.style.display = 'none';
      }
    }

    // Function to handle "Go back to Login" button click
    window.goBackToLogin = function() {
      // Clear the AFK flag ONLY when user properly responds
      sessionStorage.removeItem('afkModalActive');
      afkModalShown = false;
      
      // Close AFK modal
      closeAfkModal();
      
      // Call logout function
      try {
        logout();
      } catch (err) {
        console.error('Logout error from AFK:', err);
        // Fallback: direct redirect
        window.location.href = 'Login.php';
      }
    };

    // Events that indicate user activity
    const activityEvents = [
      'mousedown', 
      'mousemove', 
      'keypress', 
      'scroll', 
      'touchstart',
      'click'
    ];

    // Attach event listeners for user activity
    activityEvents.forEach(function(eventName) {
      document.addEventListener(eventName, resetAfkTimer, true);
    });

    // Initialize the timer when page loads
    document.addEventListener('DOMContentLoaded', function() {
      // Check if AFK modal was active before page refresh
      if (sessionStorage.getItem('afkModalActive') === 'true') {
        // Restore AFK modal state - don't let refresh dismiss it
        afkModalShown = true;
        const afkModal = document.getElementById('afkModal');
        if (afkModal) {
          afkModal.style.display = 'flex';
        }
        // Don't start timer - user must respond to the modal
      } else {
        // Normal page load - start AFK timer
        resetAfkTimer();
      }
    });

    // Also reset on page visibility change (when user returns to tab)
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) {
        // Only reset timer if AFK modal is not shown
        if (!afkModalShown) {
          resetAfkTimer();
        }
      }
    });
  })();
  </script>
    <script>
        // Sidebar hover/click behavior: expand on hover, lock open on click, and overlay handling
        (function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const menuLinks = document.querySelectorAll('.sidebar-menu a');
            let locked = false;

            // expand on hover when not locked
            sidebar.addEventListener('mouseenter', () => {
                if (!locked) sidebar.classList.add('active');
            });
            sidebar.addEventListener('mouseleave', () => {
                if (!locked) sidebar.classList.remove('active');
            });

            // click a menu item: lock the sidebar open and show overlay (for mobile behaviour)
            menuLinks.forEach(a => {
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    // mark active
                    menuLinks.forEach(i => i.classList.remove('active'));
                    a.classList.add('active');

                    locked = true;
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                    try { localStorage.setItem('sidebarActive', 'true'); } catch (e) { /* ignore */ }
                });
            });

            // clicking overlay closes/unlocks
            overlay.addEventListener('click', () => {
                locked = false;
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                try { localStorage.setItem('sidebarActive', 'false'); } catch (e) { /* ignore */ }
            });

            // close on Escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    locked = false;
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    try { localStorage.setItem('sidebarActive', 'false'); } catch (ex) {}
                }
            });

            // close sidebar when clicking outside (but ignore clicks inside the sidebar or on the overlay toggle)
            document.addEventListener('click', (e) => {
                const target = e.target;
                if (!sidebar.contains(target) && !overlay.contains(target)) {
                    locked = false;
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    try { localStorage.setItem('sidebarActive', 'false'); } catch (ex) {}
                }
            });
        })();

        
    </script>
</body>
</html>