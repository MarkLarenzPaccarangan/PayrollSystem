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

// Get all distinct sites that have attendance records within the date range
$sites_query = "SELECT DISTINCT site_name 
                FROM (
                    SELECT site_assignment_am as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_am IS NOT NULL AND site_assignment_am != ''
                    UNION
                    SELECT site_assignment_pm as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_pm IS NOT NULL AND site_assignment_pm != ''
                    UNION
                    SELECT site_assignment_night as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_night IS NOT NULL AND site_assignment_night != ''
                ) AS sites
                ORDER BY site_name";

$sites_stmt = $conn->prepare($sites_query);
$sites_stmt->bind_param("ssssss", $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
$sites_stmt->execute();
$sites_result = $sites_stmt->get_result();

$sites = [];
while ($site_row = $sites_result->fetch_assoc()) {
    $sites[] = $site_row['site_name'];
}
$sites_stmt->close();

// For each site, get attendance records with session-specific display
$site_data = [];
$grand_total_records = 0;

// Helper function to format time
$formatTime = function($time) {
    if (empty($time) || $time === null || $time == '00:00:00') {
        return '--:--';
    }
    return date('h:i A', strtotime($time));
};

foreach ($sites as $site_name) {
    // Build query for this specific site
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
                a.remarks,
                a.leave_type,
                a.workday_type,
                a.site_assignment_am,
                a.site_assignment_pm,
                a.site_assignment_night
            FROM employees e
            INNER JOIN attendance a ON a.employee_id = e.id 
            WHERE a.date BETWEEN ? AND ?
            AND (
                a.site_assignment_am = ? OR 
                a.site_assignment_pm = ? OR 
                a.site_assignment_night = ?
            )";
    
    $params = [$date_from, $date_to, $site_name, $site_name, $site_name];
    $param_types = "sssss";
    
    if ($employee_id > 0) {
        $sql .= " AND e.id = ?";
        $params[] = $employee_id;
        $param_types .= "i";
    }
    
    $sql .= " ORDER BY e.last_name ASC, a.date ASC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        continue;
    }
    
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $site_rows = [];
    $site_present_count = 0;
    $site_absent_count = 0;
    $site_leave_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Check if employee is on leave globally
        $is_on_leave = (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '');
        
        // ============================================
        // SESSION-SPECIFIC DISPLAY LOGIC
        // For each session, only show data if THIS site was assigned
        // ============================================
        
        // ----- AM SESSION -----
        if ($row['site_assignment_am'] == $site_name) {
            if ($is_on_leave) {
                $row['status_am_display'] = 'On Leave';
                $row['time_in_am_display'] = '--:--';
                $row['time_out_am_display'] = '--:--';
            } else {
                $row['status_am_display'] = $row['status'] ?? 'Present';
                $row['time_in_am_display'] = $formatTime($row['time_in_am']);
                $row['time_out_am_display'] = $formatTime($row['time_out_am']);
            }
            $am_assigned = true;
        } else {
            $row['status_am_display'] = 'No Record';
            $row['time_in_am_display'] = '--:--';
            $row['time_out_am_display'] = '--:--';
            $am_assigned = false;
        }
        
        // ----- PM SESSION -----
        if ($row['site_assignment_pm'] == $site_name) {
            if ($is_on_leave) {
                $row['status_pm_display'] = 'On Leave';
                $row['time_in_pm_display'] = '--:--';
                $row['time_out_pm_display'] = '--:--';
            } else {
                $row['status_pm_display'] = $row['pm_status'] ?? 'Present';
                $row['time_in_pm_display'] = $formatTime($row['time_in_pm']);
                $row['time_out_pm_display'] = $formatTime($row['time_out_pm']);
            }
            $pm_assigned = true;
        } else {
            $row['status_pm_display'] = 'No Record';
            $row['time_in_pm_display'] = '--:--';
            $row['time_out_pm_display'] = '--:--';
            $pm_assigned = false;
        }
        
        // ----- NIGHT SESSION -----
        if ($row['site_assignment_night'] == $site_name) {
            if ($is_on_leave) {
                $row['status_night_display'] = 'On Leave';
                $row['time_in_night_display'] = '--:--';
                $row['time_out_night_display'] = '--:--';
            } else {
                $row['status_night_display'] = $row['night_status'] ?? 'Present';
                $row['time_in_night_display'] = $formatTime($row['time_in_night']);
                $row['time_out_night_display'] = $formatTime($row['time_out_night']);
            }
            $night_assigned = true;
        } else {
            $row['status_night_display'] = 'No Record';
            $row['time_in_night_display'] = '--:--';
            $row['time_out_night_display'] = '--:--';
            $night_assigned = false;
        }
        
        // ============================================
        // DETERMINE OVERALL STATUS FOR THIS SITE
        // ============================================
        $has_present_at_this_site = false;
        $has_any_assignment = false;
        
        if ($am_assigned && $row['status_am_display'] == 'Present') $has_present_at_this_site = true;
        if ($pm_assigned && $row['status_pm_display'] == 'Present') $has_present_at_this_site = true;
        if ($night_assigned && $row['status_night_display'] == 'Present') $has_present_at_this_site = true;
        
        if ($am_assigned || $pm_assigned || $night_assigned) {
            $has_any_assignment = true;
        }
        
        // Count for site summary
        if ($is_on_leave) {
            $site_leave_count++;
            $row['overall_status_display'] = 'On Leave';
        } elseif ($has_present_at_this_site) {
            $site_present_count++;
            $row['overall_status_display'] = 'Present';
        } elseif ($has_any_assignment) {
            $site_absent_count++;
            $row['overall_status_display'] = 'Absent';
        } else {
            $site_absent_count++;
            $row['overall_status_display'] = 'No Record';
        }
        
        // Store display values
        $row['time_in_am'] = $row['time_in_am_display'];
        $row['time_out_am'] = $row['time_out_am_display'];
        $row['time_in_pm'] = $row['time_in_pm_display'];
        $row['time_out_pm'] = $row['time_out_pm_display'];
        $row['time_in_night'] = $row['time_in_night_display'];
        $row['time_out_night'] = $row['time_out_night_display'];
        $row['status'] = $row['status_am_display'];
        $row['pm_status'] = $row['status_pm_display'];
        $row['night_status'] = $row['status_night_display'];
        
        $site_rows[] = $row;
    }
    
    $site_data[] = [
        'site_name' => $site_name,
        'rows' => $site_rows,
        'present_count' => $site_present_count,
        'absent_count' => $site_absent_count,
        'leave_count' => $site_leave_count,
        'record_count' => count($site_rows)
    ];
    
    $grand_total_records += count($site_rows);
    
    $stmt->close();
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Attendance_Report_Grouped_By_Site_' . $date_from . '_to_' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Generate Excel file with grouped by site
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Attendance Report Grouped By Site - ' . $date_from . ' to ' . $date_to . '</title>';
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
    
    .site-section {
        margin-top: 40px;
        margin-bottom: 40px;
        page-break-inside: avoid;
    }
    
    .site-title {
        background: linear-gradient(135deg, #2E7D32, #1B5E20);
        color: #2E7D32;
        padding: 12px 20px;
        border-radius: 8px 8px 0 0;
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 0;
    }
    
    .site-title i {
        margin-right: 10px;
        color: #2E7D32;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0;
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
    
    /* Summary Styles - no border */
    .summary-container {
        margin-top: 20px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: none;
    }
    
    .summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .summary-box {
        flex: 1;
        min-width: 200px;
    }
    
    .summary-box h4 {
        color: #2E7D32;
        margin-bottom: 12px;
        font-size: 13px;
        font-weight: 700;
    }
    
    .summary-table {
        width: 100%;
        border: none;
        margin: 0;
        box-shadow: none;
    }
    
    .summary-table td {
        border: none;
        padding: 6px 0;
        text-align: left;
        background: transparent;
    }
    
    .summary-table td:first-child {
        font-weight: 600;
        color: #555;
        width: 50%;
    }
    
    .summary-table td:last-child {
        font-weight: 700;
        color: #2c3e50;
    }
    
    .summary-table .present-value {
        color: #28a745;
    }
    
    .summary-table .absent-value {
        color: #dc3545;
    }
    
    .summary-table .leave-value {
        color: #ffc107;
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
    
    .no-data {
        text-align: center;
        padding: 30px;
        color: #666;
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
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
            <div class="info-label">Total Sites</div>
            <div class="info-value"><?= count($sites) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Records</div>
            <div class="info-value"><?= $grand_total_records ?></div>
        </div>
    </div>
</div>

<?php if (empty($site_data)): ?>
    <div class="no-data">
        <p>No attendance records found for the selected period.</p>
    </div>
<?php else: ?>
    <?php foreach ($site_data as $site): ?>
        <?php if (empty($site['rows'])): ?>
            <?php continue; ?>
        <?php endif; ?>
        
        <!-- Site Section -->
        <div class="site-section">
            <!-- Site Header - Green text on dark background -->
            <div class="site-title">
                <i class="fas fa-building"></i> SITE: <?= htmlspecialchars($site['site_name']) ?>
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
                        <th>Remarks</th>
                    </tr>
                    <tr>
                        <th colspan="4"></th>
                        <th>In</th>
                        <th>Out</th>
                        <th>In</th>
                        <th>Out</th>
                        <th>In</th>
                        <th>Out</th>
                        <th colspan="4"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($site['rows'] as $row): 
                        $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                        $day_name = date('D', strtotime($row['date']));
                        
                        // Format times - already formatted above
                        $time_in_am = $row['time_in_am'];
                        $time_out_am = $row['time_out_am'];
                        $time_in_pm = $row['time_in_pm'];
                        $time_out_pm = $row['time_out_pm'];
                        $time_in_night = $row['time_in_night'];
                        $time_out_night = $row['time_out_night'];
                        
                        // Determine status class for overall status
                        $status_class = '';
                        $status_display = $row['overall_status_display'] ?? 'No Record';
                        
                        if ($status_display == 'Present') $status_class = 'status-present';
                        elseif ($status_display == 'Absent') $status_class = 'status-absent';
                        elseif ($status_display == 'On Leave') $status_class = 'status-leave';
                        else $status_class = 'status-no-record';
                        
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
                            <td class="text-center time-display"><?= $time_in_night ?></td>
                            <td class="text-center time-display"><?= $time_out_night ?></td>
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
                            <td class="text-left"><?= htmlspecialchars($row['remarks'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Site Summary - Enhanced (2 columns, no total hours, no border) -->
            <?php 
                $site_total_accounted = $site['present_count'] + $site['absent_count'] + $site['leave_count'];
                $site_present_rate = ($site_total_accounted > 0) ? round(($site['present_count'] / $site_total_accounted) * 100, 2) : 0;
                $site_absent_rate = ($site_total_accounted > 0) ? round(($site['absent_count'] / $site_total_accounted) * 100, 2) : 0;
                $site_leave_rate = ($site_total_accounted > 0) ? round(($site['leave_count'] / $site_total_accounted) * 100, 2) : 0;
            ?>
            <div class="summary-container">
                <div class="summary-grid">
                    <!-- Attendance Summary Column -->
                    <div class="summary-box">
                        <h4>ATTENDANCE SUMMARY</h4>
                        <table class="summary-table">
                            <tr>
                                <td>Total Records:</td>
                                <td><?= $site['record_count'] ?></td>
                            </tr>
                            <tr>
                                <td>Present Days:</td>
                                <td class="present-value"><?= $site['present_count'] ?></td>
                            </tr>
                            <tr>
                                <td>Absent Days:</td>
                                <td class="absent-value"><?= $site['absent_count'] ?></td>
                            </tr>
                            <tr>
                                <td>Leave Days:</td>
                                <td class="leave-value"><?= $site['leave_count'] ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Attendance Rate Column -->
                    <div class="summary-box">
                        <h4>ATTENDANCE RATE</h4>
                        <table class="summary-table">
                            <tr>
                                <td>Present Rate:</td>
                                <td class="present-value"><?= $site_present_rate ?>%</td>
                            </tr>
                            <tr>
                                <td>Absent Rate:</td>
                                <td class="absent-value"><?= $site_absent_rate ?>%</td>
                            </tr>
                            <tr>
                                <td>Leave Rate:</td>
                                <td class="leave-value"><?= $site_leave_rate ?>%</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Footer -->
<div class="footer">
    <p>Attendance Management System - Official Report (Grouped by Site)</p>
    <p>Generated on <?= date('F j, Y \a\t h:i A') ?></p>
</div>

</body>
</html>
<?php

$conn->close();

// ============================================
// HELPER FUNCTION
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
?>