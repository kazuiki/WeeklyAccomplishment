<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Get all students with their statistics
$query = "SELECT 
            u.user_id,
            u.username,
            u.email,
            u.created_at,
            COUNT(wa.id) as total_logs,
            COALESCE(SUM(wa.grand_total), 0) as total_hours,
            MAX(wa.date_record) as last_log_date
          FROM users u
          LEFT JOIN weekly_accomplishments wa ON u.user_id = wa.users_user_id
          GROUP BY u.user_id, u.username, u.email, u.created_at
          ORDER BY u.username ASC";

$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Students - Admin Panel</title>
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
        
        .navbar-links {
            display: flex;
            gap: 15px;
        }
        
        .nav-link {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .page-header p {
            color: #666;
            font-size: 14px;
        }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .student-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.2);
        }
        
        .student-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .student-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            font-weight: bold;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }
        
        .student-email {
            font-size: 13px;
            color: #666;
        }
        
        .student-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
            display: block;
        }
        
        .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .student-footer {
            font-size: 12px;
            color: #999;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .last-log {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .no-students {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .no-students-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .students-grid {
                grid-template-columns: 1fr;
            }
            
            .navbar {
                flex-direction: column;
                gap: 15px;
            }
            
            .navbar-links {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <span>👥</span>
            All Students
        </h1>
        <div class="navbar-links">
            <a href="admin_dashboard.php" class="nav-link">📊 Dashboard</a>
            <a href="?logout=1" class="nav-link">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h2>Student Directory</h2>
            <p>View all registered students and their accomplishment statistics</p>
        </div>
        
        <?php if ($result->num_rows > 0): ?>
            <div class="students-grid">
                <?php while ($student = $result->fetch_assoc()): ?>
                    <?php
                    $isActive = false;
                    if ($student['last_log_date']) {
                        $lastLog = new DateTime($student['last_log_date']);
                        $now = new DateTime();
                        $diff = $now->diff($lastLog);
                        $isActive = ($diff->days <= 7); // Active if logged in last 7 days
                    }
                    ?>
                    <a href="admin_student_detail.php?id=<?php echo $student['user_id']; ?>" class="student-card">
                        <div class="student-header">
                            <div class="student-avatar">
                                <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name"><?php echo htmlspecialchars($student['username']); ?></div>
                                <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                            </div>
                        </div>
                        
                        <div class="student-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($student['total_logs']); ?></span>
                                <span class="stat-label">Logs</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($student['total_hours'], 1); ?></span>
                                <span class="stat-label">Hours</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">
                                    <span class="badge <?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $isActive ? '✓' : '✗'; ?>
                                    </span>
                                </span>
                                <span class="stat-label">Status</span>
                            </div>
                        </div>
                        
                        <div class="student-footer">
                            <div class="last-log">
                                <span>📅</span>
                                <span>
                                    <?php 
                                    if ($student['last_log_date']) {
                                        echo 'Last log: ' . date('M d, Y', strtotime($student['last_log_date']));
                                    } else {
                                        echo 'No logs yet';
                                    }
                                    ?>
                                </span>
                            </div>
                            <span>Joined: <?php echo date('M Y', strtotime($student['created_at'])); ?></span>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="no-students">
                <div class="no-students-icon">👥</div>
                <h3>No students found</h3>
                <p>There are no registered students in the system yet.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php
    // Handle logout
    if (isset($_GET['logout'])) {
        session_destroy();
        header("Location: admin_login.php");
        exit();
    }
    ?>
</body>
</html>
