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
    die("Date range is required");
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
    
    // Count statuses - FIXED to check leave_type first
    if (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '') {
        $leave_count++;
    } elseif ($row['status'] == 'Present') {
        $present_count++;
    } elseif ($row['status'] == 'On Leave') {
        $leave_count++;
    } else {
        $absent_count++;
    }
    
    $total = calculateTotalHoursForPrint(
        $row['time_in_am'], $row['time_out_am'],
        $row['time_in_pm'], $row['time_out_pm'],
        $row['time_in_night'], $row['time_out_night']
    );
    $total_hours += floatval($total);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance Report - Print</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0.5in;
            font-size: 11px;
            line-height: 1.4;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #75e6da;
        }
        
        .report-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .report-title i {
            color: #75e6da;
            margin-right: 10px;
        }
        
        .report-date-range {
            font-size: 1rem;
            color: #666;
            background: #f8f9fa;
            padding: 8px 20px;
            border-radius: 25px;
            border: 1px solid #e0e0e0;
        }
        
        .summary-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            border: 1px solid #e0e0e0;
        }
        
        .summary-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-label {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .summary-value {
            font-weight: 600;
        }
        
        .summary-value.employee {
            color: #75e6da;
        }
        
        .summary-value.total-hours {
            color: #27ae60;
        }
        
        .summary-value.present {
            color: #28a745;
        }
        
        .summary-value.absent {
            color: #dc3545;
        }
        
        .summary-value.leave {
            color: #ffc107;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            font-size: 10px;
        }
        
        th {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
            color: white;
            padding: 10px 5px;
            font-weight: 600;
            text-align: center;
            border: 1px solid #5fd9c9;
            white-space: nowrap;
        }
        
        td {
            padding: 8px 5px;
            border: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
        }
        
        .status-present {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-absent {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .status-leave {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-no-record {
            background-color: #e9ecef;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .time-display {
            font-family: monospace;
            font-size: 0.9rem;
        }
        
        .pm-label {
            color: #f39c12;
            font-weight: 600;
        }
        
        .night-label {
            color: #9b59b6;
            font-weight: 600;
        }
        
        .leave-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .leave-badge.sick {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .leave-badge.vacation {
            background-color: #d4edda;
            color: #155724;
        }
        
        .leave-badge.emergency {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .workday-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }
        
        .total-hours {
            color: #27ae60;
            font-weight: 600;
        }
        
        .total-row {
            background: #f0f7f0;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 20px;
            text-align: right;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        @media print {
            body { 
                margin: 0.25in; 
                font-size: 9px;
            }
            .no-print { 
                display: none; 
            }
            th {
                background: #75e6da !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .status-present, .status-absent, .status-leave, .status-no-record,
            .leave-badge, .workday-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .no-print {
            text-align: center;
            margin-top: 20px;
        }
        
        .no-print button {
            padding: 8px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            margin: 0 5px;
        }
        
        .no-print button:hover {
            background: #2980b9;
        }
        
        .no-print button.print-btn {
            background: #75e6da;
            color: #2c3e50;
        }
        
        .no-print button.print-btn:hover {
            background: #5fd9c9;
        }
        
        .no-print button.close-btn {
            background: #95a5a6;
        }
        
        .no-print button.close-btn:hover {
            background: #7f8c8d;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-title">
            <i class="fas fa-calendar-check"></i> Attendance Report
        </div>
        <div class="report-date-range">
            <?= date('M d, Y', strtotime($date_from)) ?> to <?= date('M d, Y', strtotime($date_to)) ?>
        </div>
    </div>
    
    <div class="summary-info">
        <div class="summary-item">
            <span class="summary-label">Employee:</span>
            <span class="summary-value employee"><?= htmlspecialchars($employee_name) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Records:</span>
            <span class="summary-value"><?= count($rows) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Hours:</span>
            <span class="summary-value total-hours"><?= number_format($total_hours, 2) ?> hrs</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Present:</span>
            <span class="summary-value present"><?= $present_count ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Absent:</span>
            <span class="summary-value absent"><?= $absent_count ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">On Leave:</span>
            <span class="summary-value leave"><?= $leave_count ?></span>
        </div>
    </div>
    
     <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>AM Status</th>
                <th>AM In</th>
                <th>AM Out</th>
                <th>PM Status</th>
                <th>PM/Night In</th>
                <th>PM/Night Out</th>
                <th>Night Status</th>
                <th>Leave</th>
                <th>Workday Type</th>
                <th>Total Hrs</th>
                <th>Site</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                    
                    // Format status - FIXED to check leave_type first
                    if (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '') {
                        $am_status = 'On Leave';
                        $am_class = 'status-leave';
                    } else {
                        $am_status = $row['status'] ?? 'No Record';
                        $am_class = 'status-no-record';
                        if ($am_status == 'Present') $am_class = 'status-present';
                        elseif ($am_status == 'Absent') $am_class = 'status-absent';
                        elseif ($am_status == 'On Leave') $am_class = 'status-leave';
                    }
                    
                    $pm_status = $row['pm_status'] ?? 'No Record';
                    $pm_class = 'status-no-record';
                    if ($pm_status == 'Present') $pm_class = 'status-present';
                    elseif ($pm_status == 'Absent') $pm_class = 'status-absent';
                    elseif ($pm_status == 'On Leave') $pm_class = 'status-leave';
                    
                    $night_status = $row['night_status'] ?? 'No Record';
                    $night_class = 'status-no-record';
                    if ($night_status == 'Present') $night_class = 'status-present';
                    elseif ($night_status == 'Absent') $night_class = 'status-absent';
                    elseif ($night_status == 'On Leave') $night_class = 'status-leave';
                    
                    // Format times - FIXED to handle 00:00:00
                    $time_in_am = formatTimeForPrint($row['time_in_am']);
                    $time_out_am = formatTimeForPrint($row['time_out_am']);
                    $time_in_pm = formatTimeForPrint($row['time_in_pm']);
                    $time_out_pm = formatTimeForPrint($row['time_out_pm']);
                    $time_in_night = formatTimeForPrint($row['time_in_night']);
                    $time_out_night = formatTimeForPrint($row['time_out_night']);
                    
                    // Leave type class
                    $leave_class = '';
                    if (!empty($row['leave_type'])) {
                        if (strpos(strtolower($row['leave_type']), 'sick') !== false) {
                            $leave_class = 'sick';
                        } elseif (strpos(strtolower($row['leave_type']), 'vacation') !== false) {
                            $leave_class = 'vacation';
                        } elseif (strpos(strtolower($row['leave_type']), 'emergency') !== false) {
                            $leave_class = 'emergency';
                        }
                    }
                    
                    $total = calculateTotalHoursForPrint(
                        $row['time_in_am'], $row['time_out_am'],
                        $row['time_in_pm'], $row['time_out_pm'],
                        $row['time_in_night'], $row['time_out_night']
                    );
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($full_name) ?><br>
                            <small style="color: #666;">ID: <?= $row['employee_id'] ?></small>
                        </td>
                        <td style="text-align: center;"><?= date('M d, Y', strtotime($row['date'])) ?></td>
                        <td style="text-align: center;">
                            <span class="status-badge <?= $am_class ?>"><?= $am_status ?></span>
                        </td>
                        <td style="text-align: center; font-family: monospace;"><?= $time_in_am ?></td>
                        <td style="text-align: center; font-family: monospace;"><?= $time_out_am ?></td>
                        <td style="text-align: center;">
                            <span class="status-badge <?= $pm_class ?>"><?= $pm_status ?></span>
                        </td>
                        <td>
                            <?php if ($time_in_pm != '-'): ?>
                                <div><span class="pm-label">PM:</span> <?= $time_in_pm ?></div>
                            <?php endif; ?>
                            <?php if ($time_in_night != '-'): ?>
                                <div><span class="night-label">Night:</span> <?= $time_in_night ?></div>
                            <?php endif; ?>
                            <?php if ($time_in_pm == '-' && $time_in_night == '-'): ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($time_out_pm != '-'): ?>
                                <div><span class="pm-label">PM:</span> <?= $time_out_pm ?></div>
                            <?php endif; ?>
                            <?php if ($time_out_night != '-'): ?>
                                <div><span class="night-label">Night:</span> <?= $time_out_night ?></div>
                            <?php endif; ?>
                            <?php if ($time_out_pm == '-' && $time_out_night == '-'): ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <span class="status-badge <?= $night_class ?>"><?= $night_status ?></span>
                        </td>
                        <td style="text-align: center;">
                            <?php if (!empty($row['leave_type']) && $row['leave_type'] != 'None'): ?>
                                <span class="leave-badge <?= $leave_class ?>"><?= htmlspecialchars($row['leave_type']) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if (!empty($row['workday_type'])): ?>
                                <span class="workday-badge"><?= htmlspecialchars($row['workday_type']) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <span class="total-hours"><?= $total ?></span>
                        </td>
                        <td><?= htmlspecialchars($row['site'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="11" style="text-align: right;"><strong>TOTAL HOURS:</strong></td>
                    <td><strong><?= number_format($total_hours, 2) ?></strong></td>
                    <td></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="13" style="text-align: center; padding: 30px;">
                        <i class="fas fa-calendar-times" style="font-size: 2rem; color: #75e6da; margin-bottom: 10px; display: block;"></i>
                        No attendance records found for the selected period
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="footer">
        Report ID: RPT-ATT-<?= date('Ymd') ?>-<?= strtoupper(substr(md5($date_from . $date_to . $employee_id), 0, 6)) ?><br>
        Generated by: <?= htmlspecialchars($_SESSION['Admin_User']) ?> | <?= date('F d, Y h:i:s A') ?>
    </div>
    
    <div class="no-print">
        <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <button class="close-btn" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
    </div>
    
    <script>
        window.onload = function() {
            // Uncomment the line below if you want auto-print
            // window.print();
        }
    </script>
</body>
</html>
<?php
// ============================================
// HELPER FUNCTIONS - FIXED FOR MIDNIGHT
// ============================================

function formatTimeForPrint($time) {
    if (empty($time) || $time === null) {
        return '-';
    }
    // Handle midnight (12:00 AM) specially
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
}

function calculateTotalHoursForPrint($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    
    // Calculate AM hours - FIXED to include 00:00:00
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHoursForPrint($time_in_am, $time_out_am);
        $total += floatval($am_hours);
    }
    
    // Calculate PM hours - FIXED to include 00:00:00
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHoursForPrint($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
    }
    
    // Calculate Night hours - FIXED to include 00:00:00
    if (!empty($time_in_night) && !empty($time_out_night)) {
        $night_hours = calculateHoursForPrint($time_in_night, $time_out_night);
        $total += floatval($night_hours);
    }
    
    return number_format($total, 2);
}

function calculateHoursForPrint($time_in, $time_out) {
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
?>