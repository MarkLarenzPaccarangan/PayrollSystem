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

// For each site, get attendance records
$site_data = [];
$grand_total_records = 0;
$grand_total_hours = 0;
$grand_present_count = 0;
$grand_absent_count = 0;
$grand_leave_count = 0;

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
    
    $site_records = [];
    $site_total_hours = 0;
    $site_present_count = 0;
    $site_absent_count = 0;
    $site_leave_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Calculate total hours for this record
        $total = calculateTotalHoursForPrint(
            $row['time_in_am'], $row['time_out_am'],
            $row['time_in_pm'], $row['time_out_pm'],
            $row['time_in_night'], $row['time_out_night']
        );
        $row['total_hours'] = $total;
        $site_total_hours += floatval($total);
        
        // Format times for display
        $row['time_in_am_display'] = formatTimeForPrint($row['time_in_am']);
        $row['time_out_am_display'] = formatTimeForPrint($row['time_out_am']);
        $row['time_in_pm_display'] = formatTimeForPrint($row['time_in_pm']);
        $row['time_out_pm_display'] = formatTimeForPrint($row['time_out_pm']);
        $row['time_in_night_display'] = formatTimeForPrint($row['time_in_night']);
        $row['time_out_night_display'] = formatTimeForPrint($row['time_out_night']);
        
        // Format employee name
        $row['employee_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
        
        // ============================================
        // FIXED STATUS LOGIC - Checks ALL THREE SESSIONS
        // ============================================
        
        // Check if ON LEAVE first (highest priority)
        $is_on_leave = (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '');
        
        // Check if PRESENT in ANY session (AM, PM, or Night)
        $is_present = ($row['status'] == 'Present' || $row['pm_status'] == 'Present' || $row['night_status'] == 'Present');
        
        // Determine final status
        if ($is_on_leave) {
            $row['overall_status'] = 'On Leave';
            $site_leave_count++;
        } elseif ($is_present) {
            $row['overall_status'] = 'Present';
            $site_present_count++;
        } else {
            $row['overall_status'] = 'Absent';
            $site_absent_count++;
        }
        
        $site_records[] = $row;
    }
    
    $site_data[] = [
        'site_name' => $site_name,
        'records' => $site_records,
        'summary' => [
            'record_count' => count($site_records),
            'present_count' => $site_present_count,
            'absent_count' => $site_absent_count,
            'leave_count' => $site_leave_count,
            'total_hours' => number_format($site_total_hours, 2)
        ]
    ];
    
    $grand_total_records += count($site_records);
    $grand_total_hours += $site_total_hours;
    $grand_present_count += $site_present_count;
    $grand_absent_count += $site_absent_count;
    $grand_leave_count += $site_leave_count;
    
    $stmt->close();
}

