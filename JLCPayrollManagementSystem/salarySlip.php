
<?php
session_start();

// Redirect if the admin is not logged in
if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// TURN ON ERROR DISPLAY FOR DEBUGGING - REMOVE AFTER FIXING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log all errors to a file
ini_set('log_errors', 1);
ini_set('error_log', './salaryslip_errors.log');

// Include database connection
$servername = "localhost";
$username = "root";
$password = "";
$database = "payrollsystem";

$conn = new mysqli($servername, $username, $password, $database);

// Check database connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Define working days per month for calculation (fallback if no payroll record exists)
define('WORKING_DAYS_PER_MONTH', 22);
define('HOURS_PER_DAY', 8);

// Function to calculate hours between two times (from payrollList.php)
function calculateHours($time_in, $time_out) {
    if (empty($time_in) || empty($time_out) || $time_in == '00:00:00' || $time_out == '00:00:00') {
        return 0;
    }
    
    $time_in_ts = strtotime($time_in);
    $time_out_ts = strtotime($time_out);
    
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400; // Add 24 hours if time_out is next day
    }
    
    $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
    return $hours;
}

// Updated holiday pay calculation function (from payrollList.php)
function calculateHolidayPay($daily_salary, $holiday_type, $has_attendance) {
    $holiday_bonus = 0;
    
    if ($holiday_type == 'Regular Holiday') {
        // Regular Holiday: No additional bonus - base salary is added separately in regular_pay
        $holiday_bonus = 0;
    } 
    else if ($holiday_type == 'Special Working Holiday') {
        if ($has_attendance) {
            // Special Working Holiday: Add 30% bonus on daily salary only
            $holiday_bonus = $daily_salary * 0.30;
        }
        // If absent, bonus stays 0
    }
    else if ($holiday_type == 'Special Non-Working Holiday') {
        if ($has_attendance) {
            // Special Non-Working Holiday: Add 30% bonus on daily salary only
            $holiday_bonus = $daily_salary * 0.30;
        }
        // If absent, bonus stays 0
    }
    
    return $holiday_bonus;
}

// Handle AJAX request for salary report - UPDATED for date range and site filter
if (isset($_POST['ajax']) && $_POST['ajax'] == 'generate_report') {
    header('Content-Type: application/json');
    
    $date_from = $_POST['date_from'];
    $date_to = $_POST['date_to'];
    $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : 'all';
    $site_filter = isset($_POST['site_id']) ? $_POST['site_id'] : 'all';
    
    // Get attendance records with site assignments for filtering
    $attendance_query = "
        SELECT 
            a.employee_id,
            a.date,
            a.time_in_am, a.time_out_am,
            a.time_in_pm, a.time_out_pm,
            a.time_in_night, a.time_out_night,
            a.site_assignment_am,
            a.site_assignment_pm,
            a.site_assignment_night,
            a.workday_type
        FROM attendance a
        WHERE a.date BETWEEN ? AND ?
    ";
    
    $attendance_stmt = $conn->prepare($attendance_query);
    $attendance_stmt->bind_param("ss", $date_from, $date_to);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    // Store attendance records by employee_id and date
    $attendance_records = [];
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance_records[$row['employee_id']][$row['date']] = $row;
    }
    
    // Build query based on whether specific employee or all employees
    if ($employee_id && $employee_id != 'all') {
        // Specific employee - show all their records in date range
        $reportQuery = "
            SELECT 
                p.id,
                p.employee_id,
                p.date_from,
                p.date_to,
                p.base_salary,
                p.total_deductions,
                p.net_pay,
                p.total_work_hours,
                p.status,
                p.salary_breakdown,
                e.first_name,
                e.last_name,
                e.position,
                e.daily_salary,
                e.contact_num
            FROM payroll p
            LEFT JOIN employees e ON e.id = p.employee_id
            WHERE p.employee_id = ? 
            AND ((p.date_from BETWEEN ? AND ?) OR (p.date_to BETWEEN ? AND ?))
            ORDER BY p.date_from DESC
        ";
        $stmt = $conn->prepare($reportQuery);
        $stmt->bind_param("issss", $employee_id, $date_from, $date_to, $date_from, $date_to);
    } else {
        // All employees - show all records in date range
        $reportQuery = "
            SELECT 
                p.id,
                p.employee_id,
                p.date_from,
                p.date_to,
                p.base_salary,
                p.total_deductions,
                p.net_pay,
                p.total_work_hours,
                p.status,
                p.salary_breakdown,
                e.first_name,
                e.last_name,
                e.position,
                e.daily_salary,
                e.contact_num
            FROM payroll p
            LEFT JOIN employees e ON e.id = p.employee_id
            WHERE (p.date_from BETWEEN ? AND ?) OR (p.date_to BETWEEN ? AND ?)
            ORDER BY e.first_name, e.last_name, p.date_from DESC
        ";
        $stmt = $conn->prepare($reportQuery);
        $stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
    }
    
    $stmt->execute();
    $reportResult = $stmt->get_result();
    
    $employees = [];
    $total_gross = 0;
    $total_deductions = 0;
    $total_net = 0;
    
    // Group records by employee for summary
    $employee_summary = [];
    
    // Get hourly rate for each employee
    $hourly_rate = 0;
    
    // Function to filter attendance by site// Function to filter attendance by site
function filterAttendanceBySite($attendance_records_for_employee, $date_from, $date_to, $site_filter, $employee_daily_salary) {
    $filtered_records = [];
    $site_hours = [];
    $site_pay = [];
    
    $hourly_rate = $employee_daily_salary / 8;
    
    // Get site name from ID if needed
    global $conn;
    $site_name = $site_filter;
    if (is_numeric($site_filter) && $site_filter != 'all') {
        $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
        $site_stmt = $conn->prepare($site_query);
        $site_stmt->bind_param("i", $site_filter);
        $site_stmt->execute();
        $site_result = $site_stmt->get_result();
        if ($site_row = $site_result->fetch_assoc()) {
            $site_name = $site_row['site_name'];
        }
    }
    
    foreach ($attendance_records_for_employee as $date => $record) {
        // Calculate hours for each session
        $am_hours = 0;
        $pm_hours = 0;
        $night_hours = 0;
        
        // AM Session
        if (!empty($record['time_in_am']) && !empty($record['time_out_am']) && 
            $record['time_in_am'] != '00:00:00' && $record['time_out_am'] != '00:00:00') {
            $am_hours = calculateHours($record['time_in_am'], $record['time_out_am']);
        }
        
        // PM Session
        if (!empty($record['time_in_pm']) && !empty($record['time_out_pm'])) {
            $pm_in = $record['time_in_pm'];
            $pm_out = $record['time_out_pm'];
            if ($pm_out == '00:00:00') {
                $pm_in_ts = strtotime($pm_in);
                $date_of_pm_in = date('Y-m-d', $pm_in_ts);
                $next_day = strtotime('+1 day', strtotime($date_of_pm_in));
                $pm_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
                $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
            } else {
                $pm_in_ts = strtotime($pm_in);
                $pm_out_ts = strtotime($pm_out);
                if ($pm_out_ts < $pm_in_ts) $pm_out_ts += 86400;
                $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
            }
            $pm_hours = max(0, $pm_hours);
        }
        
        // Night Session
        if (!empty($record['time_in_night']) && !empty($record['time_out_night'])) {
            $night_in = $record['time_in_night'];
            $night_out = $record['time_out_night'];
            if ($night_out == '00:00:00') {
                $night_in_ts = strtotime($night_in);
                $date_of_night_in = date('Y-m-d', $night_in_ts);
                $next_day = strtotime('+1 day', strtotime($date_of_night_in));
                $night_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
                $night_hours = ($night_out_ts - $night_in_ts) / 3600;
            } else {
                $night_in_ts = strtotime($night_in);
                $night_out_ts = strtotime($night_out);
                if ($night_out_ts < $night_in_ts) $night_out_ts += 86400;
                $night_hours = ($night_out_ts - $night_in_ts) / 3600;
            }
            $night_hours = max(0, $night_hours);
        }
        
        // Apply site filter - check each session
        $am_site = $record['site_assignment_am'] ?? null;
        $pm_site = $record['site_assignment_pm'] ?? null;
        $night_site = $record['site_assignment_night'] ?? null;
        
        // Check if this record should be included based on site filter
        $include_am = ($site_filter == 'all' || $am_site == $site_name) && $am_hours > 0;
        $include_pm = ($site_filter == 'all' || $pm_site == $site_name) && $pm_hours > 0;
        $include_night = ($site_filter == 'all' || $night_site == $site_name) && $night_hours > 0;
        
        if ($include_am) {
            $filtered_records[] = [
                'date' => $date,
                'session' => 'AM',
                'hours' => $am_hours,
                'site' => $am_site,
                'pay' => $am_hours * $hourly_rate
            ];
            
            if (!isset($site_hours[$am_site])) {
                $site_hours[$am_site] = 0;
                $site_pay[$am_site] = 0;
            }
            $site_hours[$am_site] += $am_hours;
            $site_pay[$am_site] += $am_hours * $hourly_rate;
        }
        
        if ($include_pm) {
            $filtered_records[] = [
                'date' => $date,
                'session' => 'PM',
                'hours' => $pm_hours,
                'site' => $pm_site,
                'pay' => $pm_hours * $hourly_rate
            ];
            
            if (!isset($site_hours[$pm_site])) {
                $site_hours[$pm_site] = 0;
                $site_pay[$pm_site] = 0;
            }
            $site_hours[$pm_site] += $pm_hours;
            $site_pay[$pm_site] += $pm_hours * $hourly_rate;
        }
        
        if ($include_night) {
            $filtered_records[] = [
                'date' => $date,
                'session' => 'Night',
                'hours' => $night_hours,
                'site' => $night_site,
                'pay' => $night_hours * $hourly_rate
            ];
            
            if (!isset($site_hours[$night_site])) {
                $site_hours[$night_site] = 0;
                $site_pay[$night_site] = 0;
            }
            $site_hours[$night_site] += $night_hours;
            $site_pay[$night_site] += $night_hours * $hourly_rate;
        }
    }
    
    return [
        'records' => $filtered_records,
        'site_hours' => $site_hours,
        'site_pay' => $site_pay,
        'total_hours' => array_sum($site_hours),
        'total_pay' => array_sum($site_pay)
    ];
}
    if ($reportResult && $reportResult->num_rows > 0) {
        while ($row = $reportResult->fetch_assoc()) {
            $employee_name = $row['first_name'] . ' ' . $row['last_name'];
            $employee_daily_salary = floatval($row['daily_salary']);
            
            // Get attendance records for this employee
            $emp_attendance = isset($attendance_records[$row['employee_id']]) ? $attendance_records[$row['employee_id']] : [];
            
            // Apply site filter to attendance
            $filtered_attendance = filterAttendanceBySite($emp_attendance, $date_from, $date_to, $site_filter, $employee_daily_salary);
            
            // Calculate base salary from filtered attendance if site filter is applied
            $filtered_base_salary = $filtered_attendance['total_pay'];
            
            // Use filtered base salary if site filter is not 'all', otherwise use payroll base_salary
            $base_salary_to_use = ($site_filter != 'all' && $filtered_base_salary > 0) ? $filtered_base_salary : floatval($row['base_salary']);
            $work_hours_to_use = ($site_filter != 'all' && $filtered_attendance['total_hours'] > 0) ? $filtered_attendance['total_hours'] : floatval($row['total_work_hours']);
            
            // Calculate net pay based on filtered amounts
            $deductions_to_use = floatval($row['total_deductions']);
            $net_pay_to_use = $base_salary_to_use - $deductions_to_use;
            
            $employee_record = [
                'id' => $row['employee_id'],
                'payroll_id' => $row['id'],
                'employee_name' => $employee_name,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'position' => $row['position'],
                'date_from' => date('M d, Y', strtotime($row['date_from'])),
                'date_to' => date('M d, Y', strtotime($row['date_to'])),
                'monthly_salary' => $base_salary_to_use,
                'total_deductions' => $deductions_to_use,
                'net_salary' => $net_pay_to_use,
                'work_hours' => $work_hours_to_use,
                'status' => $row['status'],
                'site_breakdown' => $filtered_attendance['site_hours'],
                'site_pay_breakdown' => $filtered_attendance['site_pay']
            ];
            
            // Only include if there's relevant data for the site filter
            if ($site_filter == 'all' || $work_hours_to_use > 0) {
                $employees[] = $employee_record;
                $total_gross += $base_salary_to_use;
                $total_deductions += $deductions_to_use;
                $total_net += $net_pay_to_use;
                
                // Build employee summary
                if (!isset($employee_summary[$employee_name])) {
                    $employee_summary[$employee_name] = [
                        'count' => 0,
                        'gross' => 0,
                        'deductions' => 0,
                        'net' => 0
                    ];
                }
                $employee_summary[$employee_name]['count']++;
                $employee_summary[$employee_name]['gross'] += $base_salary_to_use;
                $employee_summary[$employee_name]['deductions'] += $deductions_to_use;
                $employee_summary[$employee_name]['net'] += $net_pay_to_use;
            }
        }
    }
    
    // Get period description
    $period_desc = date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to));
    
    // Get site name for display
    $site_name = 'All Sites';
    if ($site_filter != 'all') {
        $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
        $site_stmt = $conn->prepare($site_query);
        $site_stmt->bind_param("i", $site_filter);
        $site_stmt->execute();
        $site_result = $site_stmt->get_result();
        if ($site_row = $site_result->fetch_assoc()) {
            $site_name = $site_row['site_name'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'employees' => $employees,
        'employee_summary' => $employee_summary,
        'totals' => [
            'count' => count($employees),
            'employee_count' => count($employee_summary),
            'gross' => $total_gross,
            'deductions' => $total_deductions,
            'net' => $total_net
        ],
        'period_desc' => $period_desc,
        'selected_employee' => $employee_id,
        'selected_site' => $site_filter,
        'selected_site_name' => $site_name
    ]);
    exit;
}

