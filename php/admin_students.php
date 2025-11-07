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

// Get all students (excluding admins) with their statistics and session status
$students_query = "SELECT 
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
          WHERE u.is_admin = 0
          GROUP BY u.user_id, u.username, u.email, u.created_at, si.profile_picture, si.profile_picture_type, s.is_active
          ORDER BY u.username ASC";

$students_result = $conn->query($students_query);

// Get all admin accounts
$admins_query = "SELECT 
            user_id,
            username,
            email,
            created_at
          FROM users
          WHERE is_admin = 1
          ORDER BY username ASC";

$admins_result = $conn->query($admins_query);

// Get counts
$student_count = $students_result->num_rows;
$admin_count = $admins_result->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin</title>
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
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .admin-directory-btn {
            background: linear-gradient(135deg, navy, #1e3a8a);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 128, 0.2);
        }
        
        .admin-directory-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 128, 0.3);
        }
        
        .admin-directory-btn:active {
            transform: translateY(0);
        }
        
        .add-user-btn {
            width: 45px;
            height: 45px;
            background: white;
            border: 2px solid navy;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: navy;
            text-decoration: none;
            font-size: 30px;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(90, 155, 213, 0.3);
            transition: all 0.3s ease;
            cursor: pointer;
            line-height: 1; 
            padding-bottom: 7px;
            flex-shrink: 0;
        }
        
        .add-user-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 15px rgba(90, 155, 213, 0.5);
        }
        
        .add-user-btn:active {
            transform: scale(0.95);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, navy, #1e3a8a);
            color: white;
            padding: 20px 30px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }
        
        .close-modal {
            font-size: 32px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
            line-height: 1;
        }
        
        .close-modal:hover {
            transform: scale(1.2);
            color: #ffcccc;
        }
        
        #adminSignupForm {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: navy;
            box-shadow: 0 0 0 3px rgba(0, 0, 128, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn-cancel,
        .btn-submit {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-cancel {
            background: #f1f1f1;
            color: #666;
        }
        
        .btn-cancel:hover {
            background: #e0e0e0;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, navy, #1e3a8a);
            color: white;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 128, 0.3);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .signup-message {
            margin-top: 15px;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            display: none;
        }
        
        .signup-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }
        
        .signup-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }
        
        /* Tabs Styles */
        .tabs-container {
            margin-bottom: 0;
        }
        
        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .search-container {
            position: relative;
            flex: 1;
            max-width: 400px;
        }
        
        .search-container input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
        }
        
        .search-container input:focus {
            outline: none;
            border-color: #2C5E8F;
            box-shadow: 0 0 0 3px rgba(44, 94, 143, 0.1);
        }
        
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            pointer-events: none;
            opacity: 0.5;
        }
        
        .tabs-nav {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0;
            position: relative;
            z-index: 10;
            padding: 0 10px;
            right: -10px;
        }
        
        .tab-button {
            padding: 12px 28px;
            background: transparent;
            border: none;
            border-radius: 8px 8px 0 0;
            font-size: 15px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            margin-bottom: 0;
        }
        
        .tab-button:hover:not(.active) {
            color: #333;
        }
        
        .tab-button.active {
            background: #2C5E8F;
            color: white;
            margin-bottom: 0;
        }
        
        .tab-badge {
            display: inline-block;
            background: rgba(0, 0, 0, 0.1);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 8px;
            transition: all 0.3s ease;
        }
        
        .tab-button.active .tab-badge {
            background: rgba(255, 255, 255, 0.25);
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Admin Table Styles */
        .admins-table {
            background: #2C5E8F;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            padding: 0;
        }
        
        .admins-table table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .admins-table thead {
            background: #2C5E8F;
            color: white;
        }
        
        .admins-table th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
            transition: opacity 0.3s ease;
        }
        
        .admins-table tbody {
            position: relative;
        }
        
        .admins-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.3s ease, opacity 0.3s ease;
            background: white;
        }
        
        .admins-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .admins-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .admins-table td {
            padding: 14px 20px;
            font-size: 14px;
            color: #333;
            transition: color 0.3s ease, opacity 0.3s ease;
        }
        
        .admins-table tbody tr:hover td {
            color: #2C5E8F;
        }
        
        .admin-badge-role {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #333;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            box-shadow: 0 2px 6px rgba(255, 215, 0, 0.3);
        }
        
        /* Fade transition for content */
        .fade-out {
            animation: fadeOut 0.2s ease forwards;
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease forwards;
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px; /* increase spacing between cards */
        }
        
        .student-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            width: 105%;
            box-shadow: 0 2px 10px rgba(44, 94, 143, 0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            border-left: 2px solid #5A9BD5;
        }
        
        .student-card.inactive {
            background: #fff8f8;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(90, 155, 213, 0.3);
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
            flex: 0 0 60px; /* prevent flex stretching/shrinking */
            aspect-ratio: 1 / 1; /* ensure square box */
            background: linear-gradient(135deg, #5A9BD5 0%, #2C5E8F 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            font-weight: bold;
            position: relative;
            /* allow status indicator to render fully outside the circle edge */
            overflow: visible;
        }

        /* Ensure image inside avatar always fills square and stays circular */
        .student-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
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
            z-index: 3;
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
            min-width: 0; /* allow long content to wrap instead of pushing avatar */
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
            white-space: normal;
            line-height: 1.25;
            overflow-wrap: anywhere; /* wrap very long emails */
            word-break: break-word;   /* prefer breaking long tokens instead of overflow */
            display: -webkit-box;     /* clamp to avoid awkward long tails */
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;    /* show up to 2 lines then ellipsis */
            overflow: hidden;
            text-overflow: ellipsis;
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
            color: #5A9BD5;
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
        /* Removed red dot prefix to restore simpler badge appearance */
        
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
            background: rgba(90, 155, 213, 0.95);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            z-index: 1000;
            display: none;
            animation: fadeInOut 2s ease-in-out;
            box-shadow: 0 2px 10px rgba(44, 94, 143, 0.3);
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

        // Admin Signup Modal Functions
        function openAdminSignupModal() {
            const modal = document.getElementById('adminSignupModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }

        function closeAdminSignupModal() {
            const modal = document.getElementById('adminSignupModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('adminSignupForm').reset();
            document.getElementById('signup-message').className = 'signup-message';
            document.getElementById('signup-message').textContent = '';
        }

        // Handle add user button click
        document.addEventListener('DOMContentLoaded', function() {
            const addUserBtn = document.querySelector('.add-user-btn');
            if (addUserBtn) {
                addUserBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    openAdminSignupModal();
                });
            }

            // Handle modal close button
            const closeBtn = document.querySelector('.close-modal');
            if (closeBtn) {
                closeBtn.addEventListener('click', closeAdminSignupModal);
            }

            // Close modal when clicking outside
            window.addEventListener('click', function(e) {
                const modal = document.getElementById('adminSignupModal');
                if (e.target === modal) {
                    closeAdminSignupModal();
                }
            });

            // Handle form submission
            const signupForm = document.getElementById('adminSignupForm');
            if (signupForm) {
                signupForm.addEventListener('submit', async function(e) {
                    e.preventDefault();

                    const username = document.getElementById('admin_username').value;
                    const email = document.getElementById('admin_email').value;
                    const password = document.getElementById('admin_password').value;
                    const confirmPassword = document.getElementById('admin_confirm_password').value;
                    const messageDiv = document.getElementById('signup-message');

                    // Validation
                    if (password !== confirmPassword) {
                        messageDiv.className = 'signup-message error';
                        messageDiv.textContent = 'Passwords do not match!';
                        return;
                    }

                    if (password.length < 6) {
                        messageDiv.className = 'signup-message error';
                        messageDiv.textContent = 'Password must be at least 6 characters long!';
                        return;
                    }

                    try {
                        const response = await fetch('create_admin.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `username=${encodeURIComponent(username)}&email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
                        });

                        const result = await response.json();

                        if (result.success) {
                            messageDiv.className = 'signup-message success';
                            messageDiv.textContent = result.message || 'Admin account created successfully!';
                            signupForm.reset();
                            
                            // Close modal after 2 seconds
                            setTimeout(() => {
                                closeAdminSignupModal();
                            }, 2000);
                        } else {
                            messageDiv.className = 'signup-message error';
                            messageDiv.textContent = result.message || 'Failed to create admin account!';
                        }
                    } catch (error) {
                        messageDiv.className = 'signup-message error';
                        messageDiv.textContent = 'An error occurred. Please try again.';
                        console.error('Error:', error);
                    }
                });
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
            <img src="img/group.png" alt="All Users" style="width:24px;height:24px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'; this.parentNode.insertBefore(document.createTextNode('👥 '), this);">
            All Users
        </h1>
        <div class="navbar-links">
            <a href="admin_dashboard.php" class="nav-link">
                <img src="img/ui.png" alt="Dashboard" style="width:18px;height:18px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'; this.parentNode.insertBefore(document.createTextNode('📊 '), this);">
                Dashboard
            </a>
            <a href="?logout=1" class="nav-link">Logout</a>
        </div>
    </nav>
    
    <!-- Admin Signup Modal -->
    <div id="adminSignupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create Admin Account</h2>
                <span class="close-modal">&times;</span>
            </div>
            <form id="adminSignupForm" method="POST">
                <div class="form-group">
                    <label for="admin_username">Username</label>
                    <input type="text" id="admin_username" name="admin_username" required>
                </div>
                <div class="form-group">
                    <label for="admin_email">Email</label>
                    <input type="email" id="admin_email" name="admin_email" required>
                </div>
                <div class="form-group">
                    <label for="admin_password">Password</label>
                    <input type="password" id="admin_password" name="admin_password" required>
                </div>
                <div class="form-group">
                    <label for="admin_confirm_password">Confirm Password</label>
                    <input type="password" id="admin_confirm_password" name="admin_confirm_password" required>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeAdminSignupModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Create Account</button>
                </div>
            </form>
            <div id="signup-message" class="signup-message"></div>
        </div>
    </div>
    
    <div class="container">
        <div class="page-header">
            <div class="page-header-text">
                <h2>Users Directory</h2>
                <p>Manage students and administrators</p>
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="tabs-container">
            <div class="search-header">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search by username or email..." onkeyup="searchTable()">
                    <span class="search-icon">🔍</span>
                </div>
                <a href="#" class="add-user-btn" title="Add New Admin">+</a>
            </div>
            
            <div class="tabs-nav">
                <button class="tab-button active" onclick="switchTab('students')" id="students-tab">
                    Students
                </button>
                <button class="tab-button" onclick="switchTab('admins')" id="admins-tab">
                    Admins
                </button>
            </div>
            
            <!-- Students Tab Content -->
            <div id="students-content" class="tab-content active">
                <?php if ($students_result->num_rows > 0): ?>
                    <div class="admins-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Logs</th>
                                    <th>Hours</th>
                                    <th>Last Log</th>
                                    <th>Joined Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($student = $students_result->fetch_assoc()): ?>
                                    <tr onclick="window.location.href='admin_student_detail.php?id=<?php echo $student['user_id']; ?>'" style="cursor: pointer;">
                                        <td><strong><?php echo htmlspecialchars($student['username']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo number_format($student['total_logs']); ?></td>
                                        <td><?php echo number_format($student['total_hours'], 1); ?></td>
                                        <td>
                                            <?php 
                                            if ($student['last_log_date']) {
                                                echo date('M d, Y', strtotime($student['last_log_date']));
                                            } else {
                                                echo 'No logs yet';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-students">
                        <div class="no-students-icon">👥</div>
                        <h3>No students found</h3>
                        <p>There are no registered students in the system yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Admins Tab Content -->
            <div id="admins-content" class="tab-content">
                <?php if ($admins_result->num_rows > 0): ?>
                    <div class="admins-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($admin = $admins_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($admin['user_id']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($admin['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-students">
                        <div class="no-students-icon">👤</div>
                        <h3>No admin accounts found</h3>
                        <p>There are currently no administrator accounts in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function searchTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            
            // Get the active tab content
            const activeContent = document.querySelector('.tab-content.active');
            if (!activeContent) return;
            
            const table = activeContent.querySelector('table');
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = tbody.getElementsByTagName('tr');
            
            // Loop through all table rows
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                // Check username (first column) and email (second column)
                if (cells.length > 1) {
                    const username = cells[0].textContent || cells[0].innerText;
                    const email = cells[1].textContent || cells[1].innerText;
                    
                    if (username.toLowerCase().indexOf(filter) > -1 || 
                        email.toLowerCase().indexOf(filter) > -1) {
                        found = true;
                    }
                }
                
                // Show or hide row
                if (found) {
                    rows[i].style.display = '';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        }
        
        function switchTab(tabName) {
            // Get current active content
            const currentContent = document.querySelector('.tab-content.active');
            
            // Don't do anything if clicking the same tab
            if (currentContent && currentContent.id === tabName + '-content') {
                return;
            }
            
            // Get only tbody for fade effect (not thead to avoid header blink)
            const currentTbody = currentContent ? currentContent.querySelector('tbody') : null;
            
            // Add fade out effect only to tbody
            if (currentTbody) {
                currentTbody.classList.add('fade-out');
            }
            
            // Wait for fade out, then switch content
            setTimeout(() => {
                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                // Remove active class from all tab buttons
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                });
                
                // Show selected tab content
                const newContent = document.getElementById(tabName + '-content');
                newContent.classList.add('active');
                
                // Add active class to selected tab button
                document.getElementById(tabName + '-tab').classList.add('active');
                
                // Clear search input and reset table display
                document.getElementById('searchInput').value = '';
                const allRows = newContent.querySelectorAll('tbody tr');
                allRows.forEach(row => row.style.display = '');
                
                // Add fade in effect only to new tbody
                const newTbody = newContent.querySelector('tbody');
                if (newTbody) {
                    newTbody.classList.add('fade-in');
                    // Remove fade-in class after animation
                    setTimeout(() => {
                        newTbody.classList.remove('fade-in');
                    }, 300);
                }
                
                // Remove fade-out class
                if (currentTbody) {
                    currentTbody.classList.remove('fade-out');
                }
            }, 200);
        }
    </script>
</body>
</html>