// Calculate grand total rates
$grand_total_accounted = $grand_present_count + $grand_absent_count + $grand_leave_count;
$grand_present_rate = ($grand_total_accounted > 0) ? round(($grand_present_count / $grand_total_accounted) * 100, 2) : 0;
$grand_absent_rate = ($grand_total_accounted > 0) ? round(($grand_absent_count / $grand_total_accounted) * 100, 2) : 0;
$grand_leave_rate = ($grand_total_accounted > 0) ? round(($grand_leave_count / $grand_total_accounted) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance Report - Print (Grouped by Site)</title>
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
        
        .summary-value.total-sites {
            color: #2E7D32;
        }
        
        .summary-value.total-records {
            color: #3498db;
        }
        
        .site-section {
            margin-top: 30px;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .site-header {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            color: white;
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            font-size: 14px;
            font-weight: 700;
        }
        
        .site-header i {
            margin-right: 8px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e0e0e0;
            font-size: 9px;
        }
        
        th {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
            color: #2c3e50;
            padding: 8px 4px;
            font-weight: 600;
            text-align: center;
            border: 1px solid #5fd9c9;
            white-space: nowrap;
        }
        
        td {
            padding: 6px 4px;
            border: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
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
            font-size: 0.8rem;
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
            padding: 3px 6px;
            border-radius: 12px;
            font-size: 0.7rem;
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
            padding: 3px 6px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background-color: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }
        
        .total-hours {
            color: #27ae60;
            font-weight: 600;
        }
        
        .site-summary {
            background: #f8f9fa;
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 8px 8px;
            font-size: 0.8rem;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .site-summary span {
            margin-right: 15px;
        }
        
        .site-summary i {
            margin-right: 4px;
        }
        
        .grand-total-section {
            margin-top: 30px;
            padding: 20px;
            background: #e8f5e9;
            border-radius: 12px;
            border: 2px solid #2E7D32;
        }
        
        .grand-total-title {
            text-align: center;
            font-size: 1rem;
            font-weight: 700;
            color: #2E7D32;
            margin-bottom: 15px;
        }
        
        .grand-total-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
        }
        
        .grand-total-box {
            flex: 1;
            min-width: 200px;
        }
        
        .grand-total-box h4 {
            color: #2E7D32;
            margin-bottom: 10px;
            font-size: 0.85rem;
        }
        
        .grand-total-table {
            width: 100%;
            border: none;
        }
        
        .grand-total-table td {
            border: none;
            padding: 4px 0;
            background: transparent;
        }
        
        .grand-total-table td:first-child {
            font-weight: 600;
            color: #555;
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
            .site-header {
                background: #2E7D32 !important;
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
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #75e6da;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-title">
            <i class="fas fa-calendar-check"></i> Attendance Report (Grouped by Site)
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
            <span class="summary-label">Total Sites:</span>
            <span class="summary-value total-sites"><?= count($sites) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Records:</span>
            <span class="summary-value total-records"><?= $grand_total_records ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Present:</span>
            <span class="summary-value present"><?= $grand_present_count ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Absent:</span>
            <span class="summary-value absent"><?= $grand_absent_count ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">On Leave:</span>
            <span class="summary-value leave"><?= $grand_leave_count ?></span>
        </div>
    </div>
    
    <?php if (empty($site_data) || $grand_total_records == 0): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <p>No attendance records found for the selected period.</p>
        </div>
    <?php else: ?>
        <?php foreach ($site_data as $site): ?>
            <?php if (empty($site['records'])): ?>
                <?php continue; ?>
            <?php endif; ?>
            
            <div class="site-section">
                <div class="site-header">
                    <i class="fas fa-building"></i> SITE: <?= htmlspecialchars($site['site_name']) ?>
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
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($site['records'] as $row): ?>
                            <?php
                            // Format status
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
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($row['employee_name']) ?><br>
                                    <small style="color: #666;">ID: <?= $row['employee_id'] ?></small>
                                </div>
                                <td><?= date('M d, Y', strtotime($row['date'])) ?> </div>
                                <td style="text-align: center;">
                                    <span class="status-badge <?= $am_class ?>"><?= $am_status ?></span>
                                </div>
                                <td style="text-align: center; font-family: monospace;"><?= $row['time_in_am_display'] ?> </div>
                                <td style="text-align: center; font-family: monospace;"><?= $row['time_out_am_display'] ?> </div>
                                <td style="text-align: center;">
                                    <span class="status-badge <?= $pm_class ?>"><?= $pm_status ?></span>
                                </div>
                                <td>
                                    <?php if ($row['time_in_pm_display'] != '-'): ?>
                                        <div><span class="pm-label">PM:</span> <?= $row['time_in_pm_display'] ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['time_in_night_display'] != '-'): ?>
                                        <div><span class="night-label">Night:</span> <?= $row['time_in_night_display'] ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['time_in_pm_display'] == '-' && $row['time_in_night_display'] == '-'): ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <td>
                                    <?php if ($row['time_out_pm_display'] != '-'): ?>
                                        <div><span class="pm-label">PM:</span> <?= $row['time_out_pm_display'] ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['time_out_night_display'] != '-'): ?>
                                        <div><span class="night-label">Night:</span> <?= $row['time_out_night_display'] ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['time_out_pm_display'] == '-' && $row['time_out_night_display'] == '-'): ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <td style="text-align: center;">
                                    <span class="status-badge <?= $night_class ?>"><?= $night_status ?></span>
                                </div>
                                <td style="text-align: center;">
                                    <?php if (!empty($row['leave_type']) && $row['leave_type'] != 'None'): ?>
                                        <span class="leave-badge <?= $leave_class ?>"><?= htmlspecialchars($row['leave_type']) ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <td style="text-align: center;">
                                    <?php if (!empty($row['workday_type'])): ?>
                                        <span class="workday-badge"><?= htmlspecialchars($row['workday_type']) ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <td style="text-align: center;">
                                    <span class="total-hours"><?= $row['total_hours'] ?></span>
                                </div>
                                <td style="font-size: 0.75rem;"><?= htmlspecialchars($row['remarks'] ?? '-') ?></div>
                             </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Site Summary -->
                <div class="site-summary">
                    <span><i class="fas fa-users"></i> Employees: <?= $site['summary']['record_count'] ?></span>
                    <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Present: <?= $site['summary']['present_count'] ?></span>
                    <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Absent: <?= $site['summary']['absent_count'] ?></span>
                    <span style="color: #ffc107;"><i class="fas fa-umbrella-beach"></i> On Leave: <?= $site['summary']['leave_count'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- GRAND TOTAL SUMMARY -->
        <div class="grand-total-section">
            <div class="grand-total-title">
                <i class="fas fa-chart-line"></i> GRAND TOTAL SUMMARY (All Sites Combined)
            </div>
            <div class="grand-total-grid">
                <div class="grand-total-box">
                    <h4>ATTENDANCE SUMMARY</h4>
                    <table class="grand-total-table">
                        <tr><td><strong>Total Records:</strong></td><td><?= $grand_total_records ?></td></tr>
                        <tr><td><strong>Present Days:</strong></td><td style="color: #28a745;"><?= $grand_present_count ?></td></tr>
                        <tr><td><strong>Absent Days:</strong></td><td style="color: #dc3545;"><?= $grand_absent_count ?></td></tr>
                        <tr><td><strong>Leave Days:</strong></td><td style="color: #ffc107;"><?= $grand_leave_count ?></td></tr>
                    </table>
                </div>
                <div class="grand-total-box">
                    <h4>ATTENDANCE RATE</h4>
                    <table class="grand-total-table">
                        <tr><td><strong>Present Rate:</strong></td><td style="color: #28a745;"><?= $grand_present_rate ?>%</td></tr>
                        <tr><td><strong>Absent Rate:</strong></td><td style="color: #dc3545;"><?= $grand_absent_rate ?>%</td></tr>
                        <tr><td><strong>Leave Rate:</strong></td><td style="color: #ffc107;"><?= $grand_leave_rate ?>%</td></tr>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
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
// HELPER FUNCTIONS
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
    
    // Calculate AM hours
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHoursForPrint($time_in_am, $time_out_am);
        $total += floatval($am_hours);
    }
    
    // Calculate PM hours
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHoursForPrint($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
    }
    
    // Calculate Night hours
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