// Handle Generate Payroll Slip Download - FIXED Excel download with site filter
if (isset($_POST['download_payroll_slip'])) {
    $payroll_id = $_POST['payroll_id'];
    
    // Get payroll data with employee details
    $query = "SELECT p.*, 
                     e.first_name, e.last_name, e.position, e.daily_salary, e.contact_num
              FROM payroll p 
              JOIN employees e ON e.id = p.employee_id 
              WHERE p.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $payroll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $p = $result->fetch_assoc();
        
        // Get deductions
        $deductions = [];
        $table_check = $conn->query("SHOW TABLES LIKE 'payroll_deductions'");
        if ($table_check->num_rows > 0) {
            $deduction_query = "SELECT deduction_name, amount FROM payroll_deductions WHERE payroll_id = ?";
            $deduction_stmt = $conn->prepare($deduction_query);
            $deduction_stmt->bind_param("i", $payroll_id);
            $deduction_stmt->execute();
            $deduction_result = $deduction_stmt->get_result();
            
            while ($row = $deduction_result->fetch_assoc()) {
                $deductions[] = $row;
            }
        }
        
        // Set headers for Excel download (CSV with .xls extension for Excel compatibility)
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="Salary_Slip_' . $p['first_name'] . '_' . $p['last_name'] . '_' . date('Y-m-d') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Company Header
        fputcsv($output, ['JLC BEST CONSTRUCTION OPC - SALARY SLIP']);
        fputcsv($output, []);
        
        // Employee Information
        fputcsv($output, ['EMPLOYEE INFORMATION']);
        fputcsv($output, ['Employee ID:', $p['employee_id']]);
        fputcsv($output, ['Employee Name:', $p['first_name'] . ' ' . $p['last_name']]);
        fputcsv($output, ['Position:', $p['position']]);
        fputcsv($output, ['Contact:', $p['contact_num'] ?? 'N/A']);
        fputcsv($output, ['Daily Rate:', '₱' . number_format($p['daily_salary'], 2)]);
        fputcsv($output, []);
        
        // Pay Period
        fputcsv($output, ['PAY PERIOD']);
        fputcsv($output, ['From:', date('F d, Y', strtotime($p['date_from']))]);
        fputcsv($output, ['To:', date('F d, Y', strtotime($p['date_to']))]);
        fputcsv($output, []);
        
        // Salary Breakdown
        fputcsv($output, ['SALARY BREAKDOWN']);
        fputcsv($output, ['Description', 'Hours', 'Amount']);
        fputcsv($output, ['Total Work Hours', $p['total_work_hours'] . ' hrs', '']);
        fputcsv($output, ['Base Salary', '', '₱' . number_format($p['base_salary'], 2)]);
        
        // Site breakdown from salary_breakdown JSON
        $salary_breakdown = json_decode($p['salary_breakdown'] ?? '{}', true);
        if (!empty($salary_breakdown)) {
            fputcsv($output, []);
            fputcsv($output, ['SITE BREAKDOWN']);
            foreach ($salary_breakdown as $site => $data) {
                fputcsv($output, [$site, $data['hours'] . ' hrs', '₱' . number_format($data['pay'], 2)]);
            }
        }
        
        // Deductions
        if (!empty($deductions)) {
            fputcsv($output, []);
            fputcsv($output, ['DEDUCTIONS']);
            foreach ($deductions as $d) {
                fputcsv($output, [$d['deduction_name'], '', '- ₱' . number_format($d['amount'], 2)]);
            }
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Total Deductions', '', '- ₱' . number_format($p['total_deductions'], 2)]);
        fputcsv($output, []);
        fputcsv($output, ['NET PAY', '', '₱' . number_format($p['net_pay'], 2)]);
        fputcsv($output, []);
        fputcsv($output, ['Status:', ucfirst($p['status'] ?? 'pending')]);
        fputcsv($output, []);
        fputcsv($output, ['Generated on:', date('F d, Y h:i A')]);
        
        fclose($output);
        exit;
    } else {
        // If no data found, show error
        echo "No payroll record found";
        exit;
    }
}

