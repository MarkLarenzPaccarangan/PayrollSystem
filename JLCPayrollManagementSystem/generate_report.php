<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// Get parameters from URL - these come from the calendar in attendance.php
$type = isset($_GET['type']) ? $_GET['type'] : 'excel'; // excel or word
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); // THIS COMES FROM THE CALENDAR
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Validate date (should be in YYYY-MM-DD format from the calendar)
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    $date = date('Y-m-d');
}

// Get month and year from the selected date for monthly context
$selected_month = date('m', strtotime($date));
$selected_year = date('Y', strtotime($date));
$selected_day = date('d', strtotime($date));

// ✅ FIXED: Added leave_type to SELECT query
$sql = "SELECT 
            e.id AS employee_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.position,
            a.date,
            a.status,
            a.time_in_am,
            a.time_out_am,
            a.time_in_pm,
            a.time_out_pm,
            a.remarks,
            a.total_hours,
            a.leave_type  -- ✅ ADDED: Para ma-display ang leave type
        FROM employees e
        LEFT JOIN attendance a 
            ON a.employee_id = e.id 
            AND DATE(a.date) = ?";

$params = [$date];
$param_types = "s";

// Apply filters
$where_clauses = [];

if ($employee_id > 0) {
    $where_clauses[] = "e.id = ?";
    $params[] = $employee_id;
    $param_types .= "i";
} 
elseif (!empty($search)) {
    $where_clauses[] = "(e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ? OR e.id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY e.last_name ASC, a.date DESC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// ✅ FIXED: Store rows in array para hindi magkaproblema sa pointer
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
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

// ✅ FIXED: Get total statistics properly
$total_hours = 0;
$present_count = 0;
$absent_count = 0;
$leave_count = 0;
$total_employees = count($rows);

foreach ($rows as $row) {
    if ($row['status'] == 'Present') {
        $present_count++;
    } elseif ($row['status'] == 'On Leave') {
        $leave_count++;
    } else {
        $absent_count++;
    }
    $total_hours += floatval($row['total_hours'] ?? 0);
}

// Format date for display - based on the selected date from calendar
$formatted_date = date('F d, Y', strtotime($date));
$formatted_date_ymd = date('Y-m-d', strtotime($date));
$short_date = date('d-M-y', strtotime($date));

// ✅ ADDED: Get day of week from selected date
$day_of_week = date('l', strtotime($date));

// ✅ ADDED: Helper function to format time display
function formatTimeForReport($time) {
    if (empty($time) || $time == '00:00:00') {
        return '—';
    }
    return date('h:i A', strtotime($time));
}

// ✅ ADDED: Helper function to get status display with leave type
function getStatusDisplay($row) {
    if ($row['status'] == 'On Leave') {
        if (!empty($row['leave_type'])) {
            return 'On Leave (' . $row['leave_type'] . ')';
        }
        return 'On Leave';
    }
    return $row['status'] ?: 'Absent';
}

// ============================================
// EXPORT TO EXCEL - BASED ON CALENDAR DATE
// ============================================
if ($type == 'excel') {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Attendance_Report_' . $formatted_date_ymd . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Simple HTML table - gaya ng nasa image, pero landscape orientation
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Attendance Report - ' . $formatted_date . '</title>';
    
    // EXCEL LANDSCAPE ORIENTATION
    echo '<!-- EXCEL LANDSCAPE ORIENTATION - BASED ON CALENDAR DATE: ' . $formatted_date . ' -->';
    echo '<style>
        @page { 
            size: landscape;
            margin: 0.5in;
        }
        @media print {
            @page { 
                size: landscape;
            }
        }
        table { 
            border-collapse: collapse; 
            width: 100%;
            font-family: Arial, sans-serif;
            font-size: 10px;
        }
        td, th {
            padding: 3px 4px;
            border: 1px solid #000000;
            white-space: nowrap;
        }
        th {
            background-color: #2E7D32;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        .present { color: #2E7D32; font-weight: bold; }
        .absent { color: #dc2626; font-weight: bold; }
        .leave { color: #856404; font-weight: bold; }
        .total-row {
            font-weight: bold;
            background-color: #f0f7f0;
        }
        .header-text {
            font-weight: bold;
            font-size: 16px;
            text-align: center;
            background-color: #2E7D32;
            color: white;
        }
        .subheader-text {
            text-align: center;
            font-size: 11px;
            background-color: #f5f5f5;
        }
        .info-row {
            background-color: #f5f5f5;
            font-size: 9px;
        }
        .footer-text {
            font-size: 9px;
            color: #666;
            text-align: right;
        }
        .calendar-info {
            background-color: #e8f5e9;
            font-size: 9px;
            font-weight: bold;
            color: #2E7D32;
        }
    </style>';
    echo '</head>';
    echo '<body>';
    
    // SIMPLE TABLE FORMAT - gaya ng nasa image
    echo '<table>';
    
    // Header - CENTERED with date from calendar
    echo '<tr>';
    echo '<td colspan="12" class="header-text">ATTENDANCE REPORT - ' . strtoupper($formatted_date) . '</td>';
    echo '</tr>';
    
    // Subtitle - CENTERED
    echo '<tr>';
    echo '<td colspan="12" class="subheader-text">Payroll Management System • Official Document</td>';
    echo '</tr>';
    
    // Calendar info row - shows the selected date from calendar
    echo '<tr>';
    echo '<td colspan="12" class="calendar-info">';
    echo '📅 SELECTED DATE: ' . $formatted_date . ' (' . $day_of_week . ') | ';
    echo 'Month: ' . date('F Y', strtotime($date)) . ' | ';
    echo 'Day: ' . $selected_day . ' of ' . date('F', strtotime($date));
    echo '</td>';
    echo '</tr>';
    
    // Empty row
    echo '<tr><td colspan="12">&nbsp;</td></tr>';
    
    // Info row - isang row lang lahat
    echo '<tr class="info-row">';
    echo '<td colspan="12">';
    echo 'Report Date: ' . $short_date . ' | ';
    echo 'Calendar Date: ' . $formatted_date . ' | ';
    echo 'Employee: ' . htmlspecialchars($employee_name) . ' | ';
    echo 'Generated: ' . date('m/d/Y h:i A') . ' | ';
    echo 'By: ' . htmlspecialchars($_SESSION['Admin_User']) . ' | ';
    echo 'Report ID: RPT-' . date('Ymd') . '-' . strtoupper(substr(md5($date . $employee_id), 0, 6)) . ' | ';
    echo 'Status: Official';
    if (!empty($search)) {
        echo ' | Search: "' . htmlspecialchars($search) . '"';
    }
    echo '</td>';
    echo '</tr>';
    
    // Empty row
    echo '<tr><td colspan="12">&nbsp;</td></tr>';
    
    // Summary table headers
    echo '<tr>';
    echo '<th>Total Emp</th>';
    echo '<th>Present</th>';
    echo '<th>Absent</th>';
    echo '<th>On Leave</th>';
    echo '<th>Att. Rate</th>';
    echo '<th>Total Hrs</th>';
    echo '<th>Avg Hrs</th>';
    echo '<th>Present%</th>';
    echo '<th>Absent%</th>';
    echo '<th>Leave%</th>';
    echo '<th>Day</th>';
    echo '<th>Date</th>';
    echo '</tr>';
    
    // Summary data - based on calendar date
    echo '<tr>';
    echo '<td style="text-align: center;">' . $total_employees . '</td>';
    echo '<td style="text-align: center;" class="present">' . $present_count . '</td>';
    echo '<td style="text-align: center;" class="absent">' . $absent_count . '</td>';
    echo '<td style="text-align: center;" class="leave">' . $leave_count . '</td>';
    echo '<td style="text-align: center;">' . ($total_employees > 0 ? round((($present_count) / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td style="text-align: center;">' . number_format($total_hours, 2) . ' hrs</td>';
    echo '<td style="text-align: center;">' . ($total_employees > 0 ? round($total_hours / $total_employees, 2) : 0) . ' hrs</td>';
    echo '<td style="text-align: center;" class="present">' . ($total_employees > 0 ? round(($present_count / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td style="text-align: center;" class="absent">' . ($total_employees > 0 ? round(($absent_count / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td style="text-align: center;" class="leave">' . ($total_employees > 0 ? round(($leave_count / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td style="text-align: center;">' . $day_of_week . '</td>';
    echo '<td style="text-align: center;">' . $short_date . '</td>';
    echo '</tr>';
    
    // Empty row
    echo '<tr><td colspan="12">&nbsp;</td></tr>';
    
    // Main table headers
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Employee Name</th>';
    echo '<th>Position</th>';
    echo '<th>Status</th>';
    echo '<th>AM In</th>';
    echo '<th>AM Out</th>';
    echo '<th>PM In</th>';
    echo '<th>PM Out</th>';
    echo '<th>Total Hrs</th>';
    echo '<th>Remarks</th>';
    echo '<th>Leave Type</th>';
    echo '<th>Date</th>';
    echo '</tr>';
    
    // Data rows - gamit ang $rows array
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
            
            // Format times - keep as is para gaya ng nasa image
            $time_in_am = (!empty($row['time_in_am']) && $row['time_in_am'] != '00:00:00') ? $row['time_in_am'] : '—';
            $time_out_am = (!empty($row['time_out_am']) && $row['time_out_am'] != '00:00:00') ? $row['time_out_am'] : '—';
            $time_in_pm = (!empty($row['time_in_pm']) && $row['time_in_pm'] != '00:00:00') ? $row['time_in_pm'] : '—';
            $time_out_pm = (!empty($row['time_out_pm']) && $row['time_out_pm'] != '00:00:00') ? $row['time_out_pm'] : '—';
            
            $status_class = '';
            if ($row['status'] == 'Present') {
                $status_class = 'present';
            } elseif ($row['status'] == 'On Leave') {
                $status_class = 'leave';
            } else {
                $status_class = 'absent';
            }
            
            $status_text = getStatusDisplay($row);
            
            echo '<tr>';
            echo '<td>' . $row['employee_id'] . '</td>';
            echo '<td>' . htmlspecialchars($full_name) . '</td>';
            echo '<td>' . htmlspecialchars($row['position'] ?? '—') . '</td>';
            echo '<td class="' . $status_class . '">' . htmlspecialchars($status_text) . '</td>';
            echo '<td>' . $time_in_am . '</td>';
            echo '<td>' . $time_out_am . '</td>';
            echo '<td>' . $time_in_pm . '</td>';
            echo '<td>' . $time_out_pm . '</td>';
            echo '<td>' . number_format($row['total_hours'] ?? 0, 2) . '</td>';
            echo '<td>' . htmlspecialchars($row['remarks'] ?? '—') . '</td>';
            echo '<td>' . htmlspecialchars($row['leave_type'] ?? '—') . '</td>';
            echo '<td>' . date('m/d/Y', strtotime($row['date'] ?? $date)) . '</td>';
            echo '</tr>';
        }
        
        // Total row - gaya ng nasa image
        echo '<tr class="total-row">';
        echo '<td colspan="8" style="text-align: right;"><strong>TOTAL HOURS:</strong></td>';
        echo '<td><strong>' . number_format($total_hours, 2) . '</strong></td>';
        echo '<td colspan="3"></td>';
        echo '</tr>';
        
    } else {
        echo '<tr><td colspan="12" style="text-align: center; padding: 20px;">No attendance records found for ' . $formatted_date . '</td></tr>';
    }
    
    // Empty row
    echo '<tr><td colspan="12">&nbsp;</td></tr>';
    
    // Calendar information row
    echo '<tr class="calendar-info">';
    echo '<td colspan="12">';
    echo '📅 This report is based on calendar date: ' . $formatted_date . ' (' . $day_of_week . ') | ';
    echo 'Selected from calendar on: ' . date('F d, Y', strtotime($date));
    echo '</td>';
    echo '</tr>';
    
    // Empty row
    echo '<tr><td colspan="12">&nbsp;</td></tr>';
    
    // Footer - gaya ng nasa image
    echo '<tr>';
    echo '<td colspan="12" class="footer-text">';
    echo 'Generated on: ' . date('F d, Y h:i:s A') . ' | ';
    echo 'Report ID: RPT-' . date('Ymd') . '-' . strtoupper(substr(md5($date . $employee_id), 0, 6)) . ' | ';
    echo 'Calendar Date: ' . $formatted_date;
    echo '</td>';
    echo '</tr>';
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    
    exit;
}

// ============================================
// EXPORT TO WORD - (HINDI BINAGO ANG FORMAT, IN-ADD LANG ANG LEAVE)
// ============================================
elseif ($type == 'word') {
    // Set headers for Word download
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Attendance_Report_' . $formatted_date_ymd . '.doc"');
    header('Cache-Control: max-age=0');
    
    // Word version - HINDI BINAGO ANG FORMAT, ADDED LANG NG LEAVE
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Attendance Report - ' . $formatted_date . '</title>';
    echo '<style>
        body { 
            font-family: "Times New Roman", Times, serif; 
            margin: 0.5in;
        }
        
        /* CENTERED HEADER */
        h1 { 
            color: #2E7D32; 
            font-size: 28pt;
            text-align: center;
            margin: 0 0 5px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .subtitle {
            text-align: center;
            font-size: 14pt;
            margin-bottom: 25px;
            border-bottom: 2px solid #2E7D32;
            padding-bottom: 10px;
        }
        .subtitle strong {
            color: #2E7D32;
        }
        .calendar-note {
            text-align: center;
            font-size: 12pt;
            color: #2E7D32;
            margin-bottom: 15px;
            font-style: italic;
        }
        
        /* INFO TABLE */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            border: 2px solid #2E7D32;
        }
        .info-table td {
            border: 1px solid #2E7D32;
            padding: 8px 10px;
        }
        .info-label {
            font-weight: bold;
            background: #f0f7f0;
            width: 120px;
        }
        
        /* STATS TABLE */
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            border: 2px solid #2E7D32;
        }
        .stats-table td {
            border: 1px solid #2E7D32;
            padding: 8px;
            text-align: center;
        }
        .stats-header {
            background: #2E7D32;
            color: white;
            font-weight: bold;
            text-align: center;
            font-size: 14pt;
            padding: 10px;
        }
        .stats-label {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        /* MAIN TABLE */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #2E7D32;
        }
        .main-table th {
            background: #2E7D32;
            color: white;
            padding: 10px;
            border: 1px solid #1B5E20;
            text-align: left;
        }
        .main-table td {
            border: 1px solid #2E7D32;
            padding: 8px;
        }
        .present { color: #2E7D32; font-weight: bold; }
        .absent { color: #c62828; font-weight: bold; }
        .leave { color: #856404; font-weight: bold; }
        .total-row { background: #e8f5e9; font-weight: bold; }
        .footer { 
            margin-top: 25px; 
            text-align: right; 
            font-size: 10pt;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>';
    echo '</head>';
    echo '<body>';
    
    // CENTERED HEADER with date
    echo '<h1>ATTENDANCE REPORT - ' . strtoupper($formatted_date) . '</h1>';
    echo '<div class="subtitle">Payroll Management System • <strong>Official Document</strong></div>';
    echo '<div class="calendar-note">📅 Selected from calendar: ' . $day_of_week . ', ' . $formatted_date . '</div>';
    
    // INFO TABLE
    echo '<table class="info-table">';
    echo '<tr>';
    echo '<td class="info-label">Calendar Date:</td>';
    echo '<td>' . $formatted_date . ' (' . $day_of_week . ')</td>';
    echo '<td class="info-label">Employee:</td>';
    echo '<td>' . htmlspecialchars($employee_name) . '</td>';
    echo '<td class="info-label">Generated:</td>';
    echo '<td>' . date('m/d/Y h:i A') . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="info-label">Generated By:</td>';
    echo '<td>' . htmlspecialchars($_SESSION['Admin_User']) . '</td>';
    echo '<td class="info-label">Report ID:</td>';
    echo '<td>RPT-' . date('Ymd') . '-' . strtoupper(substr(md5($date . $employee_id), 0, 6)) . '</td>';
    echo '<td class="info-label">Status:</td>';
    echo '<td>Official Document</td>';
    echo '</tr>';
    if (!empty($search)) {
        echo '<tr>';
        echo '<td class="info-label">Search Filter:</td>';
        echo '<td colspan="5">"' . htmlspecialchars($search) . '"</td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // STATS TABLE - ADDED Leave column
    echo '<table class="stats-table">';
    echo '<tr><td colspan="11" class="stats-header">ATTENDANCE SUMMARY FOR ' . strtoupper($formatted_date) . '</td></tr>';
    echo '<tr>';
    echo '<td class="stats-label">Total</td>';
    echo '<td class="stats-label">Present</td>';
    echo '<td class="stats-label">Absent</td>';
    echo '<td class="stats-label">Leave</td>';
    echo '<td class="stats-label">Rate</td>';
    echo '<td class="stats-label">Hours</td>';
    echo '<td class="stats-label">Average</td>';
    echo '<td class="stats-label">Present%</td>';
    echo '<td class="stats-label">Absent%</td>';
    echo '<td class="stats-label">Day</td>';
    echo '<td class="stats-label">Date</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . $total_employees . '</td>';
    echo '<td class="present">' . $present_count . '</td>';
    echo '<td class="absent">' . $absent_count . '</td>';
    echo '<td class="leave">' . $leave_count . '</td>';
    echo '<td>' . ($total_employees > 0 ? round((($present_count) / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td>' . number_format($total_hours, 2) . ' hrs</td>';
    echo '<td>' . ($total_employees > 0 ? round($total_hours / $total_employees, 2) : 0) . ' hrs</td>';
    echo '<td class="present">' . ($total_employees > 0 ? round(($present_count / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td class="absent">' . ($total_employees > 0 ? round(($absent_count / $total_employees) * 100, 2) : 0) . '%</td>';
    echo '<td>' . $day_of_week . '</td>';
    echo '<td>' . $short_date . '</td>';
    echo '</tr>';
    echo '</table>';
    
    // MAIN TABLE - gamit ang $rows array
    echo '<table class="main-table">';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Employee Name</th>';
    echo '<th>Position</th>';
    echo '<th>Status</th>';
    echo '<th>AM In</th>';
    echo '<th>AM Out</th>';
    echo '<th>PM In</th>';
    echo '<th>PM Out</th>';
    echo '<th>Total Hrs</th>';
    echo '<th>Remarks</th>';
    echo '<th>Leave Type</th>';
    echo '</tr>';
    
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
            
            // Format times with proper formatting for Word
            $time_in_am = formatTimeForReport($row['time_in_am']);
            $time_out_am = formatTimeForReport($row['time_out_am']);
            $time_in_pm = formatTimeForReport($row['time_in_pm']);
            $time_out_pm = formatTimeForReport($row['time_out_pm']);
            
            $status_class = '';
            if ($row['status'] == 'Present') {
                $status_class = 'present';
            } elseif ($row['status'] == 'On Leave') {
                $status_class = 'leave';
            } else {
                $status_class = 'absent';
            }
            
            $status_text = getStatusDisplay($row);
            
            echo '<tr>';
            echo '<td>' . $row['employee_id'] . '</td>';
            echo '<td>' . htmlspecialchars($full_name) . '</td>';
            echo '<td>' . htmlspecialchars($row['position'] ?? '—') . '</td>';
            echo '<td class="' . $status_class . '">' . htmlspecialchars($status_text) . '</td>';
            echo '<td>' . $time_in_am . '</td>';
            echo '<td>' . $time_out_am . '</td>';
            echo '<td>' . $time_in_pm . '</td>';
            echo '<td>' . $time_out_pm . '</td>';
            echo '<td>' . number_format($row['total_hours'] ?? 0, 2) . '</td>';
            echo '<td>' . htmlspecialchars($row['remarks'] ?? '—') . '</td>';
            echo '<td>' . htmlspecialchars($row['leave_type'] ?? '—') . '</td>';
            echo '</tr>';
        }
        
        // Total row
        echo '<tr class="total-row">';
        echo '<td colspan="8" style="text-align: right;"><strong>TOTAL HOURS:</strong></td>';
        echo '<td><strong>' . number_format($total_hours, 2) . '</strong></td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        
    } else {
        echo '<tr><td colspan="11" style="text-align: center;">No records found for ' . $formatted_date . '</td></tr>';
    }
    
    echo '</table>';
    
    echo '<div class="footer">';
    echo 'Generated on: ' . date('F d, Y h:i:s A') . ' | ';
    echo 'Report ID: RPT-' . date('Ymd') . '-' . strtoupper(substr(md5($date . $employee_id), 0, 6)) . ' | ';
    echo 'Calendar Date: ' . $formatted_date;
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    
    exit;
}

else {
    header("Location: attendance.php?error=invalid_report_type");
    exit;
}

$conn->close();
?>