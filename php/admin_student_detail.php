<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
    header("Location: Login.php");
    exit();
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Get user ID from URL
$user_id = $_GET['id'] ?? null;

if (!$user_id) {
    header("Location: admin_dashboard.php");
    exit();
}

// Get user information
$user_query = "SELECT u.user_id, u.username, u.email, u.created_at, si.profile_picture, si.profile_picture_type 
               FROM users u 
               LEFT JOIN student_info si ON u.user_id = si.users_user_id 
               WHERE u.user_id = ?";
$stmt = $conn->prepare($user_query);
if (!$stmt) {
    die("Error preparing user query: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows === 0) {
    header("Location: admin_dashboard.php");
    exit();
}

$user = $user_result->fetch_assoc();

// Get statistics for this user
$stats_query = "SELECT 
                COUNT(id) as total_logs,
                COALESCE(SUM(grand_total), 0) as total_hours,
                MIN(date_record) as first_log,
                MAX(date_record) as last_log
                FROM weekly_accomplishments
                WHERE users_user_id = ?";
$stmt = $conn->prepare($stats_query);
if (!$stmt) {
    die("Error preparing stats query: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Pagination for logs (5 per page)
$page = max(1, intval($_GET['page'] ?? 1));
$records_per_page = 5;
$offset = ($page - 1) * $records_per_page;

// Count total logs for pagination
$total_logs = 0;
$count_logs_stmt = $conn->prepare("SELECT COUNT(*) as total_logs FROM weekly_accomplishments WHERE users_user_id = ?");
if ($count_logs_stmt) {
    $count_logs_stmt->bind_param("i", $user_id);
    $count_logs_stmt->execute();
    $count_result = $count_logs_stmt->get_result();
    if ($count_row = $count_result->fetch_assoc()) {
        $total_logs = intval($count_row['total_logs']);
    }
    $count_logs_stmt->close();
}

$total_pages = max(1, intval(ceil($total_logs / $records_per_page)));

// Get logs for current page
$logs_query = "SELECT 
                id,
                date_record,
                time_in,
                time_out,
                task_completed,
                grand_total,
                last_updated_at
               FROM weekly_accomplishments
               WHERE users_user_id = ?
               ORDER BY date_record DESC, last_updated_at DESC
               LIMIT ? OFFSET ?";
$stmt = $conn->prepare($logs_query);
if (!$stmt) {
    die("Error preparing logs query: " . $conn->error);
}
$stmt->bind_param("iii", $user_id, $records_per_page, $offset);
$stmt->execute();
$logs_result = $stmt->get_result();
// Build pagination HTML for logs (will be displayed in card header)
$pagination_html = '';
if ($total_pages > 1) {
    $base_url = "admin_student_detail.php?id=" . urlencode($user_id);
    // Preserve weekly pagination when navigating logs pages
    if (isset($_GET['week_page'])) {
        $base_url .= '&week_page=' . intval($_GET['week_page']);
    }
    $pagination_html .= '<div id="logs-pagination" style="margin-left:auto;display:flex;gap:8px;align-items:center;">';
    // Previous
    $prev_disabled = $page <= 1 ? 'opacity:0.5;pointer-events:none;' : '';
    $pagination_html .= '<a data-ajax="logs" href="' . $base_url . '&page=' . max(1, $page - 1) . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $prev_disabled . '">← Previous</a>';

    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $page + 2);
    if ($start_page > 1) {
            $pagination_html .= '<a data-ajax="logs" href="' . $base_url . '&page=1" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;">1</a>';
        if ($start_page > 2) $pagination_html .= '<span style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;color:#999;">...</span>';
    }
    for ($i = $start_page; $i <= $end_page; $i++) {
        $activeStyle = $i == $page ? 'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border-color:#667eea;' : '';
    $pagination_html .= '<a data-ajax="logs" href="' . $base_url . '&page=' . $i . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $activeStyle . '">' . $i . '</a>';
    }
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) $pagination_html .= '<span style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;color:#999;">...</span>';
    $pagination_html .= '<a data-ajax="logs" href="' . $base_url . '&page=' . $total_pages . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;">' . $total_pages . '</a>';
    }

    $next_disabled = $page >= $total_pages ? 'opacity:0.5;pointer-events:none;' : '';
    $pagination_html .= '<a data-ajax="logs" href="' . $base_url . '&page=' . min($total_pages, $page + 1) . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $next_disabled . '">Next →</a>';

    $pagination_html .= '</div>';
}

// Weekly summary with pagination (5 per page)
$week_page = max(1, intval($_GET['week_page'] ?? 1));
$week_records_per_page = 5;
$week_offset = ($week_page - 1) * $week_records_per_page;