// Handle Get Single Payroll for View - ENHANCED with full breakdown
if (isset($_GET['get_payroll'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $payroll_id = $_GET['get_payroll'];
        
        if (!$payroll_id) {
            $response['message'] = 'Invalid payroll ID';
            echo json_encode($response);
            exit;
        }
        
        // Get main payroll data with employee details
        $query = "SELECT p.*, 
                         e.first_name, e.last_name, e.position, e.daily_salary, e.contact_num, e.employment_type,
                         e.id as employee_id
                  FROM payroll p 
                  JOIN employees e ON e.id = p.employee_id 
                  WHERE p.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $payroll_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $payroll = $result->fetch_assoc();
            
            // Decode salary_breakdown JSON
            $payroll['salary_breakdown'] = json_decode($payroll['salary_breakdown'] ?? '{}', true);
            
            // Get deductions for this payroll
            $table_check = $conn->query("SHOW TABLES LIKE 'payroll_deductions'");
            if ($table_check->num_rows > 0) {
                $deduction_query = "SELECT deduction_name, amount FROM payroll_deductions WHERE payroll_id = ?";
                $deduction_stmt = $conn->prepare($deduction_query);
                $deduction_stmt->bind_param("i", $payroll_id);
                $deduction_stmt->execute();
                $deduction_result = $deduction_stmt->get_result();
                
                $deductions = [];
                while ($row = $deduction_result->fetch_assoc()) {
                    $deductions[] = $row;
                }
                $payroll['deductions'] = $deductions;
            }
            
            // Calculate attendance summary from attendance records for this date range
            $date_from = $payroll['date_from'];
            $date_to = $payroll['date_to'];
            $employee_id = $payroll['employee_id'];
            
            // Get employee daily salary and hourly rate
            $daily_salary = floatval($payroll['daily_salary']);
            $hourly_rate = $daily_salary / 8;
            
            // Get attendance records
            $attendance_query = "SELECT date, leave_type, workday_type,
                                        time_in_am, time_out_am,
                                        time_in_pm, time_out_pm,
                                        time_in_night, time_out_night,
                                        site_assignment_am, site_assignment_pm, site_assignment_night,
                                        status, pm_status, night_status
                                 FROM attendance 
                                 WHERE employee_id = ? AND date BETWEEN ? AND ?";
            $attendance_stmt = $conn->prepare($attendance_query);
            $attendance_stmt->bind_param("iss", $employee_id, $date_from, $date_to);
            $attendance_stmt->execute();
            $attendance_result = $attendance_stmt->get_result();
            
            // Calculate days between dates
            $start = new DateTime($date_from);
            $end = new DateTime($date_to);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            
            // Track totals
            $workday_type_summary = [];
            $days_present = 0;
            $days_on_leave = 0;
            $total_attendance_hours = 0;
            
            // Store attendance records by date for easier lookup
            $attendance_by_date = [];
            while ($row = $attendance_result->fetch_assoc()) {
                $attendance_by_date[$row['date']] = $row;
            }
            
            // Loop through each date in the period
            foreach ($period as $date) {
                $date_str = $date->format('Y-m-d');
                
                if (isset($attendance_by_date[$date_str])) {
                    $record = $attendance_by_date[$date_str];
                    $workday_type = $record['workday_type'] ?? 'Ordinary Working Day';
                    $is_on_leave = !empty($record['leave_type']) && $record['leave_type'] != 'None';
                    
                    // Calculate hours for this day
                    $am_hours = 0;
                    $pm_hours = 0;
                    $night_hours = 0;
                    
                    // AM hours
                    if (!empty($record['time_in_am']) && !empty($record['time_out_am']) && 
                        $record['time_in_am'] != '00:00:00' && $record['time_out_am'] != '00:00:00') {
                        $am_in = strtotime($record['time_in_am']);
                        $am_out = strtotime($record['time_out_am']);
                        if ($am_out < $am_in) $am_out += 86400;
                        $am_hours = ($am_out - $am_in) / 3600;
                    }
                    
                    // PM hours
                    if (!empty($record['time_in_pm']) && !empty($record['time_out_pm'])) {
                        $pm_in = $record['time_in_pm'];
                        $pm_out = $record['time_out_pm'];
                        if ($pm_out == '00:00:00') {
                            $pm_in_ts = strtotime($pm_in);
                            $date_of_pm_in = date('Y-m-d', $pm_in_ts);
                            $next_day = strtotime('+1 day', strtotime($date_of_pm_in));
                            $pm_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
                            $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
                        } else {
                            $pm_in_ts = strtotime($pm_in);
                            $pm_out_ts = strtotime($pm_out);
                            if ($pm_out_ts < $pm_in_ts) $pm_out_ts += 86400;
                            $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
                        }
                        $pm_hours = max(0, $pm_hours);
                    }
                    
                    // Night hours
                    if (!empty($record['time_in_night']) && !empty($record['time_out_night'])) {
                        $night_in = $record['time_in_night'];
                        $night_out = $record['time_out_night'];
                        if ($night_out == '00:00:00') {
                            $night_in_ts = strtotime($night_in);
                            $date_of_night_in = date('Y-m-d', $night_in_ts);
                            $next_day = strtotime('+1 day', strtotime($date_of_night_in));
                            $night_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
                            $night_hours = ($night_out_ts - $night_in_ts) / 3600;
                        } else {
                            $night_in_ts = strtotime($night_in);
                            $night_out_ts = strtotime($night_out);
                            if ($night_out_ts < $night_in_ts) $night_out_ts += 86400;
                            $night_hours = ($night_out_ts - $night_in_ts) / 3600;
                        }
                        $night_hours = max(0, $night_hours);
                    }
                    
                    $day_total_hours = $am_hours + $pm_hours + $night_hours;
                    $has_attendance = $day_total_hours > 0;
                    
                    // Initialize workday type summary
                    if (!isset($workday_type_summary[$workday_type])) {
                        $workday_type_summary[$workday_type] = [
                            'count' => 0,
                            'present_count' => 0,
                            'absent_count' => 0,
                            'leave_count' => 0,
                            'total_hours' => 0
                        ];
                    }
                    
                    $workday_type_summary[$workday_type]['count']++;
                    
                    if ($is_on_leave) {
                        $workday_type_summary[$workday_type]['leave_count']++;
                        $days_on_leave++;
                    } else if ($has_attendance) {
                        $workday_type_summary[$workday_type]['present_count']++;
                        $workday_type_summary[$workday_type]['total_hours'] += $day_total_hours;
                        $total_attendance_hours += $day_total_hours;
                        $days_present++;
                    } else {
                        $workday_type_summary[$workday_type]['absent_count']++;
                    }
                }
            }
            
            $payroll['workday_type_summary'] = $workday_type_summary;
            $payroll['days_present'] = $days_present;
            $payroll['days_on_leave'] = $days_on_leave;
            $payroll['total_days'] = iterator_count($period);
            
            // Also pass through the existing payroll fields that may have been calculated
            $payroll['regular_hours'] = isset($payroll['regular_hours']) ? floatval($payroll['regular_hours']) : 0;
            $payroll['overtime_hours'] = isset($payroll['overtime_hours']) ? floatval($payroll['overtime_hours']) : 0;
            $payroll['regular_night_hours'] = isset($payroll['regular_night_hours']) ? floatval($payroll['regular_night_hours']) : 0;
            $payroll['night_shift_overtime_hours'] = isset($payroll['night_shift_overtime_hours']) ? floatval($payroll['night_shift_overtime_hours']) : 0;
            $payroll['regular_pay'] = isset($payroll['regular_pay']) ? floatval($payroll['regular_pay']) : 0;
            $payroll['overtime_pay'] = isset($payroll['overtime_pay']) ? floatval($payroll['overtime_pay']) : 0;
            $payroll['regular_night_pay'] = isset($payroll['regular_night_pay']) ? floatval($payroll['regular_night_pay']) : 0;
            $payroll['night_shift_overtime_pay'] = isset($payroll['night_shift_overtime_pay']) ? floatval($payroll['night_shift_overtime_pay']) : 0;
            $payroll['holiday_guaranteed_pay'] = isset($payroll['holiday_guaranteed_pay']) ? floatval($payroll['holiday_guaranteed_pay']) : 0;
            
            $response['success'] = true;
            $response['data'] = $payroll;
        } else {
            $response['message'] = 'Payroll not found';
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("Get payroll error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}
// First, let's check the structure of the payroll table
$payrollColumns = [];
$checkPayrollTable = $conn->query("SHOW COLUMNS FROM payroll");
if ($checkPayrollTable) {
    while ($column = $checkPayrollTable->fetch_assoc()) {
        $payrollColumns[] = $column['Field'];
    }
}

// Fetch employee salary slip details (if employee_id is passed via GET)
$employeeData = null;
$deductionsData = [];
$payrollId = null;

if (isset($_GET['employee_id'])) {
    $employeeId = $_GET['employee_id'];

    // Check if payroll record already exists for the employee
    $payrollQuery = "
        SELECT p.id, p.date_from, p.date_to, p.base_salary, p.total_deductions, p.net_pay, p.total_work_hours, p.status, p.salary_breakdown
        FROM payroll p 
        WHERE p.employee_id = ? 
        ORDER BY p.date_from DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($payrollQuery);
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $payrollResult = $stmt->get_result();

    if ($payrollResult->num_rows > 0) {
        // Use existing payroll record
        $payrollData = $payrollResult->fetch_assoc();
        $payrollId = $payrollData['id'];
        
        // Fetch employee details
        $employeeQuery = "
            SELECT e.id, CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
                   e.contact_num, e.position, e.daily_salary, e.hourly_rate,
                   e.email
            FROM employees e
            WHERE e.id = ? AND e.status = 'active'
        ";
        $stmt = $conn->prepare($employeeQuery);
        $stmt->bind_param("i", $employeeId);
        $stmt->execute();
        $employeeResult = $stmt->get_result();
        
        if ($employeeResult->num_rows > 0) {
            $employeeData = $employeeResult->fetch_assoc();
            // Merge payroll data
            $employeeData['payroll_id'] = $payrollData['id'];
            $employeeData['date_from'] = $payrollData['date_from'];
            $employeeData['date_to'] = $payrollData['date_to'];
            $employeeData['base_salary'] = $payrollData['base_salary'];
            $employeeData['total_deductions'] = $payrollData['total_deductions'];
            $employeeData['net_salary'] = $payrollData['net_pay'];
            $employeeData['total_work_hours'] = $payrollData['total_work_hours'];
            $employeeData['monthly_salary'] = $payrollData['base_salary'];
            $employeeData['status'] = $payrollData['status'];
            $employeeData['salary_breakdown'] = json_decode($payrollData['salary_breakdown'] ?? '{}', true);
        }
        
        // Fetch deductions for this payroll
        $deductionsQuery = "
            SELECT pd.deduction_name, pd.amount 
            FROM payroll_deductions pd
            WHERE pd.payroll_id = ?
        ";
        $stmt = $conn->prepare($deductionsQuery);
        $stmt->bind_param("i", $payrollId);
        $stmt->execute();
        $deductionsResult = $stmt->get_result();

        while ($row = $deductionsResult->fetch_assoc()) {
            $deductionsData[] = $row;
        }
    } else {
        // No payroll record found - calculate from attendance (fallback)
        $salaryDetailsQuery = "
            SELECT 
                e.id, 
                CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
                e.contact_num, 
                e.position, 
                e.daily_salary,
                e.hourly_rate,
                e.email
            FROM employees e
            WHERE e.id = ? AND e.status = 'active'
        ";

        $stmt = $conn->prepare($salaryDetailsQuery);
        $stmt->bind_param("i", $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $employeeData = $result->fetch_assoc();
            
            // Calculate from attendance for current month
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-t');
            
            // Get employee daily salary
            $daily_salary = $employeeData['daily_salary'];
            $hourly_rate = $daily_salary / 8;
            
            // Get attendance records
            $attendance_query = "SELECT date, status, time_in_am, time_out_am, time_in_pm, time_out_pm, 
                                        time_in_night, time_out_night, holiday_type,
                                        site_assignment_am, site_assignment_pm, site_assignment_night
                                 FROM attendance 
                                 WHERE employee_id = ? AND date BETWEEN ? AND ?";
            $attendance_stmt = $conn->prepare($attendance_query);
            $attendance_stmt->bind_param("iss", $employeeId, $date_from, $date_to);
            $attendance_stmt->execute();
            $attendance_result = $attendance_stmt->get_result();
            
            $total_work_hours = 0;
            $regular_pay = 0;
            $overtime_pay = 0;
            $night_shift_pay = 0;
            $holiday_bonus = 0;
            $site_breakdown = [];
            
            while ($row = $attendance_result->fetch_assoc()) {
                $am_hours = calculateHours($row['time_in_am'], $row['time_out_am']);
                $pm_hours = calculateHours($row['time_in_pm'], $row['time_out_pm']);
                $night_hours = calculateHours($row['time_in_night'], $row['time_out_night']);
                
                $day_hours = $am_hours + $pm_hours;
                $total_work_hours += $day_hours + $night_hours;
                
                // Track site-based hours
                $am_site = $row['site_assignment_am'] ?? 'Main Office';
                $pm_site = $row['site_assignment_pm'] ?? $am_site;
                $night_site = $row['site_assignment_night'] ?? $pm_site;
                
                if ($am_hours > 0) {
                    if (!isset($site_breakdown[$am_site])) {
                        $site_breakdown[$am_site] = ['hours' => 0, 'pay' => 0];
                    }
                    $site_breakdown[$am_site]['hours'] += $am_hours;
                    $site_breakdown[$am_site]['pay'] += $am_hours * $hourly_rate;
                    $regular_pay += $am_hours * $hourly_rate;
                }
                
                if ($pm_hours > 0) {
                    if (!isset($site_breakdown[$pm_site])) {
                        $site_breakdown[$pm_site] = ['hours' => 0, 'pay' => 0];
                    }
                    $site_breakdown[$pm_site]['hours'] += $pm_hours;
                    $site_breakdown[$pm_site]['pay'] += $pm_hours * $hourly_rate;
                    $regular_pay += $pm_hours * $hourly_rate;
                }
                
                if ($night_hours > 0) {
                    if (!isset($site_breakdown[$night_site])) {
                        $site_breakdown[$night_site] = ['hours' => 0, 'pay' => 0];
                    }
                    $site_breakdown[$night_site]['hours'] += $night_hours;
                    $site_breakdown[$night_site]['pay'] += $night_hours * $hourly_rate * 1.25;
                    $night_shift_pay += $night_hours * $hourly_rate * 1.25;
                }
                
                // Holiday bonus
                if (!empty($row['holiday_type']) && ($am_hours > 0 || $pm_hours > 0 || $night_hours > 0)) {
                    $holiday_bonus += calculateHolidayPay($daily_salary, $row['holiday_type'], true);
                }
            }
            
            $base_salary = $regular_pay + $overtime_pay + $night_shift_pay + $holiday_bonus;
            
            // Get deductions
            $deductions_query = "SELECT deduction_name, amount FROM deduction WHERE employee_id = ? AND status = 'active'";
            $deductions_stmt = $conn->prepare($deductions_query);
            $deductions_stmt->bind_param("i", $employeeId);
            $deductions_stmt->execute();
            $deductions_result = $deductions_stmt->get_result();
            
            $total_deductions = 0;
            while ($row = $deductions_result->fetch_assoc()) {
                $deductionsData[] = $row;
                $total_deductions += $row['amount'];
            }
            
            $net_salary = $base_salary - $total_deductions;
            
            // Add calculated values to employee data
            $employeeData['date_from'] = $date_from;
            $employeeData['date_to'] = $date_to;
            $employeeData['base_salary'] = $base_salary;
            $employeeData['monthly_salary'] = $base_salary;
            $employeeData['total_deductions'] = $total_deductions;
            $employeeData['net_salary'] = $net_salary;
            $employeeData['total_work_hours'] = $total_work_hours;
            $employeeData['status'] = 'pending';
            $employeeData['payroll_id'] = null;
            $employeeData['salary_breakdown'] = $site_breakdown;
        }
    }
}

// Get all active employees for the dropdown
$employees_query = "SELECT id, first_name, last_name FROM employees WHERE status = 'active' ORDER BY first_name, last_name";
$employees_result = $conn->query($employees_query);

// Get all sites for the dropdown
$sites_query = "SELECT id, site_name FROM site_monitoring ORDER BY site_name";
$sites_result = $conn->query($sites_query);

// Get all payroll records with employee details for the main table
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$sql = "
    SELECT p.id, p.date_from, p.date_to, 
           COALESCE(p.base_salary, 0) as base_salary, 
           COALESCE(p.total_deductions, 0) as total_deductions, 
           COALESCE(p.net_pay, 0) as net_pay, 
           COALESCE(p.total_work_hours, 0) as total_work_hours, 
           p.status,
           CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
           e.position, e.id as employee_id, 
           COALESCE(e.daily_salary, 0) as daily_salary,
           e.contact_num
    FROM payroll p
    LEFT JOIN employees e ON e.id = p.employee_id
    WHERE MONTH(p.date_from) = ? AND YEAR(p.date_from) = ?
    ORDER BY p.date_from DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Slip</title>
    <link rel="stylesheet" href="./assets/css/salarySlip2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Include html2canvas for image capture -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        /* Full height layout fix */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        
        body {
            display: flex;
            flex-direction: column;
            padding-top: var(--header-height, 60px) !important;
        }
        
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            padding-bottom: 80px;
            height: calc(100vh - var(--header-height, 60px));
        }
        
        .content-wrapper {
            min-height: 100%;
            padding-bottom: 20px;
        }
        
        .employee-info {
            display: grid;
            gap: 4px;
        }
        
        .employee-info strong {
            color: var(--sidebar-dark-green);
            font-weight: 600;
        }
        
        .net-pay-cell {
            font-weight: 700;
            color: var(--success-color);
        }
        
        .deductions-cell {
            color: var(--danger-color);
        }
        
        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 15px 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .search-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .main-search-container {
            position: relative;
            flex: 1;
            max-width: 400px;
        }
        
        .main-search-input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }
        
        .main-search-input:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
            outline: none;
        }
        
        .main-search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            cursor: pointer;
        }
        
        .main-search-icon:hover {
            color: #75e6da;
        }
        
        .main-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            margin-top: 5px;
        }
        
        .main-search-results.show {
            display: block;
        }
        
        .main-search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s;
        }
        
        .main-search-result-item:hover {
            background: #e6f7f5;
        }
        
        .main-search-result-item:last-child {
            border-bottom: none;
        }
        
        .main-search-result-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .main-search-result-details {
            font-size: 0.85rem;
            color: #7f8c8d;
            display: flex;
            gap: 15px;
        }
        
        .generate-report-btn {
            background: #75e6da;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(117, 230, 218, 0.3);
            white-space: nowrap;
            text-decoration: none;
            margin: 20px 0;
        }
        
        .generate-report-btn:hover {
            background: #62d4c8;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(117, 230, 218, 0.4);
        }
        
        .pay-period {
            color: #666;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .site-info-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background-color: var(--light-green);
            color: var(--sidebar-dark-green);
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .site-info-badge i {
            color: var(--sidebar-green);
        }
        
        .salary-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .daily-rate-badge {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .hourly-rate-badge {
            background-color: #fff3e0;
            color: #f57c00;
        }

        /* Status Badge Styles */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            min-width: 80px;
        }
        
        .status-paid {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeeba;
        }

        /* Action Buttons - Only View, Download and Delete */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .view-btn {
            background: linear-gradient(135deg, #75e6da, #62d4c8);
            color: white;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(117, 230, 218, 0.3);
            letter-spacing: 0.3px;
        }
        
        .view-btn:hover {
            background: linear-gradient(135deg, #62d4c8, #4fb3aa);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(117, 230, 218, 0.4);
        }
        
        .download-btn {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }
        
        .download-btn:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }
        
        .delete-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.3);
        }
        
        .delete-btn:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.4);
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            min-width: 300px;
            max-width: 400px;
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        .toast.success {
            border-left-color: #28a745;
        }

        .toast.success i {
            color: #28a745;
        }

        .toast.error {
            border-left-color: #dc3545;
        }

        .toast.error i {
            color: #dc3545;
        }

        .toast.warning {
            border-left-color: #ffc107;
        }

        .toast.warning i {
            color: #ffc107;
        }

        .toast.info {
            border-left-color: #17a2b8;
        }

        .toast.info i {
            color: #17a2b8;
        }

        .toast i {
            font-size: 1.2rem;
        }

        .toast-content {
            flex: 1;
            font-size: 0.95rem;
            color: #333;
        }

        .toast-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            transition: color 0.3s;
        }

        .toast-close:hover {
            color: #333;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(100%);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Report Modal Styles - Now always large */
        .report-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .report-modal.show {
            display: flex;
        }

        .report-modal-content {
            background-color: #fff;
            margin: auto;
            padding: 0;
            border-radius: 12px;
            width: 95%;
            max-width: 1400px;
            height: 90vh;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.3s;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .report-modal-header {
            padding: 20px 25px;
            background: #75e6da;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
            flex-shrink: 0;
        }

        .report-modal-header h3 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-modal-header button {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .report-modal-header button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .report-modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }

        /* ============ DATE PICKER STYLES FROM ATTENDANCE.PHP ============ */
        .date-picker-wrapper {
            position: relative;
            width: 100%;
        }
        
        .date-input-group {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
        }
        
        .date-field {
            width: 100%;
            padding: 12px 15px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            cursor: pointer;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .date-field:hover {
            border-color: #75e6da;
        }
        
        .date-field:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
            outline: none;
        }
        
        .calendar-dropdown-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .calendar-dropdown-btn:hover {
            color: #75e6da;
        }
        
        .modal-calendar-wrapper {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            display: none;
        }
        
        .modal-calendar-wrapper.show {
            display: block;
        }
        
        .modal-calendar-box {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .modal-calendar-header {
            background: linear-gradient(135deg, #75e6da, #62d4c8);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-calendar-month-year {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .modal-calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .modal-calendar-nav-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1.2rem;
        }
        
        .modal-calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .modal-calendar-selectors {
            display: flex;
            gap: 10px;
            padding: 15px 15px 5px 15px;
            background: white;
        }
        
        .modal-calendar-select {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }
        
        .modal-calendar-select:hover {
            border-color: #75e6da;
        }
        
        .modal-calendar-select:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        }
        
        .modal-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        .modal-calendar-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 10px;
            background: white;
        }
        
        .modal-calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 0.9rem;
            color: #2c3e50;
            text-decoration: none;
        }
        
        .modal-calendar-day:hover {
            background: #e6f7f5;
            color: #75e6da;
        }
        
        .modal-calendar-day.selected {
            background: #75e6da;
            color: white;
            font-weight: 600;
        }
        
        .modal-calendar-day.today {
            border: 2px solid #75e6da;
            font-weight: 600;
        }
        
        .modal-calendar-day.weekend {
            color: #e74c3c;
        }
        
        .modal-calendar-day.other-month {
            color: #bdc3c7;
        }
        
        .modal-calendar-footer {
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }
        
        .modal-calendar-action-btn {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
        }
        
        .modal-calendar-action-btn.clear {
            background: #f8f9fa;
            color: #7f8c8d;
            border: 1px solid #bdc3c7;
        }
        
        .modal-calendar-action-btn.clear:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }
        
        .modal-calendar-action-btn.today {
            background: #75e6da;
            color: white;
            border: 1px solid #75e6da;
        }
        
        .modal-calendar-action-btn.today:hover {
            background: #62d4c8;
        }

        /* Report Controls */
        .report-controls {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .report-controls.active {
            display: flex;
        }
        
        .report-select-group {
            flex: 1;
            min-width: 180px;
        }
        
        .report-select-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .report-select-group label i {
            color: #75e6da;
            margin-right: 5px;
        }
        
        .report-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .report-select:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
            outline: none;
        }
        
        .generate-report-submit-btn {
            background: #75e6da;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 48px;
        }
        
        .generate-report-submit-btn:hover {
            background: #62d4c8;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
        }

        /* Employee Summary Cards */
        .employee-summary-section {
            margin-bottom: 25px;
        }
        
        .employee-summary-title {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .employee-summary-title i {
            color: #75e6da;
        }
        
        .employee-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .employee-summary-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #75e6da;
        }
        
        .employee-summary-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .employee-summary-stats {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #666;
        }
        
        .employee-summary-stats span {
            font-weight: 600;
            color: #00838f;
        }

        .report-summary-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .report-summary-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #75e6da;
        }

        .report-summary-card .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .report-summary-card .value {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin-top: 5px;
        }

        .report-table-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .report-table th {
            background: #75e6da;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #ddd;
        }

        .report-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .report-table tr:hover {
            background-color: #e6f7f5;
        }

        .report-table .text-right {
            text-align: right;
        }

        .report-table .currency {
            font-weight: 600;
        }

        .report-table .total-row {
            background: linear-gradient(135deg, #e6f7f5, #d1f0ec);
            font-weight: bold;
        }

        .report-table .total-row td {
            border-top: 2px solid #75e6da;
        }

        .report-site-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #e6f7f5;
            color: #2c3e50;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }

        .report-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #75e6da;
            display: flex;
            justify-content: space-between;
            color: #666;
            font-size: 12px;
        }

        .report-print-btn {
            background: #75e6da;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .report-print-btn:hover {
            background: #62d4c8;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
        }
        
        .report-download-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }
        
        .report-download-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3);
        }

        .report-loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .report-loading i {
            font-size: 2.5rem;
            color: #75e6da;
            margin-bottom: 10px;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ============ MULTI-PAGE PRINT STYLES ============ */
        @media print {
            /* Hide elements not needed for printing */
            body * {
                visibility: hidden;
            }
            
            /* Only show the report modal content */
            .report-modal, 
            .report-modal-content,
            .report-modal-body,
            #reportContent,
            #reportContent * {
                visibility: visible;
            }
            
            /* Position the report content for printing */
            .report-modal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                display: block !important;
                background: none;
                overflow: visible;
            }
            
            .report-modal-content {
                position: relative;
                left: 0;
                top: 0;
                width: 100%;
                max-width: 100%;
                height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border-radius: 0;
                overflow: visible;
                background: white;
            }
            
            .report-modal-header, 
            .report-controls,
            .modal-calendar-wrapper,
            .calendar-dropdown-btn {
                display: none !important;
            }
            
            /* FIXED: Hide the print and download Excel buttons when printing */
            .report-print-btn,
            .report-download-btn {
                display: none !important;
            }
            
            .report-modal-body {
                padding: 0.5in;
                overflow: visible !important;
                height: auto;
            }
            
            /* Allow table to break across pages */
            .report-table-container {
                max-height: none;
                overflow: visible !important;
                border: none;
            }
            
            .report-table {
                page-break-inside: auto;
                border-collapse: collapse;
                width: 100%;
            }
            
            .report-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .report-table thead {
                display: table-header-group;
            }
            
            .report-table tfoot {
                display: table-footer-group;
            }
            
            .report-table th {
                background: #75e6da !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .report-table tbody tr {
                page-break-inside: avoid;
            }
            
            .report-summary-cards,
            .employee-summary-grid {
                page-break-inside: avoid;
            }
            
            .report-table .total-row {
                page-break-before: avoid;
                page-break-after: avoid;
            }
            
            .report-footer {
                page-break-before: avoid;
                margin-top: 20px;
            }
            
            .status-paid, 
            .status-pending,
            .report-site-badge,
            .employee-summary-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .report-footer:after {
                content: "Page " counter(page);
                counter-increment: page;
            }
        }

        /* Controls */
        .controls {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin: 20px 0;
            border: 1px solid #e9ecef;
        }
        
        .controls label {
            color: #2c3e50;
            font-weight: 600;
        }
        
        .controls label i {
            color: #75e6da;
            margin-right: 5px;
        }

        .filter-btn {
            background: #75e6da;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: #62d4c8;
            transform: translateY(-2px);
        }

        /* Table responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
        }

        .salary-table-container {
            min-width: 100%;
            display: inline-block;
        }

        .salary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .salary-table th {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .salary-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .salary-table tbody tr:hover {
            background: #f8fafc;
        }

        .net-pay-highlight {
            font-weight: 700;
            color: #00838f;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }

        /* Salary Modal Styles - Updated with system color palette #75e6da */
        .salary-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .salary-modal.show {
            display: flex;
        }

        .salary-modal-content {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        .salary-header {
            padding: 20px 25px;
            background: #75e6da;
            color: white;
            border-radius: 16px 16px 0 0;
            text-align: center;
        }

        .salary-header h2 {
            margin: 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #ffffff;
        }

        .salary-header h2 i {
            color: #ffffff;
        }

        .salary-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
            color: #ffffff;
        }

        .employee-info-modal {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e9ecef;
        }

        .employee-info-modal div {
            padding: 8px;
            background: white;
            border-radius: 8px;
        }

        .employee-info-modal strong {
            color: #2c3e50;
            display: block;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .employee-info-modal span {
            font-weight: 600;
            color: #2c3e50;
        }

        .salary-table-modal {
            width: 100%;
            border-collapse: collapse;
        }

        .salary-table-modal th {
            background: #f1f5f9;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
        }

        .salary-table-modal td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        .salary-table-modal .currency {
            text-align: right;
            font-weight: 600;
        }

        .salary-table-modal .total-deductions {
            color: #dc3545;
        }

        .salary-table-modal .net-pay-row {
            background: #e6f7f5;
            font-weight: 700;
        }

        .net-pay-display {
            background: #75e6da;
            color: white;
            padding: 20px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            padding: 20px;
            justify-content: flex-end;
            align-items: center;
        }

        /* Save as Image Button - Styled to match system color palette */
        .save-image-btn {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(255, 152, 0, 0.3);
            margin-right: 10px;
        }

        .save-image-btn:hover {
            background: linear-gradient(135deg, #f57c00, #ef6c00);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(255, 152, 0, 0.4);
        }

        .save-image-btn i {
            font-size: 0.9rem;
        }

        .close-btn-modal {
            background: #75e6da;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .close-btn-modal:hover {
            background: #62d4c8;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 10001;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #75e6da;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .download-success {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 25px;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            z-index: 10002;
            animation: slideInRight 0.3s ease;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Payroll Summary Container for Modal */
        .payroll-summary-container {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 20px;
            padding: 20px;
            margin: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(117, 230, 218, 0.2);
        }
        
        .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px dashed #d1d5db;
        }
        
        .summary-header h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-header h4 i {
            color: #75e6da;
            background: white;
            padding: 8px;
            border-radius: 50%;
            box-shadow: 0 2px 10px rgba(117, 230, 218, 0.3);
        }
        
        .employee-badge {
            background: white;
            padding: 8px 16px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #1e293b;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
        }
        
        .employee-badge i {
            color: #75e6da;
        }
        
        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px 10px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #75e6da, #62d4c8);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(117, 230, 218, 0.15);
            border-color: #75e6da;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #e0f7fa, #b2ebf2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: #00838f;
            font-size: 1.1rem;
        }
        
        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .salary-breakdown {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 25px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
            border: 1px solid #e2e8f0;
        }
        
        .breakdown-header {
            background: linear-gradient(90deg, #f1f5f9, #f8fafc);
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
        }
        
        .breakdown-header i {
            color: #75e6da;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s ease;
        }
        
        .breakdown-row:hover {
            background: #f8fafc;
        }
        
        .breakdown-row:last-child {
            border-bottom: none;
        }
        
        .breakdown-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .breakdown-label i {
            width: 20px;
            color: #75e6da;
        }
        
        .breakdown-label .badge {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            color: #475569;
            margin-left: 8px;
        }
        
        .breakdown-value {
            font-weight: 600;
            color: #1e293b;
        }
        
        .breakdown-value.highlight {
            color: #00838f;
            font-weight: 700;
        }
        
        .breakdown-row.total-deductions {
            background: #fef2f2;
        }
        
        .breakdown-row.total-deductions .breakdown-value {
            color: #dc2626;
            font-weight: 700;
        }
        
        .breakdown-row.net-pay {
            background: linear-gradient(90deg, #e0f7fa, #b2ebf2);
            font-weight: 700;
            border-top: 2px solid #80deea;
        }
        
        .breakdown-row.net-pay .breakdown-label {
            font-weight: 700;
            color: #006064;
        }
        
        .breakdown-row.net-pay .breakdown-value {
            font-size: 1.2rem;
            color: #006064;
            font-weight: 800;
        }
        
        .hourly-rate-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 20px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px dashed #cbd5e1;
            font-size: 0.9rem;
            color: #475569;
        }
        
        .hourly-rate-info strong {
            color: #1e293b;
            font-size: 1rem;
        }
        
        .hourly-rate-info .rate-value {
            background: white;
            padding: 4px 12px;
            border-radius: 30px;
            border: 1px solid #75e6da;
            color: #0f172a;
            font-weight: 600;
        }
        
        .deductions-section-enhanced {
            background: white;
            border-radius: 16px;
            padding: 0;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
            margin: 20px;
        }
        
        .deductions-header-enhanced {
            background: linear-gradient(90deg, #f8fafc, #f1f5f9);
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .deductions-header-enhanced h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .deductions-header-enhanced h5 i {
            color: #ef4444;
        }
        
        .deductions-list-enhanced {
            padding: 10px 0;
            max-height: 250px;
            overflow-y: auto;
        }
        
        .deduction-item-enhanced {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s ease;
        }
        
        .deduction-item-enhanced:hover {
            background: #f8fafc;
        }
        
        .deduction-item-enhanced:last-child {
            border-bottom: none;
        }
        
        .deduction-info-enhanced {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .deduction-icon {
            width: 30px;
            height: 30px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
            font-size: 0.85rem;
        }
        
        .deduction-name-enhanced {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.95rem;
        }
        
        .deduction-amount-enhanced {
            font-weight: 700;
            color: #dc2626;
            margin-right: 15px;
            font-size: 0.95rem;
        }
        
        .empty-deductions-enhanced {
            text-align: center;
            padding: 30px 20px;
            color: #94a3b8;
        }
        
        .empty-deductions-enhanced i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #cbd5e1;
        }
        
        .total-summary-card {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-radius: 16px;
            padding: 20px;
            margin: 20px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        .total-summary-label {
            font-size: 1rem;
            font-weight: 500;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .total-summary-value {
            font-size: 2rem;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 24px;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Image capture container styles */
        #salary-slip-capture-area {
            background-color: white;
            border-radius: 16px;
            overflow: hidden;
        }

        /* Ensure all content is visible in the captured image */
        .salary-modal-content-capture {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
        }

        /* Site badge in report table */
        .site-breakdown-cell {
            font-size: 11px;
        }
        
        .site-badge {
            display: inline-block;
            background: #e6f7f5;
            color: #00838f;
            padding: 2px 6px;
            border-radius: 10px;
            margin: 2px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Header -->
    <?php include_once("./includes/header.php"); ?>

    <main class="content">
        <div class="content-wrapper">
            
            <!-- Controls - Month/Year Filter with updated icon colors -->
            <div class="controls">
                <form method="GET" action="salarySlip.php" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="month"><i class="fas fa-calendar-alt" style="color: #75e6da;"></i> Month:</label>
                        <select name="month" id="month" class="form-control" style="width: auto; padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 30px;">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0,0,0,$m,10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="year"><i class="fas fa-calendar" style="color: #75e6da;"></i> Year:</label>
                        <input type="number" name="year" id="year" value="<?php echo $year; ?>" required 
                               min="2000" max="2100" class="form-control" style="width: 100px; padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 30px;">
                    </div>

                    <button type="submit" class="filter-btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </form>
            </div>

            <!-- Main Page Payroll Search -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
                <div class="main-search-container">
                    <input type="text" id="mainSearchInput" class="main-search-input" placeholder="Search salary slips by employee name, position, or date...">
                    <i class="fas fa-search main-search-icon" onclick="performSalarySearch()"></i>
                    <div class="main-search-results" id="mainSearchResults"></div>
                </div>
                <button class="generate-report-btn" onclick="showReportModal()">
                    <i class="fas fa-chart-bar"></i> Generate Salary Report
                </button>
            </div>

            <!-- Salary Table - With Download button -->
            <div class="table-responsive">
                <div class="salary-table-container">
                    <table class="salary-table" id="salaryTable">
                        <thead>
                            <tr>
                                <th>Employee Information</th>
                                <th>Daily Salary</th>
                                <th>Work Hours</th>
                                <th>Base Salary</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Pay Period</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salaryTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): 
                                    $date_from = date('M d, Y', strtotime($row['date_from']));
                                    $date_to = date('M d, Y', strtotime($row['date_to']));
                                    $status = $row['status'] ?? 'pending';
                                    $statusClass = $status == 'paid' ? 'status-paid' : 'status-pending';
                                    $statusIcon = $status == 'paid' ? 'fa-check-circle' : 'fa-clock';
                                ?>
                                    <tr class="salary-row" data-id="<?php echo $row['id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($row['employee_name'])); ?>" data-position="<?php echo htmlspecialchars(strtolower($row['position'])); ?>" data-date-from="<?php echo $row['date_from']; ?>" data-date-to="<?php echo $row['date_to']; ?>">
                                        <td>
                                            <div class="employee-info">
                                                <div><strong>Name:</strong> <?php echo htmlspecialchars($row['employee_name']); ?></div>
                                                <div><strong>Position:</strong> <?php echo htmlspecialchars($row['position']); ?></div>
                                                <div><strong>ID:</strong> <?php echo $row['employee_id']; ?></div>
                                                <div><strong>Contact:</strong> <?php echo htmlspecialchars($row['contact_num'] ?? 'N/A'); ?></div>
                                            </div>
                                        </td>
                                        <td>₱<?php echo number_format(floatval($row['daily_salary']), 2); ?></td>
                                        <td><?php echo number_format(floatval($row['total_work_hours']), 2); ?> hrs</td>
                                        <td>₱<?php echo number_format(floatval($row['base_salary']), 2); ?></td>
                                        <td class="deductions-cell">₱<?php echo number_format(floatval($row['total_deductions']), 2); ?></td>
                                        <td class="net-pay-highlight">₱<?php echo number_format(floatval($row['net_pay']), 2); ?></td>
                                        <td>
                                            <div class="employee-info">
                                                <div><strong>From:</strong> <?php echo $date_from; ?></div>
                                                <div><strong>To:</strong> <?php echo $date_to; ?></div>
                                                <div class="pay-period">
                                                    <?php echo date('F', mktime(0,0,0,$month,10)) . ' ' . $year; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <i class="fas <?php echo $statusIcon; ?>"></i>
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="viewSalarySlip(<?php echo $row['id']; ?>)" class="view-btn" title="View Salary Slip">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <form method="POST" action="salarySlip.php" style="display: inline;">
                                                    <input type="hidden" name="download_payroll_slip" value="1">
                                                    <input type="hidden" name="payroll_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" class="download-btn" title="Download Salary Slip">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </form>
                                                <button onclick="openDeletePayrollModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['employee_name']); ?>', '<?php echo $date_from; ?>', '<?php echo $date_to; ?>', '₱<?php echo number_format(floatval($row['net_pay']), 2); ?>')" 
                                                   class="delete-btn" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                            <p>No salary slip records found for the selected period</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include_once("./includes/footer.php"); ?>

    <!-- Salary Slip Modal - Enhanced with system color palette #75e6da -->
    <?php if ($employeeData): ?>
    <div id="salary-modal" class="salary-modal">
        <div class="salary-modal-content" id="salary-slip-content">
            <div class="salary-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> SALARY SLIP</h2>
                <p>
                    <?= date('F Y') ?> • 
                    Pay Period: 
                    <?php 
                    if (!empty($employeeData['date_from']) && $employeeData['date_from'] != '0000-00-00') {
                        echo date('M d', strtotime($employeeData['date_from']));
                    } else {
                        echo date('M 01');
                    }
                    ?> - 
                    <?php 
                    if (!empty($employeeData['date_to']) && $employeeData['date_to'] != '0000-00-00') {
                        echo date('M d, Y', strtotime($employeeData['date_to']));
                    } else {
                        echo date('M t, Y');
                    }
                    ?>
                </p>
            </div>
            
            <div class="employee-info-modal">
                <div>
                    <strong><i class="fas fa-id-card"></i> Employee ID:</strong>
                    <span><?= htmlspecialchars($employeeData['id']) ?></span>
                </div>
                <div>
                    <strong><i class="fas fa-user"></i> Employee Name:</strong>
                    <span><?= htmlspecialchars($employeeData['employee_name']) ?></span>
                </div>
                <div>
                    <strong><i class="fas fa-briefcase"></i> Position:</strong>
                    <span><?= htmlspecialchars($employeeData['position']) ?></span>
                </div>
                <div>
                    <strong><i class="fas fa-calculator"></i> Rate:</strong>
                    <span>
                        <?php if ($employeeData['daily_salary'] > 0): ?>
                            Daily: ₱<?= number_format($employeeData['daily_salary'], 2) ?>/day
                        <?php elseif ($employeeData['hourly_rate'] > 0): ?>
                            Hourly: ₱<?= number_format($employeeData['hourly_rate'], 2) ?>/hour
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Enhanced Salary Breakdown with Site Breakdown -->
            <div class="payroll-summary-container" style="margin: 20px;">
                <div class="summary-header">
                    <h4>
                        <i class="fas fa-coins"></i>
                        Salary Breakdown
                    </h4>
                </div>
                
                <div class="salary-breakdown">
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-clock"></i>
                            Work Hours
                            <span class="badge"><?= number_format($employeeData['total_work_hours'] ?? 0, 2) ?> hrs</span>
                        </div>
                        <div class="breakdown-value">-</div>
                    </div>
                    
                    <!-- Site Breakdown -->
                    <?php if (!empty($employeeData['salary_breakdown'])): ?>
                        <?php foreach ($employeeData['salary_breakdown'] as $site => $breakdown): ?>
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-building"></i>
                                    <?= htmlspecialchars($site) ?>
                                    <span class="badge"><?= number_format($breakdown['hours'], 2) ?> hrs</span>
                                </div>
                                <div class="breakdown-value">₱<?= number_format($breakdown['pay'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-money-bill"></i>
                            Base Salary
                        </div>
                        <div class="breakdown-value">₱<?= number_format($employeeData['monthly_salary'] ?? $employeeData['base_salary'] ?? 0, 2) ?></div>
                    </div>
                    
                    <?php if (count($deductionsData) > 0): ?>
                        <?php foreach ($deductionsData as $deduction): ?>
                            <div class="breakdown-row total-deductions">
                                <div class="breakdown-label">
                                    <i class="fas fa-minus-circle"></i>
                                    <?= htmlspecialchars($deduction['deduction_name']) ?>
                                </div>
                                <div class="breakdown-value">- ₱<?= number_format($deduction['amount'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="breakdown-row total-deductions">
                        <div class="breakdown-label">
                            <i class="fas fa-calculator"></i>
                            Total Deductions
                        </div>
                        <div class="breakdown-value">- ₱<?= number_format($employeeData['total_deductions'] ?? 0, 2) ?></div>
                    </div>
                    
                    <div class="breakdown-row net-pay">
                        <div class="breakdown-label">
                            <i class="fas fa-check-circle"></i>
                            Net Pay
                        </div>
                        <div class="breakdown-value">₱<?= number_format($employeeData['net_salary'] ?? 0, 2) ?></div>
                    </div>
                </div>
                
                <!-- Status Badge -->
                <div style="margin-top: 15px; text-align: right;">
                    <span class="status-badge <?= ($employeeData['status'] ?? 'pending') == 'paid' ? 'status-paid' : 'status-pending' ?>">
                        <i class="fas <?= ($employeeData['status'] ?? 'pending') == 'paid' ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                        Status: <?= ucfirst($employeeData['status'] ?? 'pending') ?>
                    </span>
                </div>
            </div>

            <div class="modal-buttons">
                <button class="save-image-btn" onclick="saveSalarySlipAsImage()">
                    <i class="fas fa-camera"></i> Save as Image
                </button>
                <button class="close-btn-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>Saving salary slip...</p>
    </div>
    
    <!-- Download Success Message -->
    <div class="download-success" id="downloadSuccess">
        <i class="fas fa-check-circle"></i> Salary slip saved successfully!
    </div>
    <?php endif; ?>

    <!-- Delete Payroll Confirmation Modal -->
    <div id="deletePayrollModal" class="modal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
        <div class="modal-content" style="background: white; border-radius: 16px; width: 90%; max-width: 500px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);">
            <div class="modal-header" style="padding: 20px 25px; background: linear-gradient(135deg, #dc3545, #c82333); color: white; border-radius: 16px 16px 0 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
                <h3 style="margin: 0; font-size: 18px; font-weight: 600; flex: 1;">Delete Payroll Record</h3>
                <button class="close-btn" onclick="closeModalById('deletePayrollModal')" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer;"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" style="padding: 25px;">
                <div class="delete-icon" style="font-size: 48px; color: #dc3545; text-align: center; margin-bottom: 15px;">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <div class="delete-message" style="text-align: center; margin-bottom: 20px; font-size: 16px; color: #333;">
                    Are you sure you want to delete this payroll record?
                </div>
                <div class="delete-details" id="deletePayrollDetails" style="background: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; font-size: 14px;">
                    <!-- Payroll details will be displayed here -->
                </div>
                <div class="delete-warning" style="color: #dc3545; font-size: 13px; font-style: italic; margin-top: 10px; text-align: center;">
                    <i class="fas fa-info-circle"></i> This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer" style="padding: 15px 25px; background-color: #f8fafc; border-top: 1px solid #e9ecef; border-radius: 0 0 16px 16px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="modal-btn cancel" onclick="closeModalById('deletePayrollModal')" style="padding: 10px 24px; border: none; border-radius: 30px; font-size: 14px; font-weight: 600; cursor: pointer; background: #e2e8f0; color: #334155;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="modal-btn delete" onclick="confirmDeletePayroll()" style="padding: 10px 24px; border: none; border-radius: 30px; font-size: 14px; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #dc3545, #c82333); color: white;">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Report Modal - Modified with Site Dropdown -->
    <div id="reportModal" class="report-modal">
        <div class="report-modal-content">
            <div class="report-modal-header">
                <h3><i class="fas fa-chart-bar"></i> Generate Salary Report</h3>
                <button onclick="closeReportModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="report-modal-body" id="reportModalBody">
                <!-- Date Range Controls with Attendance.php style date pickers -->
                <div class="report-controls active">
                    <!-- Date From - with calendar picker -->
                    <div class="report-select-group">
                        <label for="report_date_from"><i class="fas fa-calendar-alt" style="color: #75e6da;"></i> Date From</label>
                        <div class="date-picker-wrapper">
                            <div class="date-input-group">
                                <input type="text" 
                                       id="reportDateFromField" 
                                       class="date-field" 
                                       value="<?php echo date('m/d/Y', strtotime(date('Y-m-01'))); ?>"
                                       placeholder="MM/DD/YYYY"
                                       autocomplete="off"
                                       readonly
                                       onclick="toggleCalendar('from')">
                                <input type="hidden" id="report_date_from" name="report_date_from" value="<?php echo date('Y-m-01'); ?>">
                                <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('from')">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <!-- Calendar for Date From -->
                            <div class="modal-calendar-wrapper" id="calendarFrom">
                                <div class="modal-calendar-box">
                                    <div class="modal-calendar-header">
                                        <div class="modal-calendar-month-year" id="calendarFromMonthYear"></div>
                                        <div class="modal-calendar-nav">
                                            <button type="button" class="modal-calendar-nav-btn" onclick="navigateCalendar('from', -1)">‹</button>
                                            <button type="button" class="modal-calendar-nav-btn" onclick="navigateCalendar('from', 1)">›</button>
                                        </div>
                                    </div>
                                    
                                    <div class="modal-calendar-selectors">
                                        <select id="calendarFromMonthSelect" class="modal-calendar-select" onchange="changeCalendarMonthYear('from')">
                                            <option value="0">January</option>
                                            <option value="1">February</option>
                                            <option value="2">March</option>
                                            <option value="3">April</option>
                                            <option value="4">May</option>
                                            <option value="5">June</option>
                                            <option value="6">July</option>
                                            <option value="7">August</option>
                                            <option value="8">September</option>
                                            <option value="9">October</option>
                                            <option value="10">November</option>
                                            <option value="11">December</option>
                                        </select>
                                        
                                        <select id="calendarFromYearSelect" class="modal-calendar-select" onchange="changeCalendarMonthYear('from')">
                                            <?php for($y = date('Y') - 10; $y <= date('Y') + 10; $y++): ?>
                                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="modal-calendar-weekdays">
                                        <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                                    </div>
                                    
                                    <div class="modal-calendar-days-grid" id="calendarFromDaysGrid"></div>
                                    
                                    <div class="modal-calendar-footer">
                                        <button type="button" class="modal-calendar-action-btn clear" onclick="clearCalendarDate('from')">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <button type="button" class="modal-calendar-action-btn today" onclick="setCalendarToday('from')">
                                            <i class="fas fa-calendar-check"></i> Today
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Date To - with calendar picker -->
                    <div class="report-select-group">
                        <label for="report_date_to"><i class="fas fa-calendar-alt" style="color: #75e6da;"></i> Date To</label>
                        <div class="date-picker-wrapper">
                            <div class="date-input-group">
                                <input type="text" 
                                       id="reportDateToField" 
                                       class="date-field" 
                                       value="<?php echo date('m/d/Y', strtotime(date('Y-m-t'))); ?>"
                                       placeholder="MM/DD/YYYY"
                                       autocomplete="off"
                                       readonly
                                       onclick="toggleCalendar('to')">
                                <input type="hidden" id="report_date_to" name="report_date_to" value="<?php echo date('Y-m-t'); ?>">
                                <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('to')">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <!-- Calendar for Date To -->
                            <div class="modal-calendar-wrapper" id="calendarTo">
                                <div class="modal-calendar-box">
                                    <div class="modal-calendar-header">
                                        <div class="modal-calendar-month-year" id="calendarToMonthYear"></div>
                                        <div class="modal-calendar-nav">
                                            <button type="button" class="modal-calendar-nav-btn" onclick="navigateCalendar('to', -1)">‹</button>
                                            <button type="button" class="modal-calendar-nav-btn" onclick="navigateCalendar('to', 1)">›</button>
                                        </div>
                                    </div>
                                    
                                    <div class="modal-calendar-selectors">
                                        <select id="calendarToMonthSelect" class="modal-calendar-select" onchange="changeCalendarMonthYear('to')">
                                            <option value="0">January</option>
                                            <option value="1">February</option>
                                            <option value="2">March</option>
                                            <option value="3">April</option>
                                            <option value="4">May</option>
                                            <option value="5">June</option>
                                            <option value="6">July</option>
                                            <option value="7">August</option>
                                            <option value="8">September</option>
                                            <option value="9">October</option>
                                            <option value="10">November</option>
                                            <option value="11">December</option>
                                        </select>
                                        
                                        <select id="calendarToYearSelect" class="modal-calendar-select" onchange="changeCalendarMonthYear('to')">
                                            <?php for($y = date('Y') - 10; $y <= date('Y') + 10; $y++): ?>
                                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="modal-calendar-weekdays">
                                        <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                                    </div>
                                    
                                    <div class="modal-calendar-days-grid" id="calendarToDaysGrid"></div>
                                    
                                    <div class="modal-calendar-footer">
                                        <button type="button" class="modal-calendar-action-btn clear" onclick="clearCalendarDate('to')">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <button type="button" class="modal-calendar-action-btn today" onclick="setCalendarToday('to')">
                                            <i class="fas fa-calendar-check"></i> Today
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Employee Dropdown -->
                    <div class="report-select-group">
                        <label for="report_employee"><i class="fas fa-user" style="color: #75e6da;"></i> Employee</label>
                        <select id="report_employee" class="report-select">
                            <option value="all">All Employees</option>
                            <?php if ($employees_result && $employees_result->num_rows > 0): 
                                $employees_result->data_seek(0);
                                while ($emp = $employees_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <!-- NEW: Site Dropdown -->
                    <div class="report-select-group">
                        <label for="report_site"><i class="fas fa-map-marker-alt" style="color: #75e6da;"></i> Site</label>
                        <select id="report_site" class="report-select">
                            <option value="all">All Sites</option>
                            <?php if ($sites_result && $sites_result->num_rows > 0): 
                                $sites_result->data_seek(0);
                                while ($site = $sites_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $site['id']; ?>">
                                    <?php echo htmlspecialchars($site['site_name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <button class="generate-report-submit-btn" onclick="generateReportData()">
                        <i class="fas fa-chart-bar"></i> Generate
                    </button>
                </div>
                
                <!-- Report Content - This area will show the report results immediately -->
                <div id="reportContent" style="display: none;"></div>
                <div class="report-loading" id="reportLoading" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Generating report...</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once("./modal/logout-modal.php"); ?>

    <script>
    // Global variables
    let currentDeletePayrollId = null;
    
    // Calendar variables for report modal
    let activeCalendar = null;
    let calendarFromDate = new Date('<?php echo date('Y-m-01'); ?>');
    let calendarToDate = new Date('<?php echo date('Y-m-t'); ?>');
    let calendarFromSelected = '<?php echo date('Y-m-01'); ?>';
    let calendarToSelected = '<?php echo date('Y-m-t'); ?>';

    // ============================================
    // Save as Image Function - Captures entire modal except buttons
    // ============================================
    window.saveSalarySlipAsImage = function() {
        console.log('Save as image button clicked');
        
        // Find the content container
        const contentContainer = document.querySelector('.salary-modal-content');
        
        if (!contentContainer) {
            console.error('Salary slip content container not found');
            showToast('Salary slip content not found', 'error');
            return;
        }

        // Create a unique ID for the capture container
        const captureId = 'salary-slip-capture-' + Date.now();
        
        // Create a clone of the content to modify
        const cloneContainer = contentContainer.cloneNode(true);
        
        // Remove the modal-buttons section from the clone
        const buttonsSection = cloneContainer.querySelector('.modal-buttons');
        if (buttonsSection) {
            buttonsSection.remove();
        }

        // Ensure the status badge is visible and properly styled
        const statusBadge = cloneContainer.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.style.display = 'inline-block';
        }

        // Style the clone for clean capture - ensure full height
        cloneContainer.id = captureId;
        cloneContainer.style.position = 'absolute';
        cloneContainer.style.left = '-9999px';
        cloneContainer.style.top = '0';
        cloneContainer.style.width = contentContainer.offsetWidth + 'px';
        cloneContainer.style.backgroundColor = '#ffffff';
        cloneContainer.style.borderRadius = '16px';
        cloneContainer.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.2)';
        cloneContainer.style.margin = '0';
        cloneContainer.style.padding = '0';
        cloneContainer.style.overflow = 'visible';
        cloneContainer.style.height = 'auto';
        cloneContainer.style.maxHeight = 'none';
        
        // Ensure all content is visible
        const allElements = cloneContainer.querySelectorAll('*');
        allElements.forEach(el => {
            el.style.overflow = 'visible';
            el.style.maxHeight = 'none';
            if (el.classList.contains('payroll-summary-container') || 
                el.classList.contains('salary-breakdown')) {
                el.style.height = 'auto';
                el.style.maxHeight = 'none';
            }
        });
        
        // Append clone to body
        document.body.appendChild(cloneContainer);

        // Show loading overlay
        const loadingOverlay = document.getElementById('loadingOverlay');
        if (loadingOverlay) {
            loadingOverlay.style.display = 'flex';
            const loadingText = loadingOverlay.querySelector('p');
            if (loadingText) loadingText.textContent = 'Generating image...';
        }

        // Get employee name for filename
        let employeeName = 'Employee';
        const nameElement = cloneContainer.querySelector('.employee-info-modal div:nth-child(2) span') || 
                           cloneContainer.querySelector('.employee-info-modal div:nth-child(2)');
        if (nameElement) {
            employeeName = nameElement.textContent.trim().replace(/\s+/g, '_');
        }
        
        // Get status for filename
        let status = 'pending';
        const statusElement = cloneContainer.querySelector('.status-badge');
        if (statusElement) {
            status = statusElement.textContent.trim().toLowerCase().replace(/\s+/g, '_');
        }
        
        const filename = `Salary_Slip_${employeeName}_${status}_${new Date().toISOString().slice(0,10)}.jpg`;

        // Calculate actual height of the clone
        const cloneHeight = cloneContainer.scrollHeight;
        
        // Capture the clone as an image
        html2canvas(cloneContainer, {
            scale: 2,
            backgroundColor: '#ffffff',
            logging: false,
            allowTaint: true,
            useCORS: true,
            windowWidth: cloneContainer.scrollWidth,
            windowHeight: cloneHeight,
            height: cloneHeight,
            onclone: function(clonedDoc, element) {
                const clonedElement = element;
                clonedElement.style.height = cloneHeight + 'px';
                clonedElement.style.overflow = 'visible';
            }
        }).then(canvas => {
            console.log('Canvas generated successfully');
            
            // Convert canvas to JPEG and download
            const link = document.createElement('a');
            link.download = filename;
            link.href = canvas.toDataURL('image/jpeg', 1.0);
            link.click();

            // Remove the clone
            if (document.body.contains(cloneContainer)) {
                document.body.removeChild(cloneContainer);
            }

            // Hide loading overlay
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }

            // Show success toast
            showToast('Salary slip saved as image successfully!', 'success');
        }).catch(error => {
            console.error('Error capturing image:', error);
            
            // Remove the clone if there's an error
            if (document.body.contains(cloneContainer)) {
                document.body.removeChild(cloneContainer);
            }
            
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
            showToast('Error saving image: ' + error.message, 'error');
        });
    };

    // Automatically generate report when modal opens
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($employeeData): ?>
            document.getElementById('salary-modal').style.display = 'flex';
            document.body.classList.add('modal-open');
        <?php endif; ?>
        
        // Check for toast messages
        const toastMessage = localStorage.getItem('toastMessage');
        const toastType = localStorage.getItem('toastType');
        
        if (toastMessage) {
            showToast(toastMessage, toastType || 'success');
            localStorage.removeItem('toastMessage');
            localStorage.removeItem('toastType');
        }

        // Main page salary search functionality
        const mainSearchInput = document.getElementById('mainSearchInput');
        if (mainSearchInput) {
            mainSearchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                if (query.length >= 2) {
                    performSalarySearch();
                } else {
                    document.getElementById('mainSearchResults').classList.remove('show');
                    // Show all salary rows
                    document.querySelectorAll('.salary-row').forEach(row => {
                        row.style.display = '';
                    });
                }
            });
            
            // Close results when clicking outside
            document.addEventListener('click', function(e) {
                const container = document.querySelector('.main-search-container');
                const results = document.getElementById('mainSearchResults');
                if (container && !container.contains(e.target) && results) {
                    results.classList.remove('show');
                }
            });
        }
        
        // Initialize calendars
        generateCalendarDays('from');
        generateCalendarDays('to');
    });

    // Toast notification function
    function showToast(message, type = 'success', duration = 5000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let icon = '';
        switch(type) {
            case 'success':
                icon = '<i class="fas fa-check-circle"></i>';
                break;
            case 'error':
                icon = '<i class="fas fa-exclamation-circle"></i>';
                break;
            case 'warning':
                icon = '<i class="fas fa-exclamation-triangle"></i>';
                break;
            default:
                icon = '<i class="fas fa-info-circle"></i>';
        }
        
        toast.innerHTML = `
            ${icon}
            <div class="toast-content">${message}</div>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }, duration);
    }

    // Close the salary slip modal
    function closeModal() {
        document.getElementById('salary-modal').style.display = 'none';
        document.body.classList.remove('modal-open');
        window.history.pushState({}, document.title, window.location.pathname);
    }

    // Close modal by ID
    function closeModalById(modalId) {
        document.getElementById(modalId).style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    // Perform main page salary search
    function performSalarySearch() {
        const searchInput = document.getElementById('mainSearchInput');
        const resultsDiv = document.getElementById('mainSearchResults');
        const query = searchInput.value.trim().toLowerCase();
        
        if (query.length < 2) {
            resultsDiv.classList.remove('show');
            // Show all salary rows
            document.querySelectorAll('.salary-row').forEach(row => {
                row.style.display = '';
            });
            return;
        }
        
        // Filter table rows
        const rows = document.querySelectorAll('.salary-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const position = row.getAttribute('data-position') || '';
            const dateFrom = row.getAttribute('data-date-from') || '';
            const dateTo = row.getAttribute('data-date-to') || '';
            const rowText = `${name} ${position} ${dateFrom} ${dateTo}`.toLowerCase();
            
            if (rowText.includes(query)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show search results in dropdown
        if (visibleCount > 0) {
            resultsDiv.innerHTML = `<div style="padding: 15px; text-align: center; color: #666;"><i class="fas fa-check-circle" style="color: #00838f;"></i> Found ${visibleCount} matching salary records</div>`;
        } else {
            resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #999;">No matching salary records found</div>';
        }
        resultsDiv.classList.add('show');
    }
// Enhanced View Salary Slip function with full breakdown (NO SITE BREAKDOWN)
// Enhanced View Salary Slip function - ONLY Salary Computation (No Attendance, No Deductions)
async function viewSalarySlip(payrollId) {
    showLoading();
    
    try {
        const timestamp = Date.now();
        const response = await fetch(`salarySlip.php?get_payroll=${payrollId}&t=${timestamp}`, {
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            const p = data.data;
            
            // Format dates
            const dateFrom = new Date(p.date_from).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            const dateTo = new Date(p.date_to).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            
            // Get employment type display
            const employmentType = p.employment_type || 'regular';
            const employmentTypeClass = employmentType === 'regular' ? 'regular' : 'non-regular';
            const employmentTypeLabel = employmentType === 'regular' ? 'Regular' : 'Non Regular';
            
            const hourlyRate = parseFloat(p.daily_salary) / 8;
            
            // Calculate net pay (base salary - total deductions)
            const netPayAmount = parseFloat(p.net_pay || 0);
            
            const modalContent = `
                <div class="salary-modal-content" id="salary-slip-content-dynamic">
                    <div class="salary-header">
                        <h2><i class="fas fa-file-invoice-dollar"></i> SALARY SLIP</h2>
                        <p>Pay Period: ${dateFrom} - ${dateTo}</p>
                    </div>
                    
                    <div class="employee-info-modal">
                        <div><strong><i class="fas fa-id-card"></i> Employee ID:</strong> <span>${p.employee_id}</span></div>
                        <div><strong><i class="fas fa-user"></i> Employee Name:</strong> <span>${escapeHtml(p.first_name)} ${escapeHtml(p.last_name)}</span></div>
                        <div><strong><i class="fas fa-briefcase"></i> Position:</strong> <span>${escapeHtml(p.position)}</span></div>
                        <div><strong><i class="fas fa-calculator"></i> Rate:</strong> <span>Daily: ₱${parseFloat(p.daily_salary).toFixed(2)}/day</span></div>
                        <div><strong><i class="fas fa-tag"></i> Employment Type:</strong> <span class="employment-type-badge ${employmentTypeClass}">${employmentTypeLabel}</span></div>
                    </div>

                    <div class="payroll-summary-container" style="margin: 20px;">
                        <div class="summary-header">
                            <h4><i class="fas fa-coins"></i> Salary Computation</h4>
                        </div>
                        
                        <div class="salary-breakdown">
                            <div class="breakdown-header"><i class="fas fa-calculator"></i> Hours & Pay Breakdown</div>
                            
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-clock"></i>
                                    Total Work Hours
                                    <span class="badge">${parseFloat(p.total_work_hours || 0).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value">-</div>
                            </div>
                            
                            <!-- Regular Day Hours -->
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-sun"></i>
                                    Regular Day Hours
                                    <span class="badge">${parseFloat(p.regular_hours || 0).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value">₱${parseFloat(p.regular_pay || 0).toFixed(2)}</div>
                            </div>
                            
                            <!-- Day Overtime -->
                            ${p.overtime_hours && p.overtime_hours > 0 ? `
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-clock"></i>
                                    Day Overtime Hours
                                    <span class="badge">${parseFloat(p.overtime_hours).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value highlight">₱${parseFloat(p.overtime_pay).toFixed(2)}</div>
                            </div>` : ''}
                            
                            <!-- Regular Night Hours -->
                            ${p.regular_night_hours && p.regular_night_hours > 0 ? `
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-moon"></i>
                                    Regular Night Hours
                                    <span class="badge">${parseFloat(p.regular_night_hours).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value highlight">₱${parseFloat(p.regular_night_pay).toFixed(2)}</div>
                            </div>` : ''}
                            
                            <!-- Night Overtime -->
                            ${p.night_shift_overtime_hours && p.night_shift_overtime_hours > 0 ? `
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-moon"></i>
                                    Night Overtime Hours
                                    <span class="badge">${parseFloat(p.night_shift_overtime_hours).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value highlight">₱${parseFloat(p.night_shift_overtime_pay).toFixed(2)}</div>
                            </div>` : ''}
                            
                            <!-- Holiday Guaranteed Pay -->
                            ${p.holiday_guaranteed_pay && p.holiday_guaranteed_pay > 0 ? `
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-gift"></i>
                                    Holiday Guaranteed Pay
                                </div>
                                <div class="breakdown-value">₱${parseFloat(p.holiday_guaranteed_pay).toFixed(2)}</div>
                            </div>` : ''}
                            
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-money-bill"></i>
                                    Base Salary
                                </div>
                                <div class="breakdown-value">₱${parseFloat(p.base_salary).toFixed(2)}</div>
                            </div>
                        </div>
                        
                        <!-- NET PAY ONLY - removed Total Deductions row -->
                        <div class="salary-breakdown" style="margin-top: 20px;">
                            <div class="breakdown-row net-pay">
                                <div class="breakdown-label">
                                    <i class="fas fa-check-circle"></i>
                                    Net Pay
                                </div>
                                <div class="breakdown-value">₱${netPayAmount.toFixed(2)}</div>
                            </div>
                        </div>
                        
                        <div class="hourly-rate-info">
                            <span><i class="fas fa-tag"></i> <strong>Hourly Rate:</strong> ₱${hourlyRate.toFixed(2)}</span>
                            <span class="rate-value"><i class="fas fa-calendar"></i> Daily Rate: ₱${parseFloat(p.daily_salary).toFixed(2)}</span>
                        </div>
                        
                        <div style="margin-top: 15px; text-align: right;">
                            <span class="status-badge ${p.status === 'paid' ? 'status-paid' : 'status-pending'}">
                                <i class="fas ${p.status === 'paid' ? 'fa-check-circle' : 'fa-clock'}"></i>
                                Status: ${p.status.charAt(0).toUpperCase() + p.status.slice(1)}
                            </span>
                        </div>
                    </div>

                    <div class="modal-buttons">
                        <button class="save-image-btn" onclick="window.saveSalarySlipAsImage()">
                            <i class="fas fa-camera"></i> Save as Image
                        </button>
                        <button class="close-btn-modal" onclick="closeModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            // Create or update modal
            let modal = document.getElementById('salary-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'salary-modal';
                modal.className = 'salary-modal';
                document.body.appendChild(modal);
            }
            
            modal.innerHTML = modalContent;
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
            
            hideLoading();
        } else {
            showToast('Error loading salary slip', 'error');
            hideLoading();
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Error: ' + error.message, 'error');
        hideLoading();
    }
}
    // Open delete payroll modal
    function openDeletePayrollModal(payrollId, employeeName, dateFrom, dateTo, netPay) {
        currentDeletePayrollId = payrollId;
        
        document.getElementById('deletePayrollDetails').innerHTML = `
            <p><strong>Employee:</strong> ${employeeName}</p>
            <p><strong>Period:</strong> ${dateFrom} to ${dateTo}</p>
            <p><strong>Net Pay:</strong> ${netPay}</p>
        `;
        
        document.getElementById('deletePayrollModal').style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    // Confirm delete payroll
    function confirmDeletePayroll() {
        if (!currentDeletePayrollId) return;
        window.location.href = 'payrollList.php?delete_id=' + currentDeletePayrollId;
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const salaryModal = document.getElementById('salary-modal');
        const reportModal = document.getElementById('reportModal');
        const deleteModal = document.getElementById('deletePayrollModal');
        
        if (event.target === salaryModal) {
            closeModal();
        }
        if (event.target === reportModal) {
            closeReportModal();
        }
        if (event.target === deleteModal) {
            closeModalById('deletePayrollModal');
        }
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeReportModal();
            closeModalById('deletePayrollModal');
            
            // Close any open calendars
            document.querySelectorAll('.modal-calendar-wrapper').forEach(cal => {
                cal.style.display = 'none';
            });
            activeCalendar = null;
        }
    });

    // ============================================
    // CALENDAR FUNCTIONS (from attendance.php)
    // ============================================
    
    function toggleCalendar(calendarId) {
        const calendar = document.getElementById('calendar' + calendarId.charAt(0).toUpperCase() + calendarId.slice(1));
        if (calendar) {
            if (calendar.style.display === 'block') {
                calendar.style.display = 'none';
                activeCalendar = null;
            } else {
                // Hide any other open calendars
                document.querySelectorAll('.modal-calendar-wrapper').forEach(cal => {
                    cal.style.display = 'none';
                });
                calendar.style.display = 'block';
                activeCalendar = 'calendar' + calendarId.charAt(0).toUpperCase() + calendarId.slice(1);
                generateCalendarDays(calendarId);
            }
        }
    }
    
    function generateCalendarDays(calendarId) {
        let date, month, year, daysGrid, monthYearDisplay, monthSelect, yearSelect;
        
        if (calendarId === 'from') {
            date = calendarFromDate;
            month = date.getMonth();
            year = date.getFullYear();
            daysGrid = document.getElementById('calendarFromDaysGrid');
            monthYearDisplay = document.getElementById('calendarFromMonthYear');
            monthSelect = document.getElementById('calendarFromMonthSelect');
            yearSelect = document.getElementById('calendarFromYearSelect');
        } else {
            date = calendarToDate;
            month = date.getMonth();
            year = date.getFullYear();
            daysGrid = document.getElementById('calendarToDaysGrid');
            monthYearDisplay = document.getElementById('calendarToMonthYear');
            monthSelect = document.getElementById('calendarToMonthSelect');
            yearSelect = document.getElementById('calendarToYearSelect');
        }
        
        if (!daysGrid) return;
        
        monthYearDisplay.textContent = date.toLocaleDateString('en-US', { 
            month: 'long', 
            year: 'numeric' 
        });
        
        if (monthSelect) monthSelect.value = month;
        if (yearSelect) yearSelect.value = year;
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        
        let html = '';
        
        // Previous month days
        const prevMonthDays = new Date(year, month, 0).getDate();
        for (let i = firstDay - 1; i >= 0; i--) {
            const day = prevMonthDays - i;
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            html += `<div class="modal-calendar-day other-month" onclick="selectCalendarDate('${calendarId}', '${dateStr}')">${day}</div>`;
        }
        
        // Current month days
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
            const isSelected = (calendarId === 'from' && dateStr === calendarFromSelected) || 
                              (calendarId === 'to' && dateStr === calendarToSelected);
            const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
            
            let classes = 'modal-calendar-day';
            if (isToday) classes += ' today';
            if (isSelected) classes += ' selected';
            if (isWeekend) classes += ' weekend';
            
            html += `<div class="${classes}" onclick="selectCalendarDate('${calendarId}', '${dateStr}')">${day}</div>`;
        }
        
        // Next month days
        const totalCells = 42;
        const cellsUsed = firstDay + daysInMonth;
        const nextMonthDays = totalCells - cellsUsed;
        for (let day = 1; day <= nextMonthDays; day++) {
            const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            html += `<div class="modal-calendar-day other-month" onclick="selectCalendarDate('${calendarId}', '${dateStr}')">${day}</div>`;
        }
        
        daysGrid.innerHTML = html;
    }
    
    function navigateCalendar(calendarId, direction) {
        if (calendarId === 'from') {
            calendarFromDate.setMonth(calendarFromDate.getMonth() + direction);
            generateCalendarDays('from');
        } else {
            calendarToDate.setMonth(calendarToDate.getMonth() + direction);
            generateCalendarDays('to');
        }
    }
    
    function changeCalendarMonthYear(calendarId) {
        let monthSelect, yearSelect;
        
        if (calendarId === 'from') {
            monthSelect = document.getElementById('calendarFromMonthSelect');
            yearSelect = document.getElementById('calendarFromYearSelect');
        } else {
            monthSelect = document.getElementById('calendarToMonthSelect');
            yearSelect = document.getElementById('calendarToYearSelect');
        }
        
        if (monthSelect && yearSelect) {
            const newMonth = parseInt(monthSelect.value);
            const newYear = parseInt(yearSelect.value);
            
            if (calendarId === 'from') {
                calendarFromDate = new Date(newYear, newMonth, 1);
            } else {
                calendarToDate = new Date(newYear, newMonth, 1);
            }
            
            generateCalendarDays(calendarId);
        }
    }
    
    function selectCalendarDate(calendarId, dateStr) {
        const date = new Date(dateStr);
        const formattedDisplay = date.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
        
        if (calendarId === 'from') {
            document.getElementById('reportDateFromField').value = formattedDisplay;
            document.getElementById('report_date_from').value = dateStr;
            calendarFromSelected = dateStr;
            calendarFromDate = new Date(dateStr);
        } else {
            document.getElementById('reportDateToField').value = formattedDisplay;
            document.getElementById('report_date_to').value = dateStr;
            calendarToSelected = dateStr;
            calendarToDate = new Date(dateStr);
        }
        
        document.getElementById('calendar' + calendarId.charAt(0).toUpperCase() + calendarId.slice(1)).style.display = 'none';
        activeCalendar = null;
        
        generateCalendarDays(calendarId);
    }
    
    function clearCalendarDate(calendarId) {
        if (calendarId === 'from') {
            document.getElementById('reportDateFromField').value = '';
            document.getElementById('report_date_from').value = '';
            calendarFromSelected = '';
        } else {
            document.getElementById('reportDateToField').value = '';
            document.getElementById('report_date_to').value = '';
            calendarToSelected = '';
        }
        
        document.getElementById('calendar' + calendarId.charAt(0).toUpperCase() + calendarId.slice(1)).style.display = 'none';
        activeCalendar = null;
    }
    
    function setCalendarToday(calendarId) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;
        
        if (calendarId === 'from') {
            calendarFromDate = new Date(dateStr);
        } else {
            calendarToDate = new Date(dateStr);
        }
        
        selectCalendarDate(calendarId, dateStr);
    }

    // Report Modal Functions
    function showReportModal() {
        document.getElementById('reportModal').style.display = 'flex';
        document.body.classList.add('modal-open');
        
        // Reset to show controls, hide report content
        document.getElementById('reportContent').style.display = 'none';
        document.getElementById('reportLoading').style.display = 'none';
        
        // Refresh calendars
        generateCalendarDays('from');
        generateCalendarDays('to');
        
        // Automatically generate report with current date range
        generateReportData();
    }

    function closeReportModal() {
        document.getElementById('reportModal').style.display = 'none';
        document.body.classList.remove('modal-open');
        
        // Close any open calendars
        document.querySelectorAll('.modal-calendar-wrapper').forEach(cal => {
            cal.style.display = 'none';
        });
        activeCalendar = null;
    }

    function generateReportData() {
        const dateFrom = document.getElementById('report_date_from').value;
        const dateTo = document.getElementById('report_date_to').value;
        const employee_id = document.getElementById('report_employee').value;
        const site_id = document.getElementById('report_site').value;
        
        if (!dateFrom || !dateTo) {
            showToast('Please select both dates', 'error');
            return;
        }
        
        // Show loading, hide content
        document.getElementById('reportLoading').style.display = 'block';
        document.getElementById('reportContent').style.display = 'none';
        
        // Close any open calendars
        document.querySelectorAll('.modal-calendar-wrapper').forEach(cal => {
            cal.style.display = 'none';
        });
        activeCalendar = null;
        
        // Make AJAX request to get report data
        fetch('salarySlip.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax=generate_report&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&employee_id=${encodeURIComponent(employee_id)}&site_id=${encodeURIComponent(site_id)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReport(data);
            } else {
                showToast('Error generating report', 'error');
                closeReportModal();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error generating report', 'error');
            closeReportModal();
        });
    }
function displayReport(data) {
    const reportContent = document.getElementById('reportContent');
    const reportLoading = document.getElementById('reportLoading');
    
    let employeeSummaryHtml = '';
    if (data.employee_summary && Object.keys(data.employee_summary).length > 0) {
        employeeSummaryHtml = '<div class="employee-summary-section"><div class="employee-summary-title"><i class="fas fa-users"></i> Employee Summary</div><div class="employee-summary-grid">';
        for (const [empName, stats] of Object.entries(data.employee_summary)) {
            employeeSummaryHtml += `
                <div class="employee-summary-card">
                    <div class="employee-summary-name">${escapeHtml(empName)}</div>
                    <div class="employee-summary-stats">
                        <span>Records: ${stats.count}</span>
                        <span>Net: ₱${formatNumber(stats.net)}</span>
                    </div>
                    <div style="font-size: 11px; margin-top: 5px; color: #666;">
                        Gross: ₱${formatNumber(stats.gross)} | Ded: ₱${formatNumber(stats.deductions)}
                    </div>
                </div>
            `;
        }
        employeeSummaryHtml += '</div></div>';
    }
    
    // Display selected site filter info
    const siteFilterInfo = data.selected_site_name !== 'All Sites' ? `<div style="margin-bottom: 15px; padding: 8px 15px; background: #e6f7f5; border-radius: 30px; display: inline-block;">
        <i class="fas fa-map-marker-alt" style="color: #00838f;"></i> Filtered by Site: <strong>${escapeHtml(data.selected_site_name)}</strong>
    </div>` : '';
    
    let html = `
        <div class="report-summary-cards">
            <div class="report-summary-card">
                <div class="label">Payroll Records</div>
                <div class="value">${data.totals.count}</div>
            </div>
            <div class="report-summary-card">
                <div class="label">Employees</div>
                <div class="value">${data.totals.employee_count}</div>
            </div>
            <div class="report-summary-card">
                <div class="label">Gross Salary</div>
                <div class="value">₱${formatNumber(data.totals.gross)}</div>
            </div>
            <div class="report-summary-card">
                <div class="label">Deductions</div>
                <div class="value">₱${formatNumber(data.totals.deductions)}</div>
            </div>
            <div class="report-summary-card">
                <div class="label">Net Payroll</div>
                <div class="value">₱${formatNumber(data.totals.net)}</div>
            </div>
        </div>
        
        ${siteFilterInfo}
        
        ${employeeSummaryHtml}
        
        <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
            <h4 style="color: #2c3e50; margin: 0; font-size: 14px;">
                <i class="fas fa-calendar-alt" style="color: #75e6da;"></i> Period: ${data.period_desc}
            </h4>
            <div>
                <button class="report-print-btn" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="report-download-btn" onclick="downloadReportExcel()">
                    <i class="fas fa-file-excel"></i> Download Excel
                </button>
            </div>
        </div>
        
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee Name</th>
                        <th>Position</th>
                        <th>Site</th>
                        <th class="text-right">Work Hrs</th>
                        <th class="text-right">Base Salary</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Pay</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    if (data.employees.length > 0) {
        data.employees.forEach(emp => {
            // Determine which site to display based on filter
            let displaySite = '';
            let displayHours = emp.work_hours || 0;
            let displayBaseSalary = emp.monthly_salary || 0;
            
            // If filtering by a specific site, show that site name
            if (data.selected_site_name !== 'All Sites') {
                displaySite = data.selected_site_name;
            } else {
                // If all sites, show the site breakdown or first site
                if (emp.site_breakdown && Object.keys(emp.site_breakdown).length > 0) {
                    const sites = Object.keys(emp.site_breakdown);
                    displaySite = sites.join(', ');
                } else {
                    displaySite = 'N/A';
                }
            }
            
            html += `
                <tr>
                    <td><strong>${String(emp.id).padStart(4, '0')}</strong></td>
                    <td>${escapeHtml(emp.employee_name)}</td>
                    <td>${escapeHtml(emp.position)}</td>
                    <td>${escapeHtml(displaySite)}</td>
                    <td class="text-right">${displayHours}</td>
                    <td class="text-right currency">₱${formatNumber(displayBaseSalary)}</td>
                    <td class="text-right currency" style="color: #dc3545;">₱${formatNumber(emp.total_deductions)}</td>
                    <td class="text-right currency" style="color: #00838f; font-weight: bold;">₱${formatNumber(emp.net_salary)}</td>
                    <td>
                        <span class="status-badge ${emp.status === 'paid' ? 'status-paid' : 'status-pending'}" style="font-size: 11px; padding: 3px 8px;">
                            ${emp.status ? emp.status.charAt(0).toUpperCase() + emp.status.slice(1) : 'Pending'}
                        </span>
                    </td>
                </tr>
            `;
        });
        
        // Totals row
        html += `
            <tr class="total-row">
                <td colspan="4" class="text-right"><strong>GRAND TOTALS:</strong></td>
                <td class="text-right"><strong>${data.totals.gross_hours || data.employees.reduce((sum, emp) => sum + (emp.work_hours || 0), 0)}</strong></td>
                <td class="text-right"><strong>₱${formatNumber(data.totals.gross)}</strong></td>
                <td class="text-right"><strong>₱${formatNumber(data.totals.deductions)}</strong></td>
                <td class="text-right"><strong>₱${formatNumber(data.totals.net)}</strong></td>
                <td></td>
            </tr>
        `;
    } else {
        html += `
            <tr>
                <td colspan="9" style="text-align: center; padding: 30px;">
                    <i class="fas fa-file-invoice" style="font-size: 36px; color: #ccc; margin-bottom: 10px;"></i>
                    <p style="color: #666;">No salary slip records found for the selected period and site filter.</p>
                </td>
            </tr>
        `;
    }
    
    html += `
                </tbody>
            </table>
        </div>
        
        <div class="report-footer">
            <div><strong>Generated on:</strong> ${new Date().toLocaleString()}</div>
            <div><strong>Generated by:</strong> <?= $_SESSION['Admin_User'] ?></div>
        </div>
    `;
    
    reportContent.innerHTML = html;
    reportLoading.style.display = 'none';
    reportContent.style.display = 'block';
    
    // Store data for Excel download
    window.reportData = data;
}

    function formatNumber(num) {
        return new Intl.NumberFormat('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(num);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function printReport() {
        window.print();
    }

    function downloadReportExcel() {
        if (!window.reportData) {
            showToast('No report data to download', 'error');
            return;
        }
        
        // Get the current date range, employee, and site selections from the form
        const dateFrom = document.getElementById('report_date_from').value;
        const dateTo = document.getElementById('report_date_to').value;
        const employeeId = document.getElementById('report_employee').value;
        const siteId = document.getElementById('report_site').value;
        
        // Create a form to submit to the separate PHP file
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'generate_salary_report.php';
        form.target = '_blank';
        
        // Add hidden inputs
        const dateFromInput = document.createElement('input');
        dateFromInput.type = 'hidden';
        dateFromInput.name = 'date_from';
        dateFromInput.value = dateFrom;
        form.appendChild(dateFromInput);
        
        const dateToInput = document.createElement('input');
        dateToInput.type = 'hidden';
        dateToInput.name = 'date_to';
        dateToInput.value = dateTo;
        form.appendChild(dateToInput);
        
        const employeeInput = document.createElement('input');
        employeeInput.type = 'hidden';
        employeeInput.name = 'employee_id';
        employeeInput.value = employeeId;
        form.appendChild(employeeInput);
        
        const siteInput = document.createElement('input');
        siteInput.type = 'hidden';
        siteInput.name = 'site_id';
        siteInput.value = siteId;
        form.appendChild(siteInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        showToast('Salary report is being generated and downloaded...', 'success');
    }

    // Loading functions
    function showLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
        }
    }

    function hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }
</script>

</body>
</html>