<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// Get parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if (empty($date_from) || empty($date_to)) {
    header("Location: attendance.php?error=missing_dates");
    exit;
}

// Build query
$sql = "SELECT 
            e.id AS employee_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.position,
            a.id as attendance_id,
            a.date,
            a.status,
            a.pm_status,
            a.night_status,
            a.time_in_am,
            a.time_out_am,
            a.time_in_pm,
            a.time_out_pm,
            a.time_in_night,
            a.time_out_night,
            a.site,
            a.leave_type,
            a.workday_type
        FROM employees e
        LEFT JOIN attendance a ON a.employee_id = e.id 
        WHERE a.date BETWEEN ? AND ?";

$params = [$date_from, $date_to];
$param_types = "ss";

if ($employee_id > 0) {
    $sql .= " AND e.id = ?";
    $params[] = $employee_id;
    $param_types .= "i";
}

$sql .= " ORDER BY e.last_name ASC, a.date ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Database error");
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get employee name if filtered
$employee_name = "All Employees";
if ($employee_id > 0) {
    $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?";
    $name_stmt = $conn->prepare($name_query);
    $name_stmt->bind_param("i", $employee_id);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($name_row = $name_result->fetch_assoc()) {
        $employee_name = $name_row['full_name'];
    }
    $name_stmt->close();
}

