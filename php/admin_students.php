<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
    header("Location: admin_login.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

require_once "db.php";
date_default_timezone_set('Asia/Manila');

// Handle AJAX request for session status updates
if (isset($_POST['action']) && $_POST['action'] === 'get_session_status') {
    header('Content-Type: application/json');
    
    $query = "SELECT 
                u.user_id,
                COALESCE(s.is_active, 0) as session_active
              FROM users u
              LEFT JOIN sessions s ON u.user_id = s.users_user_id
              ORDER BY u.user_id ASC";
    
    $result = $conn->query($query);
    $students = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    
    echo json_encode(['students' => $students]);
    exit();
}

// Get all students with their statistics and session status
$query = "SELECT 
            u.user_id,
            u.username,
            u.email,
            u.created_at,
            COUNT(wa.id) as total_logs,
            COALESCE(SUM(wa.grand_total), 0) as total_hours,
            MAX(wa.date_record) as last_log_date,
            si.profile_picture,
            si.profile_picture_type,
            COALESCE(s.is_active, 0) as session_active
          FROM users u
          LEFT JOIN weekly_accomplishments wa ON u.user_id = wa.users_user_id
          LEFT JOIN student_info si ON u.user_id = si.users_user_id
          LEFT JOIN sessions s ON u.user_id = s.users_user_id
          GROUP BY u.user_id, u.username, u.email, u.created_at, si.profile_picture, si.profile_picture_type, s.is_active
          ORDER BY u.username ASC";

$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin</title>
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
            position: relative;
        }
        
        .student-card.inactive {
            background: #fff8f8;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.2);
        }
        
        .student-card.inactive:hover {
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.2);
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
            position: relative;
        }
        
        .session-status-indicator {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 20px;
            height: 20px;
            border: 2px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            z-index: 2;
        }
        
        .session-status-indicator.active {
            background: #28a745;
            color: white;
        }
        
        .session-status-indicator.inactive {
            background: #dc3545;
            color: white;
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
            border: 1px solid #c3e6cb;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            font-weight: bold;
        }
        
        .badge-inactive::before {
            content: '🔴 ';
            font-size: 10px;
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
        
        /* Auto-update indicator */
        .auto-update-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(102, 126, 234, 0.9);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            z-index: 1000;
            display: none;
            animation: fadeInOut 2s ease-in-out;
        }
        
        @keyframes fadeInOut {
            0%, 100% { opacity: 0; }
            50% { opacity: 1; }
        }
    </style>
    <script>
        let autoUpdateInterval;
        let isUpdating = false;

        // Function to update session status for all students
        async function updateSessionStatus() {
            if (isUpdating) return;
            isUpdating = true;
            
            try {
                const response = await fetch('admin_students.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_session_status'
                });
                
                if (response.ok) {
                    const data = await response.json();
                    updateStudentCards(data.students);
                    showUpdateIndicator();
                }
            } catch (error) {
                console.error('Error updating session status:', error);
            } finally {
                isUpdating = false;
            }
        }

        // Function to update the visual status of student cards
        function updateStudentCards(studentsData) {
            studentsData.forEach(student => {
                const card = document.querySelector(`[data-user-id="${student.user_id}"]`);
                if (card) {
                    const isActive = student.session_active == 1;
                    
                    // Update card class
                    if (isActive) {
                        card.classList.remove('inactive');
                    } else {
                        card.classList.add('inactive');
                    }
                    
                    // Update status badge
                    const badge = card.querySelector('.badge');
                    if (badge) {
                        badge.className = `badge ${isActive ? 'badge-active' : 'badge-inactive'}`;
                        badge.textContent = isActive ? '✓' : '✗';
                    }
                    
                    // Update avatar indicator
                    const avatar = card.querySelector('.student-avatar');
                    if (avatar) {
                        // Find existing indicator
                        const existingIndicator = avatar.querySelector('.session-status-indicator');
                        if (existingIndicator) {
                            // Update existing indicator
                            existingIndicator.className = `session-status-indicator ${isActive ? 'active' : 'inactive'}`;
                            existingIndicator.title = isActive ? 'Session Active' : 'Session Inactive';
                        } else {
                            // Create new indicator if it doesn't exist
                            const indicator = document.createElement('div');
                            indicator.className = `session-status-indicator ${isActive ? 'active' : 'inactive'}`;
                            indicator.title = isActive ? 'Session Active' : 'Session Inactive';
                            avatar.appendChild(indicator);
                        }
                    }
                }
            });
        }

        // Function to show update indicator
        function showUpdateIndicator() {
            const indicator = document.getElementById('updateIndicator');
            if (indicator) {
                indicator.style.display = 'block';
                indicator.textContent = `Updated: ${new Date().toLocaleTimeString()}`;
                
                setTimeout(() => {
                    indicator.style.display = 'none';
                }, 2000);
            }
        }

        // Start auto-update when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Add data attributes to student cards for easier targeting
            const studentCards = document.querySelectorAll('.student-card');
            studentCards.forEach(card => {
                const href = card.getAttribute('href');
                const userId = href.split('id=')[1];
                if (userId) {
                    card.setAttribute('data-user-id', userId);
                }
            });

            // Start auto-update every 10 seconds
            autoUpdateInterval = setInterval(updateSessionStatus, 10000);
            
            // Update immediately on load
            setTimeout(updateSessionStatus, 1000);
        });

        // Stop auto-update when page is hidden/minimized
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (autoUpdateInterval) {
                    clearInterval(autoUpdateInterval);
                }
            } else {
                autoUpdateInterval = setInterval(updateSessionStatus, 10000);
                updateSessionStatus(); // Update immediately when page becomes visible
            }
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (autoUpdateInterval) {
                clearInterval(autoUpdateInterval);
            }
        });
    </script>
</head>
<body>
    <!-- Auto-update indicator -->
    <div id="updateIndicator" class="auto-update-indicator">
        Checking for updates...
    </div>
    
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
                    // Check session active status from database
                    $isSessionActive = ($student['session_active'] == 1);
                    ?>
                    <a href="admin_student_detail.php?id=<?php echo $student['user_id']; ?>" class="student-card <?php echo !$isSessionActive ? 'inactive' : ''; ?>">
                        <div class="student-header">
                            <div class="student-avatar">
                                <?php if ($student['profile_picture'] && $student['profile_picture_type']): ?>
                                    <img src="data:<?php echo htmlspecialchars($student['profile_picture_type']); ?>;base64,<?php echo base64_encode($student['profile_picture']); ?>" 
                                         alt="<?php echo htmlspecialchars($student['username']); ?>" 
                                         style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                                <?php endif; ?>
                                <!-- Always show session status indicator -->
                                <div class="session-status-indicator <?php echo $isSessionActive ? 'active' : 'inactive'; ?>" 
                                     title="<?php echo $isSessionActive ? 'Session Active' : 'Session Inactive'; ?>">
                                </div>
                            </div>
                            <div class="student-info">
                                <div class="student-name">
                                    <?php echo htmlspecialchars($student['username']); ?>
                                </div>
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
                                    <span class="badge <?php echo $isSessionActive ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $isSessionActive ? '✓' : '✗'; ?>
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
</body>
</html>