// Count total distinct weeks for this user
$total_weeks = 0;
$count_weeks_sql = "SELECT COUNT(*) as total_weeks FROM (SELECT YEAR(date_record) as year, WEEK(date_record,1) as week FROM weekly_accomplishments WHERE users_user_id = ? GROUP BY YEAR(date_record), WEEK(date_record,1)) as t";
$count_weeks_stmt = $conn->prepare($count_weeks_sql);
if ($count_weeks_stmt) {
    $count_weeks_stmt->bind_param("i", $user_id);
    $count_weeks_stmt->execute();
    $count_weeks_result = $count_weeks_stmt->get_result();
    if ($count_row = $count_weeks_result->fetch_assoc()) {
        $total_weeks = intval($count_row['total_weeks']);
    }
    $count_weeks_stmt->close();
}

$week_total_pages = max(1, intval(ceil($total_weeks / $week_records_per_page)));

// Fetch paginated weekly summary
$weekly_query = "SELECT 
                  YEAR(date_record) as year,
                  WEEK(date_record, 1) as week,
                  COUNT(id) as log_count,
                  COALESCE(SUM(grand_total), 0) as week_hours
                 FROM weekly_accomplishments
                 WHERE users_user_id = ?
                 GROUP BY YEAR(date_record), WEEK(date_record, 1)
                 ORDER BY year DESC, week DESC
                 LIMIT ? OFFSET ?";
$stmt = $conn->prepare($weekly_query);
if (!$stmt) {
    die("Error preparing weekly query: " . $conn->error);
}
$stmt->bind_param("iii", $user_id, $week_records_per_page, $week_offset);
$stmt->execute();
$weekly_result = $stmt->get_result();

