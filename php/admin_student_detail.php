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

// Get all logs for this user
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
               ORDER BY date_record DESC, last_updated_at DESC";
$stmt = $conn->prepare($logs_query);
if (!$stmt) {
    die("Error preparing logs query: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$logs_result = $stmt->get_result();

// Get weekly summary
$weekly_query = "SELECT 
                  YEAR(date_record) as year,
                  WEEK(date_record, 1) as week,
                  COUNT(id) as log_count,
                  COALESCE(SUM(grand_total), 0) as week_hours
                 FROM weekly_accomplishments
                 WHERE users_user_id = ?
                 GROUP BY YEAR(date_record), WEEK(date_record, 1)
                 ORDER BY year DESC, week DESC
                 LIMIT 10";
$stmt = $conn->prepare($weekly_query);
if (!$stmt) {
    die("Error preparing weekly query: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$weekly_result = $stmt->get_result();
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
            <span>👤</span>
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
            <div class="card-header">
                <h3>📅 Recent Weekly Summary</h3>
            </div>
            <?php if ($weekly_result->num_rows > 0): ?>
                <table>
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
            <div class="card-header">
                <h3>📝 All Accomplishment Logs</h3>
            </div>
            <?php if ($logs_result->num_rows > 0): ?>
                <table>
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
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">No logs found for this student.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
