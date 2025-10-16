<?php
session_start();
// load DB connection (assumes db.php in same folder)
require_once __DIR__ . '/db.php';

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

$totalHours = 0.0;
$weeksRecorded = 0;
$avgPerWeek = 0.0;

// 1) Overall total hours (sum of grand_total)
if ($user_id > 0) {
    $sql = "SELECT COALESCE(SUM(grand_total), 0) AS total FROM weekly_accomplishments WHERE users_user_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $totalHours = floatval($r['total'] ?? 0);
        }
        $stmt->close();
    }

    // 2) Weeks recorded (distinct ISO year-week)
    $sql = "SELECT COUNT(DISTINCT CONCAT(YEAR(date_record), '-', WEEK(date_record,1))) AS wcount FROM weekly_accomplishments WHERE users_user_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $weeksRecorded = intval($r['wcount'] ?? 0);
        }
        $stmt->close();
    }

    // 3) Avg / Week: total for current ISO week (initial load)
    $currentWeek = intval(date('W'));
    $currentYear = intval(date('Y'));
    // Sum per-row using COALESCE to support different column names and ensure accurate aggregation
    $sql = "SELECT COALESCE(SUM(COALESCE(grand_total, total_hours, total, 0)), 0) AS week_total
            FROM weekly_accomplishments
            WHERE users_user_id = ? AND YEAR(date_record) = ? AND WEEK(date_record,1) = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('iii', $user_id, $currentYear, $currentWeek);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $avgPerWeek = round(floatval($r['week_total'] ?? 0), 2);
        }
        $stmt->close();
    }
}

