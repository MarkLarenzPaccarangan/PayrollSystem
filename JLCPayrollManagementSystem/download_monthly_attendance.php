<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// Get parameters from URL
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

if ($employee_id == 0) {
    header("Location: attendance.php?error=invalid_employee");
    exit;
}

// Get month name
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));

// Get employee details
$emp_sql = "SELECT id, first_name, middle_name, last_name, position FROM employees WHERE id = ?";
$emp_stmt = $conn->prepare($emp_sql);
$emp_stmt->bind_param("i", $employee_id);
$emp_stmt->execute();
$emp_result = $emp_stmt->get_result();
$employee = $emp_result->fetch_assoc();

if (!$employee) {
    header("Location: attendance.php?error=employee_not_found");
    exit;
}

$full_name = $employee['first_name'] . ' ' . 
            (!empty($employee['middle_name']) ? $employee['middle_name'] . ' ' : '') . 
            $employee['last_name'];
$position = $employee['position'] ?? 'N/A';

// Get all attendance records for the selected month and year
$att_sql = "SELECT * FROM attendance 
            WHERE employee_id = ? 
            AND MONTH(date) = ? 
            AND YEAR(date) = ? 
            ORDER BY date ASC";
$att_stmt = $conn->prepare($att_sql);
$att_stmt->bind_param("iii", $employee_id, $selected_month, $selected_year);
$att_stmt->execute();
$att_result = $att_stmt->get_result();

// Get all dates in the month
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$all_dates = [];
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = sprintf("%04d-%02d-%02d", $selected_year, $selected_month, $day);
    $all_dates[$date] = [
        'date' => $date,
        'day' => $day,
        'day_name' => date('l', strtotime($date)),
        'is_weekend' => (date('N', strtotime($date)) >= 6),
        'attendance' => null
    ];
}

// Organize attendance data
$attendance_records = [];
while ($row = $att_result->fetch_assoc()) {
    $date = $row['date'];
    $attendance_records[$date] = $row;
    
    if (isset($all_dates[$date])) {
        $all_dates[$date]['attendance'] = $row;
    }
}

// Function to format time
// Function to format time - FIXED to handle 00:00:00 (midnight)
function formatTimeForMonthDownload($time) {
    if (empty($time) || $time === null) {
        return '-';
    }
    // Handle midnight (12:00 AM) specially
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
}

