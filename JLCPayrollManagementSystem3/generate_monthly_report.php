<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// Include access control
include_once("check_access.php");

// Check if user has access to this page
$current_page = basename($_SERVER['PHP_SELF']);
if (!checkPageAccess($current_page)) {
    header("Location: home.php?error=access_denied");
    exit;
}

include_once("connection.php");

// Get parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'excel';
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$employee_filter_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// Get month name
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));

// Check if position column exists in employees table
$check_position = $conn->query("SHOW COLUMNS FROM employees LIKE 'position'");
$position_exists = $check_position && $check_position->num_rows > 0;

// Base query for monthly attendance - added position field
if ($position_exists) {
    $sql = "SELECT 
                e.id AS employee_id,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.position,
                a.id as attendance_id,
                a.date,
                a.status,
                a.time_in_am,
                a.time_out_am,
                a.time_in_pm,
                a.time_out_pm,
                a.remarks,
                a.leave_type
            FROM employees e
            LEFT JOIN attendance a 
                ON a.employee_id = e.id 
                AND MONTH(a.date) = ? 
                AND YEAR(a.date) = ?";
} else {
    // If position column doesn't exist, use empty string as default
    $sql = "SELECT 
                e.id AS employee_id,
                e.first_name,
                e.middle_name,
                e.last_name,
                '' as position,
                a.id as attendance_id,
                a.date,
                a.status,
                a.time_in_am,
                a.time_out_am,
                a.time_in_pm,
                a.time_out_pm,
                a.remarks,
                a.leave_type
            FROM employees e
            LEFT JOIN attendance a 
                ON a.employee_id = e.id 
                AND MONTH(a.date) = ? 
                AND YEAR(a.date) = ?";
}

$params = [$selected_month, $selected_year];
$param_types = "ii";

// Apply employee filter if specified
if ($employee_filter_id > 0) {
    $sql .= " AND e.id = ?";
    $params[] = $employee_filter_id;
    $param_types .= "i";
} 
elseif (!empty($search)) {
    $sql .= " AND (e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ? OR e.id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

$sql .= " ORDER BY e.last_name ASC, a.date ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all employees for summary
$employee_sql = "SELECT id, first_name, middle_name, last_name, position FROM employees ORDER BY last_name, first_name";
$employee_result = $conn->query($employee_sql);

// Function to calculate hours between two times
function calculateHoursMonthly($time_in, $time_out) {
    if (empty($time_in) || empty($time_out) || $time_in == '00:00:00' || $time_out == '00:00:00') {
        return 0;
    }
    
    $time_in_ts = strtotime($time_in);
    $time_out_ts = strtotime($time_out);
    
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400; // Add 24 hours if time_out is next day
    }
    
    return round(($time_out_ts - $time_in_ts) / 3600, 2);
}

// Function to calculate total work hours for a day
function calculateDailyHours($time_in_am, $time_out_am, $time_in_pm, $time_out_pm) {
    $total = 0;
    $total += calculateHoursMonthly($time_in_am, $time_out_am);
    $total += calculateHoursMonthly($time_in_pm, $time_out_pm);
    return $total;
}

// Calculate monthly summary
$employee_totals = [];
$total_present_days = 0;
$total_absent_days = 0;
$total_leave_days = 0;
$total_hours_sum = 0;

// Organize data by employee
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $emp_id = $row['employee_id'];
        
        // Initialize employee data if not exists
        if (!isset($employee_totals[$emp_id])) {
            $full_name = $row['first_name'] . ' ' . 
                        (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') . 
                        $row['last_name'];
            
            $employee_totals[$emp_id] = [
                'name' => $full_name,
                'position' => $row['position'] ?? 'N/A',
                'present_days' => 0,
                'absent_days' => 0,
                'leave_days' => 0,
                'total_hours' => 0
            ];
        }
        
        // Calculate daily hours
        $daily_hours = calculateDailyHours(
            $row['time_in_am'], $row['time_out_am'],
            $row['time_in_pm'], $row['time_out_pm']
        );
        
        // Determine attendance status
        $status = $row['status'] ?? 'No Record';
        $has_time = (!empty($row['time_in_am']) && $row['time_in_am'] != '00:00:00') ||
                   (!empty($row['time_out_am']) && $row['time_out_am'] != '00:00:00') ||
                   (!empty($row['time_in_pm']) && $row['time_in_pm'] != '00:00:00') ||
                   (!empty($row['time_out_pm']) && $row['time_out_pm'] != '00:00:00');
        
        if ($has_time) {
            $employee_totals[$emp_id]['present_days']++;
            $total_present_days++;
            $employee_totals[$emp_id]['total_hours'] += $daily_hours;
            $total_hours_sum += $daily_hours;
        } elseif ($status == 'On Leave') {
            $employee_totals[$emp_id]['leave_days']++;
            $total_leave_days++;
        } elseif ($status == 'Absent') {
            $employee_totals[$emp_id]['absent_days']++;
            $total_absent_days++;
        }
    }
}

