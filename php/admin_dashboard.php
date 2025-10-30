<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
    header("Location: Login.php");
    exit();
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: Login.php");
    exit();
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_filter = $_GET['user_id'] ?? '';

// Fetch all users for filter dropdown
$users_query = "SELECT user_id, username, email FROM users ORDER BY username";
$users_result = $conn->query($users_query);
$users_list = [];
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users_list[] = $row;
    }
}

// Build query for weekly accomplishments with filters
$query = "SELECT 
            wa.id,
            wa.date_record,
            wa.time_in,
            wa.time_out,
            wa.task_completed,
            wa.grand_total,
            wa.created_at,
            u.user_id,
            u.username,
            u.email
          FROM weekly_accomplishments wa
          LEFT JOIN users u ON wa.users_user_id = u.user_id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR wa.task_completed LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($date_from)) {
    $query .= " AND wa.date_record >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND wa.date_record <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if (!empty($user_filter)) {
    $query .= " AND wa.users_user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

$query .= " ORDER BY wa.date_record DESC, wa.created_at DESC LIMIT 200";

// Prepare and execute safely with fallbacks and clear errors
$result = null;
$logsError = '';
$stmt = $conn->prepare($query);
if ($stmt === false) {
    // If no parameters, fall back to direct query; otherwise capture error
    if (empty($params)) {
        $result = $conn->query($query);
        if ($result === false) {
            $logsError = 'Query failed: ' . $conn->error;
        }
    } else {
        $logsError = 'Prepare failed: ' . $conn->error;
    }
} else {
    if (!empty($params)) {
        if (!$stmt->bind_param($types, ...$params)) {
            $logsError = 'Bind failed: ' . $stmt->error;
        }
    }
    if ($logsError === '') {
        if (!$stmt->execute()) {
            $logsError = 'Execute failed: ' . $stmt->error;
        } else {
            if (method_exists($stmt, 'get_result')) {
                $result = $stmt->get_result();
            } else {
                // Fallback when mysqlnd is not available: fetch via bind_result
                $rows = [];
                $id = $date_record = $time_in = $time_out = $task_completed = $grand_total = $created_at = $user_id = $username = $email = null;
                $stmt->bind_result($id, $date_record, $time_in, $time_out, $task_completed, $grand_total, $created_at, $user_id, $username, $email);
                while ($stmt->fetch()) {
                    $rows[] = [
                        'id' => $id,
                        'date_record' => $date_record,
                        'time_in' => $time_in,
                        'time_out' => $time_out,
                        'task_completed' => $task_completed,
                        'grand_total' => $grand_total,
                        'created_at' => $created_at,
                        'user_id' => $user_id,
                        'username' => $username,
                        'email' => $email,
                    ];
                }
                // Create a lightweight result-like wrapper for unified rendering
                $result = new class($rows) {
                    private $rows; public $num_rows;
                    function __construct($rows){ $this->rows=$rows; $this->num_rows=count($rows); }
                    function fetch_assoc(){ return array_shift($this->rows); }
                };
            }
        }
    }
    $stmt->close();
}

// Get statistics
$stats_query = "SELECT 
                COUNT(DISTINCT wa.users_user_id) as total_students,
                COUNT(wa.id) as total_logs,
                COALESCE(SUM(wa.grand_total), 0) as total_hours
                FROM weekly_accomplishments wa";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Weekly Accomplishment System</title>
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
        
        .navbar .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .navbar .admin-name {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .navbar .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .navbar .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card .icon {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .stat-card .label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .stat-card .value {
            font-size: 32px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .card-header h2 {
            font-size: 20px;
            color: #333;
        }
        
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #666;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .table-container {
            overflow-x: auto;
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
        
        tbody tr {
            transition: background-color 0.2s ease;
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
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        
        .task-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .navbar {
                flex-direction: column;
                gap: 15px;
            }
            
            .table-container {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <span>📊</span>
            Admin Dashboard
        </h1>
        <div class="admin-info">
            <a href="admin_students.php" class="logout-btn" style="margin-right: 10px;">👥 All Students</a>
            <span class="admin-name">Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">👥</div>
                <div class="label">Total Students</div>
                <div class="value"><?php echo number_format($stats['total_students']); ?></div>
            </div>
            <div class="stat-card">
                <div class="icon">📝</div>
                <div class="label">Total Log Entries</div>
                <div class="value"><?php echo number_format($stats['total_logs']); ?></div>
            </div>
            <div class="stat-card">
                <div class="icon">⏱️</div>
                <div class="label">Total Hours Logged</div>
                <div class="value"><?php echo number_format($stats['total_hours'], 1); ?></div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h2>Filter Logs</h2>
            </div>
            <?php if (!empty($logsError)): ?>
                <div style="background:#fdecea;color:#611a15;border:1px solid #f5c6cb;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
                    <strong>Unable to load logs.</strong><br>
                    <small><?php echo htmlspecialchars($logsError); ?></small>
                </div>
            <?php endif; ?>
            <form method="GET" action="">
                <div class="filters">
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" placeholder="Username, email, or task..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="user_id">Student</label>
                        <select id="user_id" name="user_id">
                            <option value="">All Students</option>
                            <?php foreach ($users_list as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>" <?php echo $user_filter == $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="admin_dashboard.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <h2>Student Accomplishment Logs</h2>
            </div>
            <div class="table-container">
                <?php if ($result && $result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Hours</th>
                                <th>Task Completed</th>
                                <th>Logged At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['date_record'])); ?></td>
                                    <td>
                                        <a href="admin_student_detail.php?id=<?php echo $row['user_id']; ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                                            <?php echo htmlspecialchars($row['username']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['time_in'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['time_out'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo number_format($row['grand_total'] ?? 0, 2); ?> hrs
                                        </span>
                                    </td>
                                    <td>
                                        <div class="task-preview" title="<?php echo htmlspecialchars($row['task_completed']); ?>">
                                            <?php echo htmlspecialchars($row['task_completed'] ?? 'No task recorded'); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, Y g:i A', strtotime($row['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>📭 No logs found matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