// Build weekly pagination HTML for card header
$weekly_pagination_html = '';
if ($week_total_pages > 1) {
    $base_week_url = "admin_student_detail.php?id=" . urlencode($user_id);
    // Preserve logs page when navigating weekly pages
    if (isset($_GET['page'])) {
        $base_week_url .= '&page=' . intval($_GET['page']);
    }
    $weekly_pagination_html .= '<div id="weekly-pagination" style="margin-left:auto;display:flex;gap:8px;align-items:center;">';
    $prev_disabled = $week_page <= 1 ? 'opacity:0.5;pointer-events:none;' : '';
    $weekly_pagination_html .= '<a data-ajax="weeks" href="' . $base_week_url . '&week_page=' . max(1, $week_page - 1) . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $prev_disabled . '">← Previous</a>';
    $start_w = max(1, $week_page - 2);
    $end_w = min($week_total_pages, $week_page + 2);
    if ($start_w > 1) {
    $weekly_pagination_html .= '<a data-ajax="weeks" href="' . $base_week_url . '&week_page=1" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;">1</a>';
        if ($start_w > 2) $weekly_pagination_html .= '<span style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;color:#999;">...</span>';
    }
    for ($w = $start_w; $w <= $end_w; $w++) {
        $activeStyle = $w == $week_page ? 'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border-color:#667eea;' : '';
    $weekly_pagination_html .= '<a data-ajax="weeks" href="' . $base_week_url . '&week_page=' . $w . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $activeStyle . '">' . $w . '</a>';
    }
    if ($end_w < $week_total_pages) {
        if ($end_w < $week_total_pages - 1) $weekly_pagination_html .= '<span style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;color:#999;">...</span>';
    $weekly_pagination_html .= '<a data-ajax="weeks" href="' . $base_week_url . '&week_page=' . $week_total_pages . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;">' . $week_total_pages . '</a>';
    }
    $next_disabled = $week_page >= $week_total_pages ? 'opacity:0.5;pointer-events:none;' : '';
    $weekly_pagination_html .= '<a data-ajax="weeks" href="' . $base_week_url . '&week_page=' . min($week_total_pages, $week_page + 1) . '" style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;text-decoration:none;color:#666;' . $next_disabled . '">Next →</a>';
    $weekly_pagination_html .= '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details - <?php echo htmlspecialchars($user['username']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .user-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
        }
        
        .user-details h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .user-details p {
            color: #666;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .stat-card .label {
            color: #666;
            font-size: 13px;
            margin-bottom: 5px;
        }
        
        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .card-header h3 {
            font-size: 18px;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            color: #555;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .task-cell {
            max-width: 400px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .user-info {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <img src="img/profile.png" alt="Student Details" style="width:24px;height:24px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'; this.parentNode.insertBefore(document.createTextNode('👤 '), this);">
            Student Details
        </h1>
        <a href="admin_dashboard.php" class="back-btn">← Back to Dashboard</a>
    </nav>
    
    <div class="container">
        <!-- User Header -->
        <div class="user-header">
            <div class="user-info">
                <div class="user-avatar">
                    <?php if ($user['profile_picture'] && $user['profile_picture_type']): ?>
                        <img src="data:<?php echo htmlspecialchars($user['profile_picture_type']); ?>;base64,<?php echo base64_encode($user['profile_picture']); ?>" 
                             alt="<?php echo htmlspecialchars($user['username']); ?>" 
                             style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                    <p><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Logs</div>
                <div class="value"><?php echo number_format($stats['total_logs']); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Hours</div>
                <div class="value"><?php echo number_format($stats['total_hours'], 1); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">First Log</div>
                <div class="value" style="font-size: 16px;">
                    <?php echo $stats['first_log'] ? date('M d, Y', strtotime($stats['first_log'])) : 'N/A'; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="label">Latest Log</div>
                <div class="value" style="font-size: 16px;">
                    <?php echo $stats['last_log'] ? date('M d, Y', strtotime($stats['last_log'])) : 'N/A'; ?>
                </div>
            </div>
        </div>
        
        <!-- Weekly Summary -->
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;gap:12px;">
                <h3>📅 Recent Weekly Summary</h3>
                <?php echo $weekly_pagination_html; ?>
            </div>
            <?php if ($weekly_result->num_rows > 0): ?>
                <table id="weekly-table">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Week Number</th>
                            <th>Log Count</th>
                            <th>Total Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($week = $weekly_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $week['year']; ?></td>
                                <td>Week <?php echo $week['week']; ?></td>
                                <td><?php echo $week['log_count']; ?> entries</td>
                                <td><span class="badge"><?php echo number_format($week['week_hours'], 2); ?> hrs</span></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">No weekly data available.</p>
            <?php endif; ?>
        </div>
        
        <!-- All Logs -->
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;gap:12px;">
                <h3>📝 All Accomplishment Logs</h3>
                <?php echo $pagination_html; ?>
            </div>
            <?php if ($logs_result->num_rows > 0): ?>
                <table id="logs-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours</th>
                            <th>Task Completed</th>
                            <th>Logged At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($log = $logs_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($log['date_record'])); ?></td>
                                <td><?php echo htmlspecialchars($log['time_in'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($log['time_out'] ?? 'N/A'); ?></td>
                                <td><span class="badge"><?php echo number_format($log['grand_total'] ?? 0, 2); ?> hrs</span></td>
                                <td class="task-cell"><?php echo htmlspecialchars($log['task_completed'] ?? 'No task recorded'); ?></td>
                                <td><?php echo date('M d, Y g:i A', strtotime($log['last_updated_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <!-- pagination moved to card header (upper-right) -->
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">No logs found for this student.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<script>
// AJAX pagination: intercept clicks on pagination links with data-ajax and fetch updated content
document.addEventListener('click', function(e) {
    var a = e.target.closest('a[data-ajax]');
    if (!a) return;
    e.preventDefault();
    var url = a.href;
    var target = a.getAttribute('data-ajax'); // 'logs' or 'weeks'

    fetch(url, { credentials: 'same-origin' }).then(function(resp) {
        if (!resp.ok) throw new Error('Network error');
        return resp.text();
    }).then(function(text) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(text, 'text/html');
        if (target === 'logs') {
            var newTable = doc.querySelector('#logs-table');
            var newPagination = doc.querySelector('#logs-pagination');
            if (newTable) {
                var oldTable = document.querySelector('#logs-table');
                oldTable.parentNode.replaceChild(newTable, oldTable);
            }
            if (newPagination) {
                var oldPag = document.querySelector('#logs-pagination');
                if (oldPag) oldPag.parentNode.replaceChild(newPagination, oldPag);
            }
        } else if (target === 'weeks') {
            var newTable = doc.querySelector('#weekly-table');
            var newPagination = doc.querySelector('#weekly-pagination');
            if (newTable) {
                var oldTable = document.querySelector('#weekly-table');
                oldTable.parentNode.replaceChild(newTable, oldTable);
            }
            if (newPagination) {
                var oldPag = document.querySelector('#weekly-pagination');
                if (oldPag) oldPag.parentNode.replaceChild(newPagination, oldPag);
            }
        }

        // Update URL without scrolling
        history.pushState({}, '', url);
    }).catch(function(err) {
        console.error(err);
    });
});

// Handle back/forward navigation
window.addEventListener('popstate', function() {
    // On back/forward, just reload the page to restore state
    location.reload();
});
</script>