// AJAX endpoint: return daily hours and totals for a selected ISO week
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'week_data') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['success' => false, 'daily_hours' => [], 'week_total' => 0, 'total_hours' => 0, 'weeksRecorded' => 0];
    $selWeek = isset($_POST['week']) ? intval($_POST['week']) : intval(date('W'));
    $selYear = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));

    // Get daily hours for the selected week
    $dailyHours = [];
    $weekTotal = 0.0;
    
    if ($user_id > 0) {
        // Get daily grand_total values for the selected week, ordered by date
        $dailySql = "SELECT date_record, grand_total 
                     FROM weekly_accomplishments 
                     WHERE users_user_id = ? 
                     AND YEAR(date_record) = ? 
                     AND WEEK(date_record,1) = ?
                     ORDER BY date_record ASC";
        
        if ($dailyStmt = $conn->prepare($dailySql)) {
            $dailyStmt->bind_param('iii', $user_id, $selYear, $selWeek);
            $dailyStmt->execute();
            $dailyRes = $dailyStmt->get_result();
            
            while ($row = $dailyRes->fetch_assoc()) {
                $date = $row['date_record'];
                $hours = floatval($row['grand_total'] ?? 0);
                $dailyHours[$date] = $hours;
                $weekTotal += $hours;
            }
            $dailyStmt->close();
        }
    }

    // total hours (overall) - reuse $totalHours computed earlier
    $overallTotal = isset($totalHours) ? floatval($totalHours) : 0.0;

    // weeks recorded - reuse $weeksRecorded computed earlier
    $wcount = isset($weeksRecorded) ? intval($weeksRecorded) : 0;

    $resp['success'] = true;
    $resp['daily_hours'] = $dailyHours;
    $resp['week_total'] = $weekTotal;
    $resp['total_hours'] = $overallTotal;
    $resp['weeksRecorded'] = $wcount;

    echo json_encode($resp);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Statistics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            overflow: hidden; /* Remove all body scrollbars */
            height: 100vh;
            width: 100vw;
        }
        
        .dashboard-container {
            width: 100%; /* occupy full available width within homepage */
            max-width: none; /* remove side gaps */
            margin: 0; /* align with page content edges */
            background-color: white;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden; /* Remove all scrollbars */
            animation: fadeIn 0.35s ease-out;
            margin-top: -25px;
        }
        
        .dashboard-header {
            color: black;
            padding: 10px 12px; /* even tighter */
            border-bottom: 1px solid #f0f0f0;
            border-radius: 0; /* flush with edges */
        }
        
        .dashboard-header h1 {
            font-size: 20px;
            font-weight: 600;   
            margin-bottom: 2px;
        }
        
        .dashboard-header p {
            opacity: 0.85;
            font-size: 13px;
            margin: 0;
        }
        
        .dashboard-content {
            padding: 8px 10px; /* tighter to save vertical space */
        }
        
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .week-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .week-selector label {
            font-weight: 600;
            color: #333;
        }
        
        select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: white;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        select:hover {
            border-color: #4b6cb7;
        }
        
        select:focus {
            outline: none;
            border-color: #4b6cb7;
            box-shadow: 0 0 0 3px rgba(75, 108, 183, 0.2);
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 8px;
            padding: 8px 10px;
            box-shadow: 
                0 6px 20px rgba(0, 0, 0, 0.08),
                0 2px 8px rgba(0, 0, 0, 0.04),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            border-left: 4px solid #4b6cb7;
            transition: transform 0.15s ease, box-shadow 0.3s ease;
            animation: slideUp 0.35s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 4s ease-in-out infinite;
            z-index: 1;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 
                0 10px 25px rgba(0, 0, 0, 0.12),
                0 4px 12px rgba(0, 0, 0, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 1);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .value {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-bottom: 4px;
            background: linear-gradient(45deg, #4b6cb7, #2ecc71);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: valueGlow 2s ease-in-out infinite;
            position: relative;
            z-index: 2;
        }
        
        @keyframes valueGlow {
            0%, 100% {
                text-shadow: 0 0 5px rgba(75, 108, 183, 0.3);
            }
            50% {
                text-shadow: 0 0 10px rgba(46, 204, 113, 0.4);
            }
        }
        
        .stat-card .change {
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .chart-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.12),
                0 4px 16px rgba(0, 0, 0, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            margin-bottom: 12px;
            animation: slideUp 0.5s ease-out, glowPulse 3s ease-in-out infinite;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden; /* Remove scrollbars */
        }
        
        .chart-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            animation: shimmer 3s ease-in-out infinite;
            z-index: 1;
        }
        
        .chart-container::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #4b6cb7, #2ecc71, #e74c3c, #f39c12);
            border-radius: 12px;
            z-index: -1;
            opacity: 0.1;
            animation: borderGlow 4s ease-in-out infinite;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
        }
        
        .chart-header h2 {
            font-size: 20px;
            color: #333;
            margin: 0;
            background: linear-gradient(45deg, #333, #4b6cb7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: headerPulse 3s ease-in-out infinite;
        }
        
        @keyframes headerPulse {
            0%, 100% {
                opacity: 0.9;
            }
            50% {
                opacity: 1;
            }
        }
        
        .chart-legend {
            display: flex;
            gap: 15px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
            padding: 5px 8px;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(5px);
        }
        
        .legend-item:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: scale(1.05);
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: colorPulse 2s ease-in-out infinite;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes colorPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
            }
        }
        
        .chart-wrapper {
            position: relative;
            /* slightly taller default height to restore vertical breathing room */
            height: 350px;
            min-height: 300px;
            max-height: 450px;
            width: 100%;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 8px;
            padding: 10px;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.1);
            z-index: 2;
            overflow: hidden; /* Remove chart scrollbars */
        }
        
        #performanceChart {
            width: 100% !important;
            height: 100% !important;
        }
        
        .week-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            animation: slideUp 0.9s ease-out;
        }
        
        .week-info h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
        }
        
        .week-info p {
            color: #666;
            line-height: 1.6;
            margin: 0;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes glowPulse {
            0%, 100% {
                box-shadow: 
                    0 8px 32px rgba(0, 0, 0, 0.12),
                    0 4px 16px rgba(0, 0, 0, 0.08),
                    inset 0 1px 0 rgba(255, 255, 255, 0.8);
            }
            50% {
                box-shadow: 
                    0 12px 40px rgba(75, 108, 183, 0.15),
                    0 6px 20px rgba(46, 204, 113, 0.1),
                    inset 0 1px 0 rgba(255, 255, 255, 0.9);
            }
        }
        
        @keyframes shimmer {
            0% {
                left: -100%;
            }
            100% {
                left: 100%;
            }
        }
        
        @keyframes borderGlow {
            0%, 100% {
                opacity: 0.1;
            }
            50% {
                opacity: 0.3;
            }
        }
        
        @keyframes dataUpdate {
            0% {
                transform: scaleY(0);
                opacity: 0.5;
            }
            50% {
                transform: scaleY(1.05);
                opacity: 0.8;
            }
            100% {
                transform: scaleY(1);
                opacity: 1;
            }
        }
        
        @media (max-width: 1024px) {
            .controls {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .chart-wrapper {
                height: 260px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>Work Hours Analytics Dashboard</h1>
            <p>Track your weekly performance from September 08 to October 10</p>
        </div>
        
        <div class="dashboard-content">
            <div class="controls">
                <div class="week-selector">
                    <label for="weekSelect">Select Week:</label>
                    <select id="weekSelect">
                        <?php
                        // Determine the earliest relevant start date for week options.
                        // Behavior desired:
                        // - If the logged-in user is "new" (users.is_new = 1), start the selector from the week containing users.created_at.
                        // - Otherwise, prefer the earliest weekly accomplishment record for this user, falling back to project start.
                        $projectStart = '2025-09-08';
                        $startDateForWeeks = $projectStart;

                        if (isset($conn) && isset($user_id) && $user_id) {
                            try {
                                // Use created_at from users when available to determine the start week.
                                // Do NOT rely on is_new here; created_at should drive the UI range when present.
                                $userSql = "SELECT created_at FROM users WHERE user_id = ? LIMIT 1";
                                if ($userStmt = $conn->prepare($userSql)) {
                                    $userStmt->bind_param('i', $user_id);
                                    $userStmt->execute();
                                    $userRes = $userStmt->get_result();
                                    if ($userRow = $userRes->fetch_assoc()) {
                                        $createdAt = !empty($userRow['created_at']) ? $userRow['created_at'] : null;

                                        if ($createdAt) {
                                            // Compute the Monday of the ISO week containing created_at
                                            try {
                                                $dt = new DateTime($createdAt);
                                                // Move to Monday of that ISO week
                                                $dt->modify('monday this week');
                                                $startDateForWeeks = $dt->format('Y-m-d');
                                            } catch (Exception $e) {
                                                // ignore and fall back
                                            }
                                        } else {
                                            // created_at missing: fall back to earliest recorded weekly_accomplishment for that user
                                            $minSql = "SELECT MIN(date_record) AS min_date FROM weekly_accomplishments WHERE users_user_id = ?";
                                            if ($minStmt = $conn->prepare($minSql)) {
                                                $minStmt->bind_param('i', $user_id);
                                                $minStmt->execute();
                                                $minRes = $minStmt->get_result();
                                                if ($minRow = $minRes->fetch_assoc()) {
                                                    if (!empty($minRow['min_date'])) {
                                                        $startDateForWeeks = $minRow['min_date'];
                                                    }
                                                }
                                                $minStmt->close();
                                            }
                                        }
                                    }
                                    $userStmt->close();
                                }
                            } catch (Exception $e) {
                                // ignore and use project start fallback
                            }
                        }

                        // build options from this week backwards to the start date (Mon..Sat week ranges)
                        $today = new DateTime();
                        $thisWeekStart = clone $today;
                        // ensure we are at Monday of this ISO week
                        $thisWeekStart->modify('monday this week');

                        $weekCount = 0;
                        $weekStart = clone $thisWeekStart;
                        $startBoundary = new DateTime($startDateForWeeks);
                        while ($weekStart >= $startBoundary) {
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
                            $wkValue = $weekStart->format('W');
                            $wkYear = $weekStart->format('Y');
                            $selected = ($weekStart->format('W') == date('W') && $weekStart->format('Y') == date('Y')) ? ' selected' : '';
                            echo '<option value="' . htmlspecialchars($wkValue) . '" data-year="' . htmlspecialchars($wkYear) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';

                            // step back one week
                            $weekStart->modify('-1 week');
                            $weekCount++;
                        }
                        ?>
                    </select>
                </div>
                
                <div class="current-date">
                    Current Date: <strong><?= date('F j, Y') ?></strong>
                </div>
            </div>
            
            <div class="stats-cards">
                <div class="stat-card" style="animation-delay: 0.1s">
                    <h3>Overall Hours Of Weeks</h3>
                    <div class="value" id="totalHours"><?= htmlspecialchars(number_format(round($totalHours))) ?></div>
                    <div class="change">Total recorded hours</div>
                </div>
                
                <div class="stat-card" style="animation-delay: 0.2s">
                    <h3>Weeks Recorded</h3>
                    <div class="value" id="weeksRecorded"><?= htmlspecialchars($weeksRecorded) ?></div>
                    <div class="change">Total weeks with data</div>
                </div>
                
                <div class="stat-card" style="animation-delay: 0.3s">
                    <h3>Total / Selected Week</h3>
                    <div class="value" id="avgPerWeek"><?= htmlspecialchars(number_format(round($avgPerWeek))) ?></div>
                    <div class="change">Total hours for selected week</div>
                </div>
            </div>
            
            <div class="chart-container">
                <div class="chart-header">
                    <h2>Daily Hours Achievement</h2>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #4b6cb7"></div>
                            <span>Hours Worked</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #2ecc71"></div>
                            <span>Target (8 hours)</span>
                        </div>
                    </div>
                </div>
                
                <div class="chart-wrapper">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
            
            <div class="week-info">
                <h3 id="weekTitle">Week Summary</h3>
                <p id="weekSummary">This section summarizes the selected week. Use the week selector above to switch.</p>
            </div>
        </div>
    </div>

    <script>
        // Initialize analytics when loaded
        function initializeAnalytics() {
            console.log('Analytics initialized');
            
            // Simple chart initialization
            function initializeChart() {
                const ctx = document.getElementById('performanceChart');
                
                if (!ctx) {
                    console.error('Canvas element not found');
                    return;
                }
                
                // Destroy existing chart if it exists
                if (window.performanceChartInstance) {
                    window.performanceChartInstance.destroy();
                }
                
                // Create the chart with empty initial data
                window.performanceChartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                        datasets: [
                            {
                                label: 'Hours Worked',
                                data: [0, 0, 0, 0, 0, 0],
                                backgroundColor: function(context) {
                                    const chart = context.chart;
                                    const {ctx, chartArea} = chart;
                                    
                                    if (!chartArea) {
                                        return '#4b6cb7';
                                    }
                                    
                                    const value = context.raw;
                                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                    
                                    if (value >= 8) {
                                        gradient.addColorStop(0, 'rgba(46, 204, 113, 0.8)');
                                        gradient.addColorStop(0.5, 'rgba(46, 204, 113, 0.9)');
                                        gradient.addColorStop(1, 'rgba(39, 174, 96, 1)');
                                    } else {
                                        gradient.addColorStop(0, 'rgba(75, 108, 183, 0.7)');
                                        gradient.addColorStop(0.5, 'rgba(75, 108, 183, 0.8)');
                                        gradient.addColorStop(1, 'rgba(52, 73, 94, 1)');
                                    }
                                    
                                    return gradient;
                                },
                                borderColor: function(context) {
                                    const value = context.raw;
                                    return value >= 8 ? 'rgba(39, 174, 96, 1)' : 'rgba(52, 73, 94, 1)';
                                },
                                borderWidth: 2,
                                borderRadius: 6,
                                borderSkipped: false,
                                barPercentage: 0.7,
                                categoryPercentage: 0.8,
                                shadowOffsetX: 3,
                                shadowOffsetY: 3,
                                shadowBlur: 10,
                                shadowColor: 'rgba(0, 0, 0, 0.2)',
                            },
                            {
                                label: 'Target (8 hours)',
                                data: [8, 8, 8, 8, 8, 8],
                                type: 'line',
                                borderColor: 'rgba(231, 76, 60, 0.9)',
                                backgroundColor: 'rgba(231, 76, 60, 0.1)',
                                borderWidth: 3,
                                borderDash: [8, 4],
                                fill: false,
                                pointRadius: 5,
                                pointBackgroundColor: 'rgba(231, 76, 60, 1)',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointHoverRadius: 7,
                                pointHoverBackgroundColor: 'rgba(231, 76, 60, 1)',
                                pointHoverBorderColor: '#ffffff',
                                pointHoverBorderWidth: 3,
                                tension: 0.1,
                                shadowOffsetX: 2,
                                shadowOffsetY: 2,
                                shadowBlur: 5,
                                shadowColor: 'rgba(231, 76, 60, 0.3)',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeInOutQuart',
                            onProgress: function(animation) {
                                const chart = animation.chart;
                                const ctx = chart.ctx;
                                ctx.save();
                                ctx.shadowColor = 'rgba(0, 0, 0, 0.3)';
                                ctx.shadowBlur = 10;
                                ctx.shadowOffsetX = 3;
                                ctx.shadowOffsetY = 3;
                                ctx.restore();
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 10,
                                title: {
                                    display: true,
                                    text: 'Hours',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    },
                                    color: '#333'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + 'h';
                                    },
                                    font: {
                                        size: 12
                                    },
                                    color: '#666'
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)',
                                    lineWidth: 1,
                                    drawBorder: false
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Days',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    },
                                    color: '#333'
                                },
                                ticks: {
                                    font: {
                                        size: 12,
                                        weight: 'bold'
                                    },
                                    color: '#666'
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: {
                                        size: 13,
                                        weight: 'bold'
                                    },
                                    color: '#333',
                                    padding: 20
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                borderColor: 'rgba(255, 255, 255, 0.2)',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true,
                                boxPadding: 6,
                                usePointStyle: true,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += context.parsed.y + ' hours';
                                        
                                        if (context.datasetIndex === 0) {
                                            const value = context.parsed.y;
                                            if (value >= 8) {
                                                label += ' ✨ Target Met';
                                            } else {
                                                label += ' 🎯 Target Not Met';
                                            }
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        onHover: (event, elements) => {
                            event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                        }
                    }
                });
                
                // Set up week selector functionality
                const weekSelect = document.getElementById('weekSelect');
                if (weekSelect) {
                    function loadWeekData(wk, yr) {
                        const form = new FormData();
                        form.append('ajax', 'week_data');
                        form.append('week', wk);
                        form.append('year', yr);

                        fetch('analytics.php', { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.success) {
                                    // Function to animate number changes
                                    function animateValue(element, start, end, duration) {
                                        if (start === end) return;
                                        const range = end - start;
                                        let current = start;
                                        const increment = end > start ? 1 : -1;
                                        const stepTime = Math.abs(Math.floor(duration / range));
                                        const timer = setInterval(function() {
                                            current += increment;
                                            element.textContent = Math.round(current);
                                            if (current == end) {
                                                clearInterval(timer);
                                            }
                                        }, stepTime);
                                    }

                                    // Update stat cards with animation
                                    const totalHoursEl = document.getElementById('totalHours');
                                    const weeksRecordedEl = document.getElementById('weeksRecorded');
                                    const avgPerWeekEl = document.getElementById('avgPerWeek');
                                    
                                    const currentTotal = parseInt(totalHoursEl.textContent) || 0;
                                    const currentWeeks = parseInt(weeksRecordedEl.textContent) || 0;
                                    const currentAvg = parseInt(avgPerWeekEl.textContent) || 0;
                                    
                                    const newTotal = Math.round(data.total_hours || 0);
                                    const newWeeks = data.weeksRecorded || 0;
                                    const newAvg = Math.round(data.week_total || 0);
                                    
                                    animateValue(totalHoursEl, currentTotal, newTotal, 800);
                                    animateValue(weeksRecordedEl, currentWeeks, newWeeks, 600);
                                    animateValue(avgPerWeekEl, currentAvg, newAvg, 700);

                                    // Update week info
                                    const selectedOption = weekSelect.options[weekSelect.selectedIndex];
                                    const weekTitle = selectedOption.textContent || ('Week ' + wk + '');
                                    document.getElementById('weekTitle').textContent = weekTitle + ' Summary';
                                    
                                    const weekTotal = Number(Math.round((data.week_total || 0)));
                                    let summaryText = `Total hours for selected week: ${weekTotal}h. `;
                                    
                                    if (weekTotal >= 40) {
                                        summaryText += "Excellent! You've met or exceeded the weekly target.";
                                    } else if (weekTotal >= 30) {
                                        summaryText += "Good progress! You're close to the weekly target.";
                                    } else {
                                        summaryText += "Keep working towards your weekly goals.";
                                    }
                                    
                                    document.getElementById('weekSummary').textContent = summaryText;

                                    // Update chart with actual daily data
                                    const dailyHours = mapDailyDataToChart(data.daily_hours || {});
                                    
                                    // Add smooth animation effect when updating data
                                    window.performanceChartInstance.data.datasets[0].data = dailyHours;
                                    
                                    // Add a subtle live update effect
                                    const chartContainer = document.querySelector('.chart-container');
                                    if (chartContainer) {
                                        chartContainer.style.animation = 'none';
                                        chartContainer.offsetHeight; // trigger reflow
                                        chartContainer.style.animation = 'dataUpdate 0.6s ease-in-out, glowPulse 3s ease-in-out infinite';
                                    }
                                    
                                    // Update chart with animation
                                    window.performanceChartInstance.update('active');
                                    
                                    // Add a brief highlight effect to show data is live
                                    setTimeout(() => {
                                        if (chartContainer) {
                                            chartContainer.style.boxShadow = '0 12px 40px rgba(75, 108, 183, 0.2), 0 6px 20px rgba(46, 204, 113, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.9)';
                                            setTimeout(() => {
                                                chartContainer.style.boxShadow = '';
                                            }, 800);
                                        }
                                    }, 100);
                                }
                            }).catch(err => console.error('Week data fetch failed', err));
                    }

                    function mapDailyDataToChart(dailyHours) {
                        const chartData = [0, 0, 0, 0, 0, 0];
                        
                        Object.keys(dailyHours).forEach(dateStr => {
                            const date = new Date(dateStr);
                            const dayOfWeek = date.getDay();
                            
                            if (dayOfWeek >= 1 && dayOfWeek <= 6) {
                                const chartIndex = dayOfWeek - 1;
                                chartData[chartIndex] = dailyHours[dateStr];
                            }
                        });
                        
                        return chartData;
                    }

                    // Load initial week data
                    const initialOption = weekSelect.options[weekSelect.selectedIndex];
                    const initialWeek = initialOption.value;
                    const initialYear = initialOption.getAttribute('data-year') || new Date().getFullYear();
                    loadWeekData(initialWeek, initialYear);

                    // Add change event listener
                    weekSelect.addEventListener('change', function() {
                        const sel = this.options[this.selectedIndex];
                        const wk = this.value;
                        const yr = sel.getAttribute('data-year') || new Date().getFullYear();
                        loadWeekData(wk, yr);
                    });
                }
            }

            // Initialize chart
            initializeChart();
        }

        // Initialize immediately if DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAnalytics);
        } else {
            initializeAnalytics();
        }
        
        // If embedded inside a parent layout (homepage), try to fit vertically to avoid scroll
        function fitDashboardToParent() {
            try {
                const container = document.querySelector('.dashboard-container');
                if (!container) return;
                const host = document.querySelector('.main-content') || (window.frameElement && window.frameElement.parentElement);
                let hostHeight = host ? (host.getBoundingClientRect().height) : window.innerHeight;
                if (!hostHeight || hostHeight < 200) hostHeight = window.innerHeight;

                // Reserve space for top nav/header and some breathing room (reduced to give more vertical room)
                const reserved = 60; // smaller reserved to allow more dashboard height
                const desired = Math.max(260, hostHeight - reserved);

                // Apply a hard cap to strongly avoid page scroll
                container.style.maxHeight = desired + 'px';
                container.style.overflow = 'hidden'; /* Remove container scrollbars */

                // Remove scrollbars from chart container completely
                const chart = document.querySelector('.chart-container');
                if (chart) {
                    chart.style.maxHeight = Math.max(160, desired * 0.5) + 'px';
                    chart.style.overflow = 'hidden'; /* Remove all chart scrollbars */
                }
                
                // Also ensure chart wrapper has no scrollbars
                const chartWrapper = document.querySelector('.chart-wrapper');
                if (chartWrapper) {
                    chartWrapper.style.overflow = 'hidden'; /* Remove wrapper scrollbars */
                }
            } catch (e) {
                // ignore
            }
        }

        // Run after layout and on resize
        window.addEventListener('load', fitDashboardToParent);
        window.addEventListener('resize', function() { setTimeout(fitDashboardToParent, 120); });
    </script>
</body>
</html>