// Calculate totals
$total_hours = 0;
$present_count = 0;
$absent_count = 0;
$leave_count = 0;
$rows = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    
    // Count leave days based on leave_type first
    if (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '') {
        $leave_count++;
    } elseif ($row['status'] == 'Present') {
        $present_count++;
    } elseif ($row['status'] == 'On Leave') {
        $leave_count++;
    } else {
        $absent_count++;
    }
    
    // Calculate hours
    $total = calculateTotalHoursForDownload(
        $row['time_in_am'], $row['time_out_am'],
        $row['time_in_pm'], $row['time_out_pm'],
        $row['time_in_night'], $row['time_out_night']
    );
    $total_hours += floatval($total);
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Attendance_Report_' . $date_from . 'to' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Generate Excel file with adapted UI
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Attendance Report - ' . $date_from . ' to ' . $date_to . '</title>';
?>
<style>
    body {
        font-family: 'Arial', sans-serif;
        margin: 30px;
        color: #333;
    }
    
    .header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 3px solid #2E7D32;
    }
    
    .header h1 {
        color: #2E7D32;
        font-size: 28px;
        margin: 0 0 10px 0;
    }
    
    .header h2 {
        color: #1B5E20;
        font-size: 20px;
        margin: 5px 0;
        font-weight: normal;
    }
    
    .header p {
        color: #666;
        font-size: 14px;
        margin: 5px 0;
    }
    
    .info-section {
        background: #f5f5f5;
        padding: 20px;
        margin-bottom: 30px;
        border-left: 6px solid #2E7D32;
        border-radius: 8px;
    }
    
    .info-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .info-item {
        flex: 1;
        min-width: 150px;
    }
    
    .info-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .info-value {
        font-size: 16px;
        font-weight: 700;
        color: #2E7D32;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        font-size: 11px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    th {
        background: #2E7D32;
        color: white;
        font-weight: 600;
        padding: 10px 5px;
        text-align: center;
        border: 1px solid #1B5E20;
        font-size: 12px;
    }
    
    td {
        padding: 8px 5px;
        border: 1px solid #ddd;
        text-align: center;
        vertical-align: middle;
    }
    
    tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    
    tr:hover {
        background-color: #f1f8e9;
    }
    
    .status-present {
        color: #28a745;
        font-weight: 700;
        background-color: #e8f5e9;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-absent {
        color: #dc3545;
        font-weight: 700;
        background-color: #fef5f5;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-leave {
        color: #856404;
        font-weight: 700;
        background-color: #fff9e6;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-no-record {
        color: #6c757d;
        font-weight: 700;
        background-color: #f0f0f0;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .total-row {
        background: #e8f5e9 !important;
        font-weight: 700;
    }
    
    .total-row td {
        background: #e8f5e9;
        border-top: 2px solid #2E7D32;
        border-bottom: 2px solid #2E7D32;
    }
    
    .time-display {
        font-family: 'Courier New', monospace;
        font-size: 10px;
    }
    
    .leave-type-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 9px;
        font-weight: 600;
    }
    
    .leave-type-badge.sick {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .leave-type-badge.vacation {
        background-color: #d4edda;
        color: #155724;
    }
    
    .leave-type-badge.emergency {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .night-shift-badge {
        font-size: 8px;
        color: #0d47a1;
        font-weight: normal;
        display: block;
        text-transform: uppercase;
    }
    
    .footer {
        margin-top: 30px;
        padding-top: 15px;
        border-top: 2px solid #2E7D32;
        font-size: 11px;
        color: #666;
        text-align: center;
    }
    
    .footer p {
        margin: 3px 0;
    }
    
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <h1>ATTENDANCE REPORT</h1>
    <h2><?= date('F d, Y', strtotime($date_from)) ?> to <?= date('F d, Y', strtotime($date_to)) ?></h2>
    <p>Generated on: <?= date('F j, Y \a\t h:i A') ?></p>
</div>

<!-- Employee Information -->
<div class="info-section">
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Employee</div>
            <div class="info-value"><?= htmlspecialchars($employee_name) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Report Period</div>
            <div class="info-value"><?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Records</div>
            <div class="info-value"><?= count($rows) ?></div>
        </div>
    </div>
</div>

<!-- Attendance Table -->
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Employee</th>
            <th>Date</th>
            <th>Day</th>
            <th colspan="2">Morning</th>
            <th colspan="2">Afternoon</th>
            <th colspan="2">Night</th>
            <th>Status</th>
            <th>Leave Type</th>
            <th>Workday</th>
            <th>Total Hrs</th>
            <th>Site</th>
        </tr>
        <tr>
            <th colspan="4"></th>
            <th>In</th>
            <th>Out</th>
            <th>In</th>
            <th>Out</th>
            <th>In</th>
            <th>Out</th>
            <th colspan="5"></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $row): 
                $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                $day_name = date('D', strtotime($row['date']));
                
                // Format times
                $time_in_am = formatTimeForDownload($row['time_in_am']);
                $time_out_am = formatTimeForDownload($row['time_out_am']);
                $time_in_pm = formatTimeForDownload($row['time_in_pm']);
                $time_out_pm = formatTimeForDownload($row['time_out_pm']);
                $time_in_night = formatTimeForDownload($row['time_in_night']);
                $time_out_night = formatTimeForDownload($row['time_out_night']);
                
                // Determine status - check leave_type first
                if (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '') {
                    $status_display = 'On Leave';
                    $status_class = 'status-leave';
                } elseif ($row['status'] == 'Present') {
                    $status_display = 'Present';
                    $status_class = 'status-present';
                } elseif ($row['status'] == 'Absent') {
                    $status_display = 'Absent';
                    $status_class = 'status-absent';
                } elseif ($row['status'] == 'On Leave') {
                    $status_display = 'On Leave';
                    $status_class = 'status-leave';
                } else {
                    $status_display = 'No Record';
                    $status_class = 'status-no-record';
                }
                
                // Check if night shift has data
                $has_night = ($time_in_night != '-' || $time_out_night != '-');
                
                // Calculate total
                $total = calculateTotalHoursForDownload(
                    $row['time_in_am'], $row['time_out_am'],
                    $row['time_in_pm'], $row['time_out_pm'],
                    $row['time_in_night'], $row['time_out_night']
                );
                
                // Determine leave type class
                $leave_type_class = '';
                if (!empty($row['leave_type']) && $row['leave_type'] != 'None') {
                    if (strpos(strtolower($row['leave_type']), 'sick') !== false) {
                        $leave_type_class = 'sick';
                    } elseif (strpos(strtolower($row['leave_type']), 'vacation') !== false) {
                        $leave_type_class = 'vacation';
                    } elseif (strpos(strtolower($row['leave_type']), 'emergency') !== false) {
                        $leave_type_class = 'emergency';
                    }
                }
            ?>
            <tr>
                <td class="text-center"><?= $row['employee_id'] ?></td>
                <td class="text-left"><?= htmlspecialchars($full_name) ?></td>
                <td class="text-center"><?= date('M d, Y', strtotime($row['date'])) ?></td>
                <td class="text-center"><?= $day_name ?></td>
                <td class="text-center time-display"><?= $time_in_am ?></td>
                <td class="text-center time-display"><?= $time_out_am ?></td>
                <td class="text-center time-display"><?= $time_in_pm ?></td>
                <td class="text-center time-display"><?= $time_out_pm ?></td>
                <td class="text-center time-display">
                    <?= $time_in_night ?>
                    <?php if($has_night): ?>
                        <span class="night-shift-badge">Night</span>
                    <?php endif; ?>
                </td>
                <td class="text-center time-display">
                    <?= $time_out_night ?>
                    <?php if($has_night): ?>
                        <span class="night-shift-badge">Night</span>
                    <?php endif; ?>
                </td>
                <td class="text-center"><span class="<?= $status_class ?>"><?= $status_display ?></span></td>
                <td class="text-center">
                    <?php if (!empty($row['leave_type']) && $row['leave_type'] != 'None'): ?>
                        <span class="leave-type-badge <?= $leave_type_class ?>">
                            <?= htmlspecialchars($row['leave_type']) ?>
                        </span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= htmlspecialchars($row['workday_type'] ?? '-') ?></td>
                <td class="text-center"><strong><?= $total ?></strong></td>
                <td class="text-left"><?= htmlspecialchars($row['site'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="13" class="text-right"><strong>TOTAL HOURS:</strong></td>
                <td class="text-center"><strong><?= number_format($total_hours, 2) ?></strong></td>
                <td></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="15" style="text-align: center; padding: 30px; color: #666;">
                    No attendance records found for the selected period
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Summary Information -->
<?php if (!empty($rows)): 
    $total_accounted_days = $present_count + $absent_count + $leave_count;
    $attendance_rate = ($total_accounted_days > 0) ? round(($present_count / $total_accounted_days) * 100, 2) : 0;
    $absent_rate = ($total_accounted_days > 0) ? round(($absent_count / $total_accounted_days) * 100, 2) : 0;
    $leave_rate = ($total_accounted_days > 0) ? round(($leave_count / $total_accounted_days) * 100, 2) : 0;
    $avg_hours_per_day = ($present_count > 0) ? round($total_hours / $present_count, 2) : 0;
?>
<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
    <div style="display: flex; flex-wrap: wrap; gap: 30px;">
        <div style="flex: 1; min-width: 200px;">
            <h4 style="color: #2E7D32; margin-bottom: 15px;">Attendance Summary</h4>
            <table style="width: 100%; border: none; margin: 0;">
                <tr><td style="border: none; padding: 5px;"><strong>Total Records:</strong></td><td style="border: none; padding: 5px;"><?= count($rows) ?></td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Present Days:</strong></td><td style="border: none; padding: 5px; color: #28a745;"><?= $present_count ?></td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Absent Days:</strong></td><td style="border: none; padding: 5px; color: #dc3545;"><?= $absent_count ?></td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Leave Days:</strong></td><td style="border: none; padding: 5px; color: #ffc107;"><?= $leave_count ?></td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Total Hours:</strong></td><td style="border: none; padding: 5px; color: #17a2b8;"><?= number_format($total_hours, 2) ?> hrs</td></tr>
            </table>
        </div>
        
        <div style="flex: 1; min-width: 200px;">
            <h4 style="color: #2E7D32; margin-bottom: 15px;">Attendance Rate</h4>
            <table style="width: 100%; border: none; margin: 0;">
                <tr><td style="border: none; padding: 5px;"><strong>Present Rate:</strong></td><td style="border: none; padding: 5px; color: #28a745;"><?= $attendance_rate ?>%</td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Absent Rate:</strong></td><td style="border: none; padding: 5px; color: #dc3545;"><?= $absent_rate ?>%</td></tr>
                <tr><td style="border: none; padding: 5px;"><strong>Leave Rate:</strong></td><td style="border: none; padding: 5px; color: #ffc107;"><?= $leave_rate ?>%</td></tr>
            </table>
        </div>
        
        <div style="flex: 1; min-width: 200px;">
            <h4 style="color: #2E7D32; margin-bottom: 15px;">Average per Day</h4>
            <table style="width: 100%; border: none; margin: 0;">
                <tr><td style="border: none; padding: 5px;"><strong>Avg Hours/Day:</strong></td><td style="border: none; padding: 5px;"><?= number_format($avg_hours_per_day, 2) ?> hrs</td></tr>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="footer">
    <p>Attendance Management System - Official Report</p>
    <p>Generated on <?= date('F j, Y \a\t h:i A') ?></p>
</div>

</body>
</html>
<?php

$stmt->close();
$conn->close();

// ============================================
// HELPER FUNCTIONS - ONLY ONCE (NO DUPLICATES)
// ============================================

function formatTimeForDownload($time) {
    if (empty($time) || $time === null) {
        return '-';
    }
    // Handle midnight (12:00 AM) specially
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
}

function calculateHoursForDownload($time_in, $time_out) {
    // Check if times are empty
    if (empty($time_in) || empty($time_out)) {
        return 0;
    }
    
    // Special handling for midnight (12:00 AM) as time_out
    $is_midnight_out = ($time_out == '00:00:00');
    
    // Convert to timestamps
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return 0;
    }
    
    if ($is_midnight_out) {
        // For midnight (12:00 AM) as time_out, calculate hours until midnight of the same day
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        
        // Calculate hours from time_in to midnight
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        return max(0, $hours);
    }
    
    // Regular time calculation (not midnight)
    $time_out_ts = strtotime($time_out);
    
    if ($time_out_ts === false) {
        return 0;
    }
    
    // If time_out is less than time_in, it means it's next day
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400; // Add 24 hours
    }
    
    // Calculate hours
    $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
    
    // Prevent negative hours
    if ($hours < 0) {
        $hours = 0;
    }
    
    return $hours;
}

function calculateTotalHoursForDownload($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    
    // Calculate AM hours
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHoursForDownload($time_in_am, $time_out_am);
        $total += floatval($am_hours);
    }
    
    // Calculate PM hours
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHoursForDownload($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
    }
    
    // Calculate Night hours
    if (!empty($time_in_night) && !empty($time_out_night)) {
        $night_hours = calculateHoursForDownload($time_in_night, $time_out_night);
        $total += floatval($night_hours);
    }
    
    return number_format($total, 2);
}
?>