// Set headers for Excel download
if ($report_type == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="monthly_attendance_summary_' . $month_name . '_' . $selected_year . '.xls"');
    header('Cache-Control: max-age=0');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Monthly Attendance Summary - <?= $month_name ?> <?= $selected_year ?></title>
    <style>
        /* Global Styles */
        body {
            font-family: 'Arial', sans-serif;
            margin: 20px;
            background: #fff;
            color: #333;
        }
        
        /* Header Section */
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #2E7D32;
        }
        
        .report-header h1 {
            color: #2E7D32;
            font-size: 28px;
            margin: 0 0 10px 0;
        }
        
        .report-header h2 {
            color: #1B5E20;
            font-size: 20px;
            margin: 5px 0;
        }
        
        .report-header p {
            color: #666;
            font-size: 14px;
            margin: 5px 0;
        }
        
        /* Filter Info */
        .filter-info {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 25px;
            border-left: 4px solid #2E7D32;
            font-size: 14px;
        }
        
        /* Summary Cards */
        .summary-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            flex: 1;
            min-width: 180px;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #2E7D32;
        }
        
        .summary-card.total {
            border-left-color: #2E7D32;
        }
        
        .summary-card.present {
            border-left-color: #28a745;
        }
        
        .summary-card.absent {
            border-left-color: #dc3545;
        }
        
        .summary-card.leave {
            border-left-color: #ffc107;
        }
        
        .summary-card.hours {
            border-left-color: #17a2b8;
        }
        
        .summary-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .summary-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }
        
        .summary-value small {
            font-size: 14px;
            color: #999;
            font-weight: normal;
        }
        
        /* Employee Summary Table */
        .employee-summary {
            margin-top: 30px;
        }
        
        .employee-summary h3 {
            color: #2E7D32;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #2E7D32;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        th {
            background: #2E7D32;
            color: white;
            font-weight: 600;
            padding: 12px 8px;
            text-align: center;
            border: 1px solid #1B5E20;
            white-space: nowrap;
        }
        
        td {
            padding: 10px 8px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        /* Status Badges */
        .status-present {
            color: #28a745;
            font-weight: 600;
        }
        
        .status-absent {
            color: #dc3545;
            font-weight: 600;
        }
        
        .status-leave {
            color: #ffc107;
            font-weight: 600;
        }
        
        /* Position column */
        .position-column {
            font-style: italic;
            color: #555;
        }
        
        /* Hours column */
        .hours-column {
            font-weight: 600;
            color: #17a2b8;
        }
        
        /* Totals */
        .total-row {
            background: #e8f5e9 !important;
            font-weight: 700;
        }
        
        .total-row td {
            border-top: 2px solid #2E7D32;
        }
        
        /* Footer */
        .report-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        
        /* Text alignment */
        .text-center {
            text-align: center;
        }
        
        .text-left {
            text-align: left;
        }
        
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <!-- Report Header -->
    <div class="report-header">
        <h1>MONTHLY ATTENDANCE SUMMARY</h1>
        <h2><?= $month_name ?> <?= $selected_year ?></h2>
        <p>Generated on: <?= date('F j, Y \a\t h:i A') ?></p>
    </div>
    
    <!-- Filter Information -->
    <div class="filter-info">
        <strong>FILTERS APPLIED:</strong><br>
        <?php if ($employee_filter_id > 0): ?>
            <?php
            $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?";
            $name_stmt = $conn->prepare($name_query);
            $name_stmt->bind_param("i", $employee_filter_id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            $employee_name = '';
            if ($name_row = $name_result->fetch_assoc()) {
                $employee_name = $name_row['full_name'];
            }
            $name_stmt->close();
            ?>
            <span>Employee: <strong><?= htmlspecialchars($employee_name) ?> (ID: <?= $employee_filter_id ?>)</strong></span><br>
        <?php endif; ?>
        
        <?php if (!empty($search)): ?>
            <span>Search: <strong><?= htmlspecialchars($search) ?></strong></span><br>
        <?php endif; ?>
        
        <span>Month: <strong><?= $month_name ?> <?= $selected_year ?></strong></span>
    </div>
    
    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card total">
            <div class="summary-label">Total Employees</div>
            <div class="summary-value"><?= count($employee_totals) ?></div>
        </div>
        <div class="summary-card present">
            <div class="summary-label">Present Days</div>
            <div class="summary-value"><?= $total_present_days ?></div>
        </div>
        <div class="summary-card absent">
            <div class="summary-label">Absent Days</div>
            <div class="summary-value"><?= $total_absent_days ?></div>
        </div>
        <div class="summary-card leave">
            <div class="summary-label">Leave Days</div>
            <div class="summary-value"><?= $total_leave_days ?></div>
        </div>
        <div class="summary-card hours">
            <div class="summary-label">Total Hours</div>
            <div class="summary-value"><?= number_format($total_hours_sum, 2) ?> <small>hrs</small></div>
        </div>
    </div>
    
    <!-- Employee Summary Table -->
    <div class="employee-summary">
        <h3>Employee Attendance Summary</h3>
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Employee Name</th>
                    <th>Position</th>
                    <th>Present Days</th>
                    <th>Absent Days</th>
                    <th>Leave Days</th>
                    <th>Total Hours</th>
                    <th>Total Days</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grand_total_present = 0;
                $grand_total_absent = 0;
                $grand_total_leave = 0;
                $grand_total_hours = 0;
                
                foreach ($employee_totals as $emp_id => $emp_data): 
                    $grand_total_present += $emp_data['present_days'];
                    $grand_total_absent += $emp_data['absent_days'];
                    $grand_total_leave += $emp_data['leave_days'];
                    $grand_total_hours += $emp_data['total_hours'];
                    $total_days = $emp_data['present_days'] + $emp_data['absent_days'] + $emp_data['leave_days'];
                ?>
                <tr>
                    <td class="text-center"><?= $emp_id ?></td>
                    <td><?= htmlspecialchars($emp_data['name']) ?></td>
                    <td class="position-column"><?= htmlspecialchars($emp_data['position']) ?></td>
                    <td class="text-center status-present"><?= $emp_data['present_days'] ?></td>
                    <td class="text-center status-absent"><?= $emp_data['absent_days'] ?></td>
                    <td class="text-center status-leave"><?= $emp_data['leave_days'] ?></td>
                    <td class="text-center hours-column"><?= number_format($emp_data['total_hours'], 2) ?></td>
                    <td class="text-center"><strong><?= $total_days ?></strong></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (count($employee_totals) > 0): ?>
                <tr class="total-row">
                    <td colspan="3" class="text-right"><strong>GRAND TOTALS:</strong></td>
                    <td class="text-center"><strong><?= $grand_total_present ?></strong></td>
                    <td class="text-center"><strong><?= $grand_total_absent ?></strong></td>
                    <td class="text-center"><strong><?= $grand_total_leave ?></strong></td>
                    <td class="text-center"><strong><?= number_format($grand_total_hours, 2) ?></strong></td>
                    <td class="text-center"><strong><?= $grand_total_present + $grand_total_absent + $grand_total_leave ?></strong></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Report Footer -->
    <div class="report-footer">
        <div>This report was generated on <?= date('F j, Y \a\t h:i A') ?></div>
        <div>Attendance Management System - Monthly Summary Report</div>
    </div>
</body>
</html>
<?php
// Close statements
if (isset($stmt)) {
    $stmt->close();
}
if (isset($employee_result)) {
    $employee_result->close();
}
$conn->close();
?>