// Function to calculate hours - FIXED to handle 00:00:00 (midnight)
function calculateHoursForMonthDownload($time_in, $time_out) {
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

function calculateDailyHoursForMonthDownload($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    $total += calculateHoursForMonthDownload($time_in_am, $time_out_am);
    $total += calculateHoursForMonthDownload($time_in_pm, $time_out_pm);
    $total += calculateHoursForMonthDownload($time_in_night, $time_out_night);
    return $total;
}

// Calculate monthly totals
$total_present_days = 0;
$total_absent_days = 0;
$total_leave_days = 0;
$total_hours = 0;
$total_working_days = 0;

// Initialize counters
$present_count = 0;
$absent_count = 0;
$leave_count = 0;

foreach ($all_dates as $date => $data) {
    if ($data['attendance']) {
        $att = $data['attendance'];
        $daily_hours = calculateDailyHoursForMonthDownload(
            $att['time_in_am'], $att['time_out_am'],
            $att['time_in_pm'], $att['time_out_pm'],
            $att['time_in_night'], $att['time_out_night']
        );
        
        // Check leave_type first to determine if it's a leave day
        if (!empty($att['leave_type']) && $att['leave_type'] != 'None' && $att['leave_type'] != '') {
            // This is a leave day - count as leave regardless of status
            $leave_count++;
            $total_leave_days++;
        } 
        // Then check status for present/absent
        elseif ($att['status'] == 'Present') {
            $present_count++;
            $total_present_days++;
            $total_hours += $daily_hours;
        } elseif ($att['status'] == 'Absent') {
            $absent_count++;
            $total_absent_days++;
        } elseif ($att['status'] == 'On Leave') {
            // Fallback for backward compatibility
            $leave_count++;
            $total_leave_days++;
        } else {
            // If status not set but has time, consider present
            // AFTER - Fixed to include 12:00 AM (00:00:00) as valid time
$has_time = (!empty($att['time_in_am']) && $att['time_in_am'] !== '') ||
            (!empty($att['time_out_am']) && $att['time_out_am'] !== '') ||
            (!empty($att['time_in_pm']) && $att['time_in_pm'] !== '') ||
            (!empty($att['time_out_pm']) && $att['time_out_pm'] !== '') ||
            (!empty($att['time_in_night']) && $att['time_in_night'] !== '') ||
            (!empty($att['time_out_night']) && $att['time_out_night'] !== '');
            if ($has_time) {
                $present_count++;
                $total_present_days++;
                $total_hours += $daily_hours;
            } else {
                $absent_count++;
                $total_absent_days++;
            }
        }
    } else {
        // No attendance record for this date - count as absent only for weekdays
        if (!$data['is_weekend']) {
            $absent_count++;
            $total_absent_days++;
        }
    }
}

// Set the final totals
$total_present_days = $present_count;
$total_absent_days = $absent_count;
$total_leave_days = $leave_count;

// Set headers for Excel download with proper formatting
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="monthly_attendance_' . $employee_id . '_' . $month_name . '_' . $selected_year . '.xls"');
header('Cache-Control: max-age=0');

// Add meta tag to force Excel to treat numbers as numbers, not dates
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<meta name="ProgId" content="Excel">';
echo '<meta name="Generator" content="PHP">';
?>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 30px;
            color: #333;
        }
        
        /* Header Styles */
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
            font-size: 24px;
            margin: 5px 0;
        }

        .header h3 {
            color: #2E7D32;
            font-size: 20px;
            margin: 5px 0;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
            margin: 5px 0;
        }
        
        /* Employee Info Section */
        .info-section {
            background: #f5f5f5;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 6px solid #2E7D32;
            border-radius: 8px;
        }
        
        .info-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
        }
        
        .info-item {
            flex: 1;
            min-width: 200px;
        }
        
        .info-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 20px;
            font-weight: 700;
            color: #2E7D32;
        }
        
        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
            font-size: 13px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        th {
            background: #2E7D32;
            color: white;
            font-weight: 600;
            padding: 15px 10px;
            text-align: center;
            border: 1px solid #1B5E20;
            font-size: 14px;
        }
        
        td {
            padding: 12px 8px;
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
        
        /* Status Styles */
        .status-present {
            color: #28a745;
            font-weight: 700;
            background-color: #e8f5e9;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .status-absent {
            color: #dc3545;
            font-weight: 700;
            background-color: #fef5f5;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .status-leave {
            color: #ffc107;
            font-weight: 700;
            background-color: #fff9e6;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .status-no-record {
            color: #6c757d;
            font-weight: 700;
            background-color: #f0f0f0;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        /* Weekend Style */
        .weekend {
            background-color: #fff3cd;
        }
        
        .weekend td {
            background-color: #fff3cd;
        }
        
        /* Total Row */
        .total-row {
            background: #e8f5e9 !important;
            font-weight: 700;
        }
        
        .total-row td {
            background: #e8f5e9;
            border-top: 2px solid #2E7D32;
            border-bottom: 2px solid #2E7D32;
        }
        
        /* Time Display */
        .time-display {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        /* Leave Type Badge */
        .leave-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
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
        
        /* Text Alignment */
        .text-center {
            text-align: center;
        }
        
        .text-left {
            text-align: left;
        }
        
        .text-right {
            text-align: right;
        }
        
        /* Column Widths */
        .col-date { width: 10%; }
        .col-day { width: 6%; }
        .col-status { width: 8%; }
        .col-time { width: 7%; }
        .col-leave { width: 8%; }
        .col-hours { width: 6%; }
        .col-remarks { width: 12%; }
        
        /* Night Shift Style */
        .night-shift {
            background-color: #e3f2fd;
        }
        
        .night-shift-label {
            font-size: 10px;
            color: #0d47a1;
            font-weight: normal;
            display: block;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>MONTHLY ATTENDANCE RECORD</h1>
        <h2><?= htmlspecialchars($full_name) ?></h2>
        <h3><?= $month_name ?> <?= $selected_year ?></h3>
        <p>Generated on: <?= date('F j, Y \a\t h:i A') ?></p>
    </div>
    
    <!-- Employee Information -->
    <div class="info-section">
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Employee ID</div>
                <div class="info-value"><?= str_pad($employee_id, 5, '0', STR_PAD_LEFT) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Full Name</div>
                <div class="info-value"><?= htmlspecialchars($full_name) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Position</div>
                <div class="info-value"><?= htmlspecialchars($position) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Report Period</div>
                <div class="info-value"><?= $month_name ?> 1 - <?= $days_in_month ?>, <?= $selected_year ?></div>
            </div>
        </div>
    </div>
    
    <!-- SUMMARY CARDS SECTION - REMOVED as requested -->
    
    <!-- Attendance Table -->
    <table>
        <thead>
            <tr>
                <th class="col-date" rowspan="2">Date</th>
                <th class="col-day" rowspan="2">Day</th>
                <th class="col-status" rowspan="2">Status</th>
                <th colspan="2">Morning Shift</th>
                <th colspan="2">Afternoon Shift</th>
                <th colspan="2">Night Shift</th>
                <th class="col-hours" rowspan="2">Total Hours</th>
                <th class="col-leave" rowspan="2">Leave Type</th>
                <th class="col-site" rowspan="2">Site</th>
            </tr>
            <tr>
                <th class="col-time">Time In</th>
                <th class="col-time">Time Out</th>
                <th class="col-time">Time In</th>
                <th class="col-time">Time Out</th>
                <th class="col-time">Time In</th>
                <th class="col-time">Time Out</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $monthly_total_hours = 0;
            
            foreach ($all_dates as $date => $data): 
                $att = $data['attendance'];
                $row_class = $data['is_weekend'] ? 'weekend' : '';
                
                if ($att):
                    $daily_hours = calculateDailyHoursForMonthDownload(
                        $att['time_in_am'], $att['time_out_am'],
                        $att['time_in_pm'], $att['time_out_pm'],
                        $att['time_in_night'], $att['time_out_night']
                    );
                    $monthly_total_hours += $daily_hours;
                    
                    $has_time = (!empty($att['time_in_am']) && $att['time_in_am'] != '00:00:00') ||
                                (!empty($att['time_out_am']) && $att['time_out_am'] != '00:00:00') ||
                                (!empty($att['time_in_pm']) && $att['time_in_pm'] != '00:00:00') ||
                                (!empty($att['time_out_pm']) && $att['time_out_pm'] != '00:00:00') ||
                                (!empty($att['time_in_night']) && $att['time_in_night'] != '00:00:00') ||
                                (!empty($att['time_out_night']) && $att['time_out_night'] != '00:00:00');
                    
                    // Determine status based on leave_type first, then status field
                    if (!empty($att['leave_type']) && $att['leave_type'] != 'None' && $att['leave_type'] != '') {
                        $status_display = 'On Leave';
                        $status_class = 'status-leave';
                        
                        // Determine leave type class
                        $leave_type_class = '';
                        if (strpos(strtolower($att['leave_type'] ?? ''), 'sick') !== false) {
                            $leave_type_class = 'sick';
                        } elseif (strpos(strtolower($att['leave_type'] ?? ''), 'vacation') !== false) {
                            $leave_type_class = 'vacation';
                        } elseif (strpos(strtolower($att['leave_type'] ?? ''), 'emergency') !== false) {
                            $leave_type_class = 'emergency';
                        }
                    } elseif ($att['status'] == 'Present') {
                        $status_display = 'Present';
                        $status_class = 'status-present';
                    } elseif ($att['status'] == 'Absent') {
                        $status_display = 'Absent';
                        $status_class = 'status-absent';
                    } elseif ($att['status'] == 'On Leave') {
                        $status_display = 'On Leave';
                        $status_class = 'status-leave';
                    } elseif ($has_time) {
                        $status_display = 'Present';
                        $status_class = 'status-present';
                    } else {
                        $status_display = 'No Record';
                        $status_class = 'status-no-record';
                    }
                    
                    // Check if night shift has data
                    $has_night = (!empty($att['time_in_night']) && $att['time_in_night'] != '00:00:00') ||
                                 (!empty($att['time_out_night']) && $att['time_out_night'] != '00:00:00');
            ?>
            <tr class="<?= $row_class ?><?= $has_night ? ' night-shift' : '' ?>">
                <td class="text-center"><strong><?= date('M d, Y', strtotime($date)) ?></strong></td>
                <td class="text-center"><?= substr($data['day_name'], 0, 3) ?></td>
                <td class="text-center"><span class="<?= $status_class ?>"><?= $status_display ?></span></td>
                <td class="text-center time-display"><?= formatTimeForMonthDownload($att['time_in_am']) ?></td>
                <td class="text-center time-display"><?= formatTimeForMonthDownload($att['time_out_am']) ?></td>
                <td class="text-center time-display"><?= formatTimeForMonthDownload($att['time_in_pm']) ?></td>
                <td class="text-center time-display"><?= formatTimeForMonthDownload($att['time_out_pm']) ?></td>
                <td class="text-center time-display">
                    <?= formatTimeForMonthDownload($att['time_in_night']) ?>
                    <?php if($has_night): ?>
                        <span class="night-shift-label">Night</span>
                    <?php endif; ?>
                </td>
                <td class="text-center time-display">
                    <?= formatTimeForMonthDownload($att['time_out_night']) ?>
                    <?php if($has_night): ?>
                        <span class="night-shift-label">Night</span>
                    <?php endif; ?>
                </td>
                <td class="text-center"><strong><?= number_format($daily_hours, 2) ?></strong></td>
                <td class="text-center">
                    <?php if (!empty($att['leave_type']) && $att['leave_type'] != 'None'): ?>
                        <span class="leave-type-badge <?= $leave_type_class ?? '' ?>">
                            <?= htmlspecialchars($att['leave_type']) ?>
                        </span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td class="text-left"><?= !empty($att['site']) ? htmlspecialchars($att['site']) : '-' ?></td>
            </tr>
            <?php else: ?>
            <tr class="<?= $row_class ?>">
                <td class="text-center"><strong><?= date('M d, Y', strtotime($date)) ?></strong></td>
                <td class="text-center"><?= substr($data['day_name'], 0, 3) ?></td>
                <td class="text-center">
                    <?php if($data['is_weekend']): ?>
                        <span class="status-no-record">Weekend</span>
                    <?php else: ?>
                        <span class="status-absent">Absent</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-center">0.00</td>
                <td class="text-center">-</td>
                <td class="text-left">-</td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            
            <!-- Monthly Total Row -->
            <tr class="total-row">
                <td colspan="9" class="text-right"><strong>MONTHLY TOTAL:</strong></td>
                <td class="text-center"><strong><?= number_format($monthly_total_hours, 2) ?> hrs</strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
    
    <!-- Summary Information - KEPT as requested -->
    <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
        <div style="display: flex; flex-wrap: wrap; gap: 30px;">
            <div style="flex: 1; min-width: 200px;">
                <h4 style="color: #2E7D32; margin-bottom: 15px;">Attendance Summary</h4>
                <table style="width: 100%; border: none; margin: 0;">
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Total Calendar Days:</strong></td>
                        <td style="border: none; padding: 5px;"><?= $days_in_month ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Present Days:</strong></td>
                        <td style="border: none; padding: 5px; color: #28a745;"><?= $total_present_days ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Absent Days:</strong></td>
                        <td style="border: none; padding: 5px; color: #dc3545;"><?= $total_absent_days ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Leave Days:</strong></td>
                        <td style="border: none; padding: 5px; color: #ffc107;"><?= $total_leave_days ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Total Hours Worked:</strong></td>
                        <td style="border: none; padding: 5px; color: #17a2b8;"><?= number_format($monthly_total_hours, 2) ?> hrs</td>
                    </tr>
                </table>
            </div>
            
            <div style="flex: 1; min-width: 200px;">
                <h4 style="color: #2E7D32; margin-bottom: 15px;">Attendance Rate</h4>
                <?php 
                $total_accounted_days = $total_present_days + $total_absent_days + $total_leave_days;
                $attendance_rate = ($total_accounted_days > 0) ? round(($total_present_days / $total_accounted_days) * 100, 2) : 0;
                $absent_rate = ($total_accounted_days > 0) ? round(($total_absent_days / $total_accounted_days) * 100, 2) : 0;
                $leave_rate = ($total_accounted_days > 0) ? round(($total_leave_days / $total_accounted_days) * 100, 2) : 0;
                ?>
                <table style="width: 100%; border: none; margin: 0;">
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Attendance Rate:</strong></td>
                        <td style="border: none; padding: 5px; color: #28a745;"><?= $attendance_rate ?>%</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Absent Rate:</strong></td>
                        <td style="border: none; padding: 5px; color: #dc3545;"><?= $absent_rate ?>%</td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Leave Rate:</strong></td>
                        <td style="border: none; padding: 5px; color: #ffc107;"><?= $leave_rate ?>%</td>
                    </tr>
                </table>
            </div>
            
            <div style="flex: 1; min-width: 200px;">
                <h4 style="color: #2E7D32; margin-bottom: 15px;">Average per Day</h4>
                <?php 
                $avg_hours_per_day = ($total_present_days > 0) ? round($monthly_total_hours / $total_present_days, 2) : 0;
                ?>
                <table style="width: 100%; border: none; margin: 0;">
                    <tr>
                        <td style="border: none; padding: 5px;"><strong>Average Hours/Day:</strong></td>
                        <td style="border: none; padding: 5px;"><?= number_format($avg_hours_per_day, 2) ?> hrs</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- SHIFT LEGEND SECTION - REMOVED as requested -->
    
    <!-- FOOTER SECTION - REMOVED as requested -->
    
</body>
</html>
<?php
$conn->close();
?>