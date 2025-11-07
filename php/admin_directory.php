<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
    header("Location: Login.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: Login.php");
    exit();
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Get all admin accounts
$query = "SELECT 
            user_id,
            username,
            email,
            created_at
          FROM users
          WHERE is_admin = 1
          ORDER BY username ASC";

$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Directory</title>
    <link rel="icon" type="image/png" href="img/admin.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: linear-gradient(145deg, #2C5E8F 0%, #1e4a70 100%);
            color: white;
            padding: 15px 30px;
            box-shadow: 0 2px 15px rgba(44, 94, 143, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar h1 {
            color: white;
            font-size: 24px;
            display: flex;
            align-items: center;
        }
        
        .navbar-links {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header-text h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .page-header-text p {
            color: #666;
            font-size: 14px;
        }
        
        .back-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4);
        }
        
        .admins-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: linear-gradient(135deg, navy, #1e3a8a);
            color: white;
        }
        
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        
        tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        tbody tr:last-child {
            border-bottom: none;
        }
        
        td {
            padding: 16px;
            font-size: 14px;
            color: #333;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #333;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 60px 30px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .empty-state h3 {
            color: #666;
            font-size: 20px;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .admins-table {
                overflow-x: auto;
            }
            
            table {
                min-width: 600px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>
            <img src="img/admin.png" alt="Admin Directory" style="width:24px;height:24px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'; this.parentNode.insertBefore(document.createTextNode('👤 '), this);">
            Admin Directory
        </h1>
        <div class="navbar-links">
            <a href="admin_dashboard.php" class="nav-link">
                <img src="img/ui.png" alt="Dashboard" style="width:18px;height:18px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'; this.parentNode.insertBefore(document.createTextNode('📊 '), this);">
                Dashboard
            </a>
            <a href="?logout=1" class="nav-link">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-header-text">
                <h2>Admin Accounts</h2>
                <p>View all administrator accounts in the system</p>
            </div>
            <a href="admin_students.php" class="back-btn">
                ← Back to Students
            </a>
        </div>
        
        <?php if ($result->num_rows > 0): ?>
            <div class="admins-table">
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($admin = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($admin['user_id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                <td><span class="admin-badge">⭐ Admin</span></td>
                                <td><?php echo date('M d, Y', strtotime($admin['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>No Admin Accounts Found</h3>
                <p>There are currently no administrator accounts in the system.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php $conn->close(); ?>
