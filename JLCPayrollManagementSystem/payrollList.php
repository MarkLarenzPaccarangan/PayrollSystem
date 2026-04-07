<?php 
session_start();

// TURN ON ERROR DISPLAY FOR DEBUGGING - REMOVE AFTER FIXING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log all errors to a file
ini_set('log_errors', 1);
ini_set('error_log', './payroll_errors.log');

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// First, add employment_type column to employees table if it doesn't exist
$check_column = $conn->query("SHOW COLUMNS FROM employees LIKE 'employment_type'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE `employees` ADD `employment_type` VARCHAR(20) NOT NULL DEFAULT 'regular'");
}

$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$employee_filter = isset($_GET['employee_id']) ? $_GET['employee_id'] : '';
$site_filter = isset($_GET['site_id']) ? $_GET['site_id'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// FIXED: Define workday type multipliers with standardized keys matching attendance.php values
function getWorkdayMultipliers($workday_type) {
    // If workday_type is empty or null, default to Ordinary working day
    if (empty($workday_type)) {
        error_log("WORKDAY DEBUG: Empty workday_type, defaulting to Ordinary Working Day");
        $workday_type = 'Ordinary Working Day';
    }
    
    // Log the workday type being processed
    error_log("WORKDAY DEBUG: Processing workday_type: '" . $workday_type . "'");
    
    // Standardized multipliers with keys matching attendance.php dropdown values
    $multipliers = [
        'Ordinary Working Day' => [
            'basic' => 1.0,
            'overtime' => 1.25,
            'night' => 1.10,
            'paid_when_absent' => false
        ],
        'Rest Day / Sunday' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special (Non-Working) Day' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special Day that falls on Rest Day' => [
            'basic' => 1.5,
            'overtime' => 1.95,
            'night' => 1.65,
            'paid_when_absent' => false
        ],
        'Regular Holiday' => [
            'basic' => 2.0,
            'overtime' => 2.6,
            'night' => 2.2,
            'paid_when_absent' => true
        ],
        'Regular Holiday on the Rest Day' => [
            'basic' => 2.6,
            'overtime' => 3.38,
            'night' => 2.86,
            'paid_when_absent' => true
        ],
        'Double Holiday' => [
            'basic' => 3.0,
            'overtime' => 3.9,
            'night' => 3.3,
            'paid_when_absent' => true
        ],
        'Double Holiday on the Rest Day' => [
            'basic' => 3.9,
            'overtime' => 5.07,
            'night' => 4.29,
            'paid_when_absent' => true
        ]
    ];
    
    // Check if the workday type exists in our multipliers
    if (isset($multipliers[$workday_type])) {
        error_log("WORKDAY DEBUG: Found multipliers for: '" . $workday_type . "'");
        return $multipliers[$workday_type];
    } else {
        error_log("WORKDAY DEBUG: WARNING - No multipliers found for: '" . $workday_type . "', defaulting to Ordinary Working Day");
        return $multipliers['Ordinary Working Day'];
    }
}

function calculateHours($time_in, $time_out) {
    // Check if either time is empty
    if (empty($time_in) || empty($time_out)) {
        return 0;
    }
    
    // Special handling for midnight (12:00 AM / 00:00:00)
    $is_midnight_out = ($time_out == '00:00:00');
    $is_midnight_in = ($time_in == '00:00:00');
    
    // Convert to timestamps
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return 0;
    }
    
    if ($is_midnight_out) {
        // For midnight (12:00 AM) as time_out, calculate hours until midnight of the same day
        // Get the date part of time_in and add 1 day, then set to 00:00:00
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        
        // Calculate hours from time_in to midnight
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        
        // Log for debugging
        error_log("MIDNIGHT CALC: time_in=$time_in, time_out=$time_out, hours=$hours");
        
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

// Handle Delete Payroll - Modified to also delete associated records when employee is deleted
if (isset($_GET['delete_id'])) {
    $payrollId = $_GET['delete_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete from payroll_deductions first
        $stmt1 = $conn->prepare("DELETE FROM payroll_deductions WHERE payroll_id = ?");
        $stmt1->bind_param("i", $payrollId);
        $stmt1->execute();
        $stmt1->close();
        
        // Then delete payroll
        $stmt2 = $conn->prepare("DELETE FROM payroll WHERE id = ?");
        $stmt2->bind_param("i", $payrollId);
        $stmt2->execute();
        $stmt2->close();
        
        $conn->commit();
        
        echo "<script>alert('Payroll record deleted successfully'); window.location.href='payrollList.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete error: " . $e->getMessage());
        echo "<script>alert('Error deleting payroll record');</script>";
    }
    exit;
}

// Handle Generate Payroll via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll_ajax'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => '', 'payroll' => null];
    
    try {
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];
        $employee_id = $_POST['employee_id'];
        
        if (empty($employee_id)) {
            $response['message'] = "Please select an employee.";
            echo json_encode($response);
            exit;
        }
        
        // Check if payroll already exists for this employee and date range
        $check_payroll_query = "SELECT id FROM payroll WHERE employee_id = ? AND date_from = ? AND date_to = ?";
        $check_payroll_stmt = $conn->prepare($check_payroll_query);
        $check_payroll_stmt->bind_param("iss", $employee_id, $date_from, $date_to);
        $check_payroll_stmt->execute();
        $check_payroll_result = $check_payroll_stmt->get_result();
        
        if ($check_payroll_result->num_rows > 0) {
            $response['message'] = "Payroll already exists for this employee and date range.";
            $response['exists'] = true;
            echo json_encode($response);
            exit;
        }
        
        // Get employee daily salary and details including employment type
        $salary_query = "SELECT id, first_name, last_name, position, address, contact_num, daily_salary, employment_type FROM employees WHERE id = ?";
        $salary_stmt = $conn->prepare($salary_query);
        if (!$salary_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $salary_stmt->bind_param("i", $employee_id);
        $salary_stmt->execute();
        $salary_result = $salary_stmt->get_result();
        $employee = $salary_result->fetch_assoc();
        
        if (!$employee) {
            $response['message'] = "Employee not found";
            echo json_encode($response);
            exit;
        }
        
        // Calculate days between dates
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $total_days = 0;
        $total_work_hours = 0;
        $regular_hours = 0;
        $overtime_hours = 0;
        $regular_night_hours = 0;
        $night_shift_overtime_hours = 0;
        $regular_pay = 0;
        $overtime_pay = 0;
        $regular_night_pay = 0;
        $night_shift_overtime_pay = 0;
        $holiday_guaranteed_pay = 0;
        $days_present = 0;
        $days_on_leave = 0;
        $attendance_records = [];
        
        // Track attendance by workday type
        $workday_type_summary = [];
        
        // Get attendance records for the period including night shift and workday_type
        $attendance_query = "SELECT date, status, pm_status, night_status, leave_type, workday_type, 
                                    time_in_am, time_out_am, 
                                    time_in_pm, time_out_pm,
                                    time_in_night, time_out_night
                             FROM attendance 
                             WHERE employee_id = ? AND date BETWEEN ? AND ?";
        $attendance_stmt = $conn->prepare($attendance_query);
        $attendance_stmt->bind_param("iss", $employee_id, $date_from, $date_to);
        $attendance_stmt->execute();
        $attendance_result = $attendance_stmt->get_result();
        
        while ($row = $attendance_result->fetch_assoc()) {
            $attendance_records[$row['date']] = $row;
        }
        
        // Calculate hourly rate (daily salary / 8 hours)
        $hourly_rate = $employee['daily_salary'] / 8;
        
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $total_days++;
            
            if (isset($attendance_records[$date_str])) {
                $record = $attendance_records[$date_str];
                
                // FIXED: Get workday type from database and log it for debugging
                $workday_type = $record['workday_type'] ?? 'Ordinary Working Day';
                
                // Log the workday type from database for debugging
                error_log("DATE: $date_str - Raw workday_type from DB: '" . ($record['workday_type'] ?? 'NULL') . "'");
                
                // Get multipliers using the standardized function
                $multipliers = getWorkdayMultipliers($workday_type);
                
                // Initialize workday type summary if not exists
                if (!isset($workday_type_summary[$workday_type])) {
                    $workday_type_summary[$workday_type] = [
                        'count' => 0,
                        'present_count' => 0,
                        'absent_count' => 0,
                        'leave_count' => 0,
                        'total_hours' => 0
                    ];
                }
                
                // Calculate hours for each session independently
                $am_hours = 0;
                $pm_hours = 0;
                $night_hours = 0;
                $day_regular_hours = 0; // AM + PM only
                
                // Calculate AM hours if present
                if (!empty($record['time_in_am']) && !empty($record['time_out_am']) && 
                    $record['time_in_am'] != '00:00:00' && $record['time_out_am'] != '00:00:00') {
                    
                    $am_in = strtotime($record['time_in_am']);
                    $am_out = strtotime($record['time_out_am']);
                    if ($am_out < $am_in) $am_out += 86400;
                    $am_hours = ($am_out - $am_in) / 3600;
                }
                
             // Calculate PM hours if present - FIXED to include 12:00 AM (00:00:00)
if (!empty($record['time_in_pm']) && !empty($record['time_out_pm'])) {
    $pm_in = $record['time_in_pm'];
    $pm_out = $record['time_out_pm'];
    
    // Handle midnight (12:00 AM) specially
    if ($pm_out == '00:00:00') {
        // Convert to timestamps for calculation
        $pm_in_ts = strtotime($pm_in);
        $date_of_pm_in = date('Y-m-d', $pm_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_pm_in));
        $pm_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
        error_log("PM MIDNIGHT CALC: in=$pm_in, out=$pm_out, hours=$pm_hours");
    } else {
        $pm_in_ts = strtotime($pm_in);
        $pm_out_ts = strtotime($pm_out);
        if ($pm_out_ts < $pm_in_ts) $pm_out_ts += 86400;
        $pm_hours = ($pm_out_ts - $pm_in_ts) / 3600;
    }
    
    // Ensure positive hours
    $pm_hours = max(0, $pm_hours);
}
                
               // Calculate Night hours if present - FIXED to include 12:00 AM (00:00:00)
if (!empty($record['time_in_night']) && !empty($record['time_out_night'])) {
    $night_in = $record['time_in_night'];
    $night_out = $record['time_out_night'];
    
    // Handle midnight (12:00 AM) specially
    if ($night_out == '00:00:00') {
        $night_in_ts = strtotime($night_in);
        $date_of_night_in = date('Y-m-d', $night_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_night_in));
        $night_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        $night_hours = ($night_out_ts - $night_in_ts) / 3600;
        error_log("NIGHT MIDNIGHT CALC: in=$night_in, out=$night_out, hours=$night_hours");
    } else {
        $night_in_ts = strtotime($night_in);
        $night_out_ts = strtotime($night_out);
        if ($night_out_ts < $night_in_ts) $night_out_ts += 86400;
        $night_hours = ($night_out_ts - $night_in_ts) / 3600;
    }
    
    $night_hours = max(0, $night_hours);
}
                
                $day_regular_hours = $am_hours + $pm_hours; // Only AM+PM for regular pay
                
                // Check if employee has any attendance
                $has_any_attendance = ($am_hours > 0 || $pm_hours > 0 || $night_hours > 0);
                $is_absent = !$has_any_attendance && empty($record['leave_type']);
                $is_on_leave = !empty($record['leave_type']);
                
                // Update workday type summary
                $workday_type_summary[$workday_type]['count']++;
                if ($is_on_leave) {
                    $workday_type_summary[$workday_type]['leave_count']++;
                } else if ($has_any_attendance) {
                    $workday_type_summary[$workday_type]['present_count']++;
                    $workday_type_summary[$workday_type]['total_hours'] += $day_regular_hours + $night_hours;
                } else {
                    $workday_type_summary[$workday_type]['absent_count']++;
                }
                
                // FIXED: Check if this is a paid holiday for regular employee FIRST
                $is_paid_holiday_for_regular = ($employee['employment_type'] === 'regular' && $multipliers['paid_when_absent']);
                
                // Handle different statuses with correct logic for regular employees on paid holidays
                if ($is_on_leave) {
                    // On leave - still get daily salary (8 hours)
                    $days_on_leave++;
                    $regular_hours += 8;
                    $regular_pay += $employee['daily_salary'];
                    error_log("  -> ON LEAVE: Added daily salary of " . $employee['daily_salary']);
                } 
                else if ($has_any_attendance) {
                    // Employee is present
                    $days_present++;
                    error_log("  -> PRESENT: Workday type = " . $workday_type . ", Employee type = " . $employee['employment_type']);
                    
                    // For REGULAR employees on PAID HOLIDAYS, add guaranteed pay FIRST
                    if ($is_paid_holiday_for_regular) {
                        $holiday_guaranteed_pay += $employee['daily_salary'];
                        error_log("     REGULAR on PAID HOLIDAY: Added guaranteed pay of " . $employee['daily_salary']);
                    }
                    
                    // DAY SESSION CALCULATION (AM+PM) - Treat independently with its own 8-hour quota
                    if ($day_regular_hours > 0) {
                        if ($day_regular_hours <= 8) {
                            // All day hours are regular
                            $regular_hours += $day_regular_hours;
                            // For regular employees on paid holidays, use basic multiplier (1.0) for hours worked
                            if ($is_paid_holiday_for_regular) {
                                $regular_pay += $day_regular_hours * $hourly_rate * 1.0;
                                error_log("     Day Regular (<=8) for Regular on Paid Holiday: " . $day_regular_hours . " hrs × " . $hourly_rate . " × 1.0 = " . ($day_regular_hours * $hourly_rate * 1.0));
                            } else {
                                $regular_pay += $day_regular_hours * $hourly_rate * $multipliers['basic'];
                                error_log("     Day Regular (<=8): " . $day_regular_hours . " hrs × " . $hourly_rate . " × " . $multipliers['basic'] . " = " . ($day_regular_hours * $hourly_rate * $multipliers['basic']));
                            }
                        } else {
                            // Split day hours into regular (8) and overtime (excess)
                            $regular_hours += 8;
                            $overtime_hours += ($day_regular_hours - 8);
                            
                            // For regular employees on paid holidays, use basic multiplier (1.0) for regular hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_pay += 8 * $hourly_rate * 1.0;
                                error_log("     Day Regular (8) for Regular on Paid Holiday: 8 hrs × " . $hourly_rate . " × 1.0 = " . (8 * $hourly_rate * 1.0));
                            } else {
                                $regular_pay += 8 * $hourly_rate * $multipliers['basic'];
                                error_log("     Day Regular (8): 8 hrs × " . $hourly_rate . " × " . $multipliers['basic'] . " = " . (8 * $hourly_rate * $multipliers['basic']));
                            }
                            
                            // Overtime always uses overtime multiplier
                            $overtime_pay += ($day_regular_hours - 8) * $hourly_rate * $multipliers['overtime'];
                            error_log("     Day Overtime: " . ($day_regular_hours - 8) . " hrs × " . $hourly_rate . " × " . $multipliers['overtime'] . " = " . (($day_regular_hours - 8) * $hourly_rate * $multipliers['overtime']));
                        }
                    }
                    
                    // NIGHT SESSION CALCULATION - Treat independently with its own 8-hour quota
                    if ($night_hours > 0) {
                        error_log("     Night hours: " . $night_hours . " hrs - Treating independently with its own 8-hour quota");
                        
                        if ($night_hours <= 8) {
                            // All night hours are regular night
                            $regular_night_hours += $night_hours;
                            // For regular employees on paid holidays, use night multiplier? Wait, no - use basic rate (1.0) for hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_night_pay += $night_hours * $hourly_rate * 1.0;
                                error_log("     All night hours for Regular on Paid Holiday: " . $night_hours . " hrs × " . $hourly_rate . " × 1.0 = " . ($night_hours * $hourly_rate * 1.0));
                            } else {
                                $regular_night_pay += $night_hours * $hourly_rate * $multipliers['night'];
                                error_log("     All night hours are regular: " . $night_hours . " hrs × " . $hourly_rate . " × " . $multipliers['night'] . " = " . ($night_hours * $hourly_rate * $multipliers['night']));
                            }
                        } else {
                            // Split night hours into regular (8) and overtime (excess)
                            $regular_night_hours += 8;
                            $night_shift_overtime_hours += ($night_hours - 8);
                            
                            // For regular employees on paid holidays, use basic rate for regular night hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_night_pay += 8 * $hourly_rate * 1.0;
                                error_log("     Night Regular (8) for Regular on Paid Holiday: 8 hrs × " . $hourly_rate . " × 1.0 = " . (8 * $hourly_rate * 1.0));
                            } else {
                                $regular_night_pay += 8 * $hourly_rate * $multipliers['night'];
                                error_log("     Night Regular (8): 8 hrs × " . $hourly_rate . " × " . $multipliers['night'] . " = " . (8 * $hourly_rate * $multipliers['night']));
                            }
                            
                            // Night overtime uses the same overtime multiplier as day
                            $night_shift_overtime_pay += ($night_hours - 8) * $hourly_rate * $multipliers['overtime'];
                            error_log("     Night Overtime: " . ($night_hours - 8) . " hrs × " . $hourly_rate . " × " . $multipliers['overtime'] . " = " . (($night_hours - 8) * $hourly_rate * $multipliers['overtime']));
                        }
                    }
                }
                else if ($is_absent) {
                    // Absent employee
                    if ($is_paid_holiday_for_regular) {
                        // Regular employee absent on paid holiday - get daily salary
                        $holiday_guaranteed_pay += $employee['daily_salary'];
                        error_log("  -> ABSENT on PAID HOLIDAY: Added daily salary of " . $employee['daily_salary']);
                    } else {
                        error_log("  -> ABSENT (no pay)");
                    }
                    // No work no pay for non-regular employees
                }
            } else {
                // No attendance record for this date - treated as absent with no record
                error_log("DATE: $date_str - No attendance record");
                // Check if this date is a paid holiday? This would require holiday table lookup
                // For now, we'll assume no pay for dates without records
            }
        }
        
        // Calculate total work hours (Day regular + Day overtime + Night regular + Night overtime)
        $total_work_hours = $regular_hours + $overtime_hours + $regular_night_hours + $night_shift_overtime_hours;
        
        // Calculate base salary (regular pay + overtime pay + regular night pay + night overtime pay + holiday guaranteed pay)
        $base_salary = $regular_pay + $overtime_pay + $regular_night_pay + $night_shift_overtime_pay + $holiday_guaranteed_pay;
        
        // Get deductions for the period
        $deduction_query = "SELECT id, deduction_name, amount 
                            FROM deduction 
                            WHERE employee_id = ? 
                            AND status = 'active'
                            AND start_date <= ? 
                            AND end_date >= ?";
        $deduction_stmt = $conn->prepare($deduction_query);
        $deduction_stmt->bind_param("iss", $employee_id, $date_to, $date_from);
        $deduction_stmt->execute();
        $deduction_result = $deduction_stmt->get_result();
        
        $deductions = [];
        $total_deductions = 0;
        while ($row = $deduction_result->fetch_assoc()) {
            $deductions[] = $row;
            $total_deductions += $row['amount'];
        }
        
        $net_pay = $base_salary - $total_deductions;
        
        $response['success'] = true;
        $response['payroll'] = [
            'employee' => $employee,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'total_days' => $total_days,
            'days_present' => $days_present,
            'days_on_leave' => $days_on_leave,
            'total_work_hours' => round($total_work_hours, 2),
            'regular_hours' => round($regular_hours, 2), // Day regular hours
            'overtime_hours' => round($overtime_hours, 2), // Day overtime hours
            'regular_night_hours' => round($regular_night_hours, 2), // Night regular hours
            'night_shift_overtime_hours' => round($night_shift_overtime_hours, 2), // Night overtime hours
            'holiday_guaranteed_pay' => round($holiday_guaranteed_pay, 2),
            'hourly_rate' => $hourly_rate,
            'regular_pay' => round($regular_pay, 2), // Day regular pay
            'overtime_pay' => round($overtime_pay, 2), // Day overtime pay
            'regular_night_pay' => round($regular_night_pay, 2), // Night regular pay
            'night_shift_overtime_pay' => round($night_shift_overtime_pay, 2), // Night overtime pay
            'base_salary' => round($base_salary, 2),
            'deductions' => $deductions,
            'total_deductions' => $total_deductions,
            'net_pay' => round($net_pay, 2),
            'workday_type_summary' => $workday_type_summary
        ];
        
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("Generate payroll error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Save Payroll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payroll'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Log received data for debugging
        error_log("=== SAVE PAYROLL START ===");
        error_log("POST data: " . print_r($_POST, true));
        
        $employee_id = $_POST['employee_id'];
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];
        $base_salary = $_POST['base_salary'];
        $total_deductions = $_POST['total_deductions'];
        $net_pay = $_POST['net_pay'];
        $total_work_hours = $_POST['total_work_hours'];
        $deductions_json = $_POST['deductions'];
        
        error_log("Employee ID: $employee_id");
        error_log("Date From: $date_from");
        error_log("Date To: $date_to");
        error_log("Base Salary: $base_salary");
        error_Log("Total Deductions: $total_deductions");
        error_log("Net Pay: $net_pay");
        error_log("Work Hours: $total_work_hours");
        error_log("Deductions JSON: $deductions_json");
        
        if (!$employee_id || !$date_from || !$date_to || !$base_salary || !$net_pay) {
            $response['message'] = 'All fields are required';
            echo json_encode($response);
            exit;
        }
        
        // Check if payroll already exists
        $check_query = "SELECT id FROM payroll WHERE employee_id = ? AND date_from = ? AND date_to = ?";
        $check_stmt = $conn->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare check failed: " . $conn->error);
        }
        $check_stmt->bind_param("iss", $employee_id, $date_from, $date_to);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        $payroll_id = null;
        
        if ($check_result->num_rows > 0) {
            // Update existing
            $row = $check_result->fetch_assoc();
            $payroll_id = $row['id'];
            error_log("Updating existing payroll ID: $payroll_id");
            
            $update_query = "UPDATE payroll SET base_salary = ?, total_deductions = ?, net_pay = ?, total_work_hours = ?, status = 'pending' WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) {
                throw new Exception("Prepare update failed: " . $conn->error);
            }
            $update_stmt->bind_param("ddddsi", $base_salary, $total_deductions, $net_pay, $total_work_hours, $payroll_id);
            if (!$update_stmt->execute()) {
                throw new Exception('Error updating payroll: ' . $update_stmt->error);
            }
            $update_stmt->close();
            error_log("Update successful");
        } else {
            // Insert new - let's first check what columns actually exist
            $columns_query = "SHOW COLUMNS FROM payroll";
            $columns_result = $conn->query($columns_query);
            $existing_columns = [];
            while ($col = $columns_result->fetch_assoc()) {
                $existing_columns[] = $col['Field'];
            }
            error_log("Existing columns: " . implode(", ", $existing_columns));
            
            // Build dynamic INSERT query based on existing columns
            $insert_columns = ['employee_id', 'date_from', 'date_to', 'base_salary', 'total_deductions', 'net_pay', 'total_work_hours'];
            $insert_values = ['?', '?', '?', '?', '?', '?', '?'];
            $bind_types = "issdddd";
            $bind_params = [$employee_id, $date_from, $date_to, $base_salary, $total_deductions, $net_pay, $total_work_hours];
            
            // Add optional columns if they exist
            if (in_array('payroll_type', $existing_columns)) {
                $insert_columns[] = 'payroll_type';
                $insert_values[] = '?';
                $bind_types .= "s";
                $bind_params[] = 'regular';
            }
            
            if (in_array('status', $existing_columns)) {
                $insert_columns[] = 'status';
                $insert_values[] = '?';
                $bind_types .= "s";
                $bind_params[] = 'pending';
            }
            
            if (in_array('date', $existing_columns)) {
                $insert_columns[] = 'date';
                $insert_values[] = 'NOW()';
                // NOW() doesn't need a parameter
            }
            
            if (in_array('pay_period', $existing_columns)) {
                $insert_columns[] = 'pay_period';
                $insert_values[] = '?';
                $bind_types .= "s";
                $pay_period = date('Y-m-01', strtotime($date_from));
                $bind_params[] = $pay_period;
            }
            
            // Build the query
            $insert_query = "INSERT INTO payroll (" . implode(", ", $insert_columns) . ") 
                             VALUES (" . implode(", ", $insert_values) . ")";
            
            error_log("Dynamic insert query: " . $insert_query);
            error_log("Bind types: " . $bind_types);
            error_log("Bind params: " . print_r($bind_params, true));
            
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                throw new Exception("Prepare insert failed: " . $conn->error);
            }
            
            // Dynamically bind parameters
            if (!empty($bind_params)) {
                $insert_stmt->bind_param($bind_types, ...$bind_params);
            }
            
            if ($insert_stmt->execute()) {
                $payroll_id = $conn->insert_id;
                error_log("Insert successful, new ID: $payroll_id");
            } else {
                throw new Exception('Error saving payroll: ' . $insert_stmt->error);
            }
            $insert_stmt->close();
        }
        
        // Clear old payroll_deductions if any
        $clear_stmt = $conn->prepare("DELETE FROM payroll_deductions WHERE payroll_id = ?");
        if ($clear_stmt) {
            $clear_stmt->bind_param("i", $payroll_id);
            $clear_stmt->execute();
            $clear_stmt->close();
            error_log("Cleared old deductions for payroll ID: $payroll_id");
        }
        
        // Save the deductions used for this payroll
        $deductions = json_decode($deductions_json, true);
        error_log("Deductions decoded: " . print_r($deductions, true));
        
        if (!empty($deductions) && is_array($deductions)) {
            // Check if payroll_deductions table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'payroll_deductions'");
            if ($table_check->num_rows > 0) {
                
                // Insert new payroll_deductions - FIX: Only insert if deduction_id > 0
                $insert_deduction_stmt = $conn->prepare("INSERT INTO payroll_deductions (payroll_id, deduction_id, deduction_name, amount) VALUES (?, ?, ?, ?)");
                if (!$insert_deduction_stmt) {
                    throw new Exception("Prepare insert deductions failed: " . $conn->error);
                }
                
                foreach ($deductions as $deduction) {
                    $deduction_id = isset($deduction['id']) ? intval($deduction['id']) : 0;
                    $deduction_name = isset($deduction['deduction_name']) ? $deduction['deduction_name'] : '';
                    $amount = isset($deduction['amount']) ? floatval($deduction['amount']) : 0;
                    
                    // FIX: Skip deductions with ID 0 (custom deductions that don't exist in deduction table)
                    if ($deduction_id == 0) {
                        error_log("Skipping deduction with ID 0: $deduction_name - $amount (doesn't exist in deduction table)");
                        continue;
                    }
                    
                    // Verify that the deduction_id exists in the deduction table
                    $verify_query = "SELECT id FROM deduction WHERE id = ?";
                    $verify_stmt = $conn->prepare($verify_query);
                    $verify_stmt->bind_param("i", $deduction_id);
                    $verify_stmt->execute();
                    $verify_result = $verify_stmt->get_result();
                    
                    if ($verify_result->num_rows > 0) {
                        // Deduction exists, safe to insert
                        $insert_deduction_stmt->bind_param("iisd", $payroll_id, $deduction_id, $deduction_name, $amount);
                        if (!$insert_deduction_stmt->execute()) {
                            error_log("Error inserting deduction: " . $insert_deduction_stmt->error);
                            // Continue with other deductions even if one fails
                        } else {
                            error_log("Inserted deduction: $deduction_name - $amount with ID: $deduction_id");
                        }
                    } else {
                        error_log("Skipping deduction with non-existent ID: $deduction_id - $deduction_name");
                    }
                    $verify_stmt->close();
                }
                $insert_deduction_stmt->close();
            } else {
                error_log("payroll_deductions table does not exist");
            }
        }
        
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Payroll saved successfully';
        $response['payroll_id'] = $payroll_id;
        error_log("=== SAVE PAYROLL SUCCESS ===");
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("=== SAVE PAYROLL ERROR: " . $e->getMessage() . " ===");
        error_log("Stack trace: " . $e->getTraceAsString());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Recalculate Payroll for Edit
// Handle Recalculate Payroll for Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_payroll'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => '', 'payroll' => null];
    
    try {
        $employee_id = $_POST['employee_id'];
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];
        $current_deductions_json = isset($_POST['current_deductions']) ? $_POST['current_deductions'] : '[]';
        
        if (empty($employee_id) || empty($date_from) || empty($date_to)) {
            $response['message'] = "Missing required parameters";
            echo json_encode($response);
            exit;
        }
        
        // Get employee daily salary and employment type
        $salary_query = "SELECT id, daily_salary, employment_type FROM employees WHERE id = ?";
        $salary_stmt = $conn->prepare($salary_query);
        $salary_stmt->bind_param("i", $employee_id);
        $salary_stmt->execute();
        $salary_result = $salary_stmt->get_result();
        $employee = $salary_result->fetch_assoc();
        
        if (!$employee) {
            $response['message'] = "Employee not found";
            echo json_encode($response);
            exit;
        }
        
        // Calculate days between dates
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $total_days = 0;
        $total_work_hours = 0;
        $regular_hours = 0;
        $overtime_hours = 0;
        $regular_night_hours = 0;
        $night_shift_overtime_hours = 0;
        $regular_pay = 0;
        $overtime_pay = 0;
        $regular_night_pay = 0;
        $night_shift_overtime_pay = 0;
        $holiday_guaranteed_pay = 0;
        $days_present = 0;
        $days_on_leave = 0;
        $attendance_records = [];
        
        // Track attendance by workday type
        $workday_type_summary = [];
        
        // Get attendance records for the period including night shift and workday_type
        $attendance_query = "SELECT date, status, pm_status, night_status, leave_type, workday_type, 
                                    time_in_am, time_out_am, 
                                    time_in_pm, time_out_pm,
                                    time_in_night, time_out_night
                             FROM attendance 
                             WHERE employee_id = ? AND date BETWEEN ? AND ?";
        $attendance_stmt = $conn->prepare($attendance_query);
        $attendance_stmt->bind_param("iss", $employee_id, $date_from, $date_to);
        $attendance_stmt->execute();
        $attendance_result = $attendance_stmt->get_result();
        
        while ($row = $attendance_result->fetch_assoc()) {
            $attendance_records[$row['date']] = $row;
        }
        
        // Calculate hourly rate (daily salary / 8 hours)
        $hourly_rate = $employee['daily_salary'] / 8;
        
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $total_days++;
            
            if (isset($attendance_records[$date_str])) {
                $record = $attendance_records[$date_str];
                
                // Get workday type from database
                $workday_type = $record['workday_type'] ?? 'Ordinary Working Day';
                $multipliers = getWorkdayMultipliers($workday_type);
                
                // Initialize workday type summary if not exists
                if (!isset($workday_type_summary[$workday_type])) {
                    $workday_type_summary[$workday_type] = [
                        'count' => 0,
                        'present_count' => 0,
                        'absent_count' => 0,
                        'leave_count' => 0,
                        'total_hours' => 0
                    ];
                }
                
                // ============================================
                // CALCULATE HOURS FOR EACH SESSION - FIXED FOR 12:00 AM
                // ============================================
                $am_hours = 0;
                $pm_hours = 0;
                $night_hours = 0;
                $day_regular_hours = 0; // AM + PM only
                
                // Calculate AM hours if present - FIXED to handle 00:00:00
                if (!empty($record['time_in_am']) && !empty($record['time_out_am'])) {
                    $am_in = $record['time_in_am'];
                    $am_out = $record['time_out_am'];
                    
                    // Handle midnight (12:00 AM) specially
                    if ($am_out == '00:00:00') {
                        $am_in_ts = strtotime($am_in);
                        $date_of_am_in = date('Y-m-d', $am_in_ts);
                        $next_day = strtotime('+1 day', strtotime($date_of_am_in));
                        $am_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
                        $am_hours = ($am_out_ts - $am_in_ts) / 3600;
                    } else {
                        $am_in_ts = strtotime($am_in);
                        $am_out_ts = strtotime($am_out);
                        if ($am_out_ts < $am_in_ts) $am_out_ts += 86400;
                        $am_hours = ($am_out_ts - $am_in_ts) / 3600;
                    }
                    $am_hours = max(0, $am_hours);
                }
                
                // Calculate PM hours if present - FIXED to handle 00:00:00
                if (!empty($record['time_in_pm']) && !empty($record['time_out_pm'])) {
                    $pm_in = $record['time_in_pm'];
                    $pm_out = $record['time_out_pm'];
                    
                    // Handle midnight (12:00 AM) specially
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
                
                // Calculate Night hours if present - FIXED to handle 00:00:00
                if (!empty($record['time_in_night']) && !empty($record['time_out_night'])) {
                    $night_in = $record['time_in_night'];
                    $night_out = $record['time_out_night'];
                    
                    // Handle midnight (12:00 AM) specially
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
                
                $day_regular_hours = $am_hours + $pm_hours; // Only AM+PM for regular pay
                
                // Check if employee has any attendance
                $has_any_attendance = ($am_hours > 0 || $pm_hours > 0 || $night_hours > 0);
                $is_absent = !$has_any_attendance && empty($record['leave_type']);
                $is_on_leave = !empty($record['leave_type']);
                
                // Update workday type summary
                $workday_type_summary[$workday_type]['count']++;
                if ($is_on_leave) {
                    $workday_type_summary[$workday_type]['leave_count']++;
                } else if ($has_any_attendance) {
                    $workday_type_summary[$workday_type]['present_count']++;
                    $workday_type_summary[$workday_type]['total_hours'] += $day_regular_hours + $night_hours;
                } else {
                    $workday_type_summary[$workday_type]['absent_count']++;
                }
                
                // Check if this is a paid holiday for regular employee
                $is_paid_holiday_for_regular = ($employee['employment_type'] === 'regular' && $multipliers['paid_when_absent']);
                
                // Handle different statuses with correct logic
                if ($is_on_leave) {
                    // On leave - still get daily salary (8 hours)
                    $days_on_leave++;
                    $regular_hours += 8;
                    $regular_pay += $employee['daily_salary'];
                } 
                else if ($has_any_attendance) {
                    // Employee is present
                    $days_present++;
                    
                    // For REGULAR employees on PAID HOLIDAYS, add guaranteed pay FIRST
                    if ($is_paid_holiday_for_regular) {
                        $holiday_guaranteed_pay += $employee['daily_salary'];
                    }
                    
                    // DAY SESSION CALCULATION (AM+PM) - Treat independently with its own 8-hour quota
                    if ($day_regular_hours > 0) {
                        if ($day_regular_hours <= 8) {
                            // All day hours are regular
                            $regular_hours += $day_regular_hours;
                            // For regular employees on paid holidays, use basic multiplier (1.0) for hours worked
                            if ($is_paid_holiday_for_regular) {
                                $regular_pay += $day_regular_hours * $hourly_rate * 1.0;
                            } else {
                                $regular_pay += $day_regular_hours * $hourly_rate * $multipliers['basic'];
                            }
                        } else {
                            // Split day hours into regular (8) and overtime (excess)
                            $regular_hours += 8;
                            $overtime_hours += ($day_regular_hours - 8);
                            
                            // For regular employees on paid holidays, use basic multiplier (1.0) for regular hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_pay += 8 * $hourly_rate * 1.0;
                            } else {
                                $regular_pay += 8 * $hourly_rate * $multipliers['basic'];
                            }
                            
                            // Overtime always uses overtime multiplier
                            $overtime_pay += ($day_regular_hours - 8) * $hourly_rate * $multipliers['overtime'];
                        }
                    }
                    
                    // NIGHT SESSION CALCULATION - Treat independently with its own 8-hour quota
                    if ($night_hours > 0) {
                        if ($night_hours <= 8) {
                            // All night hours are regular night
                            $regular_night_hours += $night_hours;
                            // For regular employees on paid holidays, use basic rate (1.0) for hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_night_pay += $night_hours * $hourly_rate * 1.0;
                            } else {
                                $regular_night_pay += $night_hours * $hourly_rate * $multipliers['night'];
                            }
                        } else {
                            // Split night hours into regular (8) and overtime (excess)
                            $regular_night_hours += 8;
                            $night_shift_overtime_hours += ($night_hours - 8);
                            
                            // For regular employees on paid holidays, use basic rate for regular night hours
                            if ($is_paid_holiday_for_regular) {
                                $regular_night_pay += 8 * $hourly_rate * 1.0;
                            } else {
                                $regular_night_pay += 8 * $hourly_rate * $multipliers['night'];
                            }
                            
                            // Night overtime uses the same overtime multiplier as day
                            $night_shift_overtime_pay += ($night_hours - 8) * $hourly_rate * $multipliers['overtime'];
                        }
                    }
                }
                else if ($is_absent) {
                    // Absent employee
                    if ($is_paid_holiday_for_regular) {
                        // Regular employee absent on paid holiday - get daily salary
                        $holiday_guaranteed_pay += $employee['daily_salary'];
                    }
                }
            }
        }
        
        // Calculate total work hours
        $total_work_hours = $regular_hours + $overtime_hours + $regular_night_hours + $night_shift_overtime_hours;
        
        // Calculate base salary
        $base_salary = $regular_pay + $overtime_pay + $regular_night_pay + $night_shift_overtime_pay + $holiday_guaranteed_pay;
        
        // Parse current deductions
        $current_deductions = json_decode($current_deductions_json, true);
        $total_deductions = 0;
        if (!empty($current_deductions) && is_array($current_deductions)) {
            foreach ($current_deductions as $deduction) {
                $total_deductions += floatval($deduction['amount']);
            }
        }
        
        $net_pay = $base_salary - $total_deductions;
        
        $response['success'] = true;
        $response['payroll'] = [
            'total_days' => $total_days,
            'days_present' => $days_present,
            'days_on_leave' => $days_on_leave,
            'total_work_hours' => round($total_work_hours, 2),
            'regular_hours' => round($regular_hours, 2),
            'overtime_hours' => round($overtime_hours, 2),
            'regular_night_hours' => round($regular_night_hours, 2),
            'night_shift_overtime_hours' => round($night_shift_overtime_hours, 2),
            'holiday_guaranteed_pay' => round($holiday_guaranteed_pay, 2),
            'hourly_rate' => $hourly_rate,
            'regular_pay' => round($regular_pay, 2),
            'overtime_pay' => round($overtime_pay, 2),
            'regular_night_pay' => round($regular_night_pay, 2),
            'night_shift_overtime_pay' => round($night_shift_overtime_pay, 2),
            'base_salary' => round($base_salary, 2),
            'total_deductions' => $total_deductions,
            'net_pay' => round($net_pay, 2),
            'workday_type_summary' => $workday_type_summary
        ];
        
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("Recalculate payroll error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Add Custom Deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_custom_deduction'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $employee_id = $_POST['employee_id'];
        $deduction_name = $_POST['deduction_name'];
        $amount = $_POST['amount'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        if (!$employee_id || !$deduction_name || !$amount || !$start_date || !$end_date) {
            $response['message'] = 'All fields are required';
            echo json_encode($response);
            exit;
        }
        
        if (!is_numeric($amount) || $amount <= 0) {
            $response['message'] = 'Amount must be a positive number';
            echo json_encode($response);
            exit;
        }
        
        $query = "INSERT INTO deduction (employee_id, deduction_name, amount, start_date, end_date, status, description) 
                  VALUES (?, ?, ?, ?, ?, 'active', ?)";
        $stmt = $conn->prepare($query);
        $description = "Custom deduction: " . $deduction_name;
        $stmt->bind_param("isdsss", $employee_id, $deduction_name, $amount, $start_date, $end_date, $description);
        
        if ($stmt->execute()) {
            $deduction_id = $conn->insert_id;
            $response['success'] = true;
            $response['message'] = 'Deduction added successfully';
            $response['deduction'] = [
                'id' => $deduction_id,
                'deduction_name' => $deduction_name,
                'amount' => $amount,
                'start_date' => $start_date,
                'end_date' => $end_date
            ];
        } else {
            throw new Exception('Error adding deduction: ' . $conn->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Add deduction error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Delete Deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_deduction'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $deduction_id = $_POST['deduction_id'];
        
        if (!$deduction_id) {
            $response['message'] = 'Invalid deduction ID';
            echo json_encode($response);
            exit;
        }
        
        $query = "DELETE FROM deduction WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $deduction_id);
        
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Deduction deleted successfully';
        } else {
            throw new Exception('Error deleting deduction: ' . $conn->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Delete deduction error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Get Single Payroll for Edit - FIXED with cache busting
if (isset($_GET['get_payroll'])) {
    // Add cache control headers
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $payroll_id = $_GET['get_payroll'];
        $timestamp = isset($_GET['t']) ? $_GET['t'] : time(); // For cache busting
        
        if (!$payroll_id) {
            $response['message'] = 'Invalid payroll ID';
            echo json_encode($response);
            exit;
        }
        
        // Get main payroll data with fresh data - FIXED: Use LEFT JOIN to handle deleted employees
        $query = "SELECT p.*, 
                         COALESCE(e.first_name, 'Deleted') as first_name, 
                         COALESCE(e.last_name, 'Deleted') as last_name, 
                         COALESCE(e.position, 'N/A') as position, 
                         COALESCE(e.daily_salary, 0) as daily_salary,
                         COALESCE(e.contact_num, 'N/A') as contact_num,
                         COALESCE(e.employment_type, 'regular') as employment_type
                  FROM payroll p 
                  LEFT JOIN employees e ON e.id = p.employee_id 
                  WHERE p.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $payroll_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $payroll = $result->fetch_assoc();
            
            // Log the data being sent
            error_log("Sending payroll data for ID $payroll_id - Net Pay: " . $payroll['net_pay']);
            
            // Check if payroll_deductions table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'payroll_deductions'");
            if ($table_check->num_rows > 0) {
                // Get deductions for this payroll - ensure we get fresh data
                $deduction_query = "SELECT deduction_name, amount FROM payroll_deductions WHERE payroll_id = ?";
                $deduction_stmt = $conn->prepare($deduction_query);
                $deduction_stmt->bind_param("i", $payroll_id);
                $deduction_stmt->execute();
                $deduction_result = $deduction_stmt->get_result();
                
                $deductions = [];
                $total_deductions_from_details = 0;
                while ($row = $deduction_result->fetch_assoc()) {
                    $deductions[] = $row;
                    $total_deductions_from_details += floatval($row['amount']);
                }
                
                $payroll['deductions'] = $deductions;
                
                // Verify total_deductions matches the sum
                if (abs(floatval($payroll['total_deductions']) - $total_deductions_from_details) > 0.01) {
                    error_log("WARNING: Total deductions mismatch for payroll $payroll_id");
                }
            }
            
            $response['success'] = true;
            $response['data'] = $payroll;
            $response['timestamp'] = $timestamp;
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

// Handle Update Payroll - Only status can be edited manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payroll_status'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $payroll_id = $_POST['payroll_id'];
        $status = $_POST['status'];
        
        if (!$payroll_id || !$status) {
            $response['message'] = 'All fields are required';
            echo json_encode($response);
            exit;
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update the status
        $update_query = "UPDATE payroll SET status = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("si", $status, $payroll_id);
        
        if ($update_stmt->execute()) {
            // Get the updated record to confirm - FIXED: Use LEFT JOIN to handle deleted employees
            $select_query = "SELECT p.*, COALESCE(e.first_name, 'Deleted') as first_name, COALESCE(e.last_name, 'Deleted') as last_name 
                            FROM payroll p 
                            LEFT JOIN employees e ON e.id = p.employee_id 
                            WHERE p.id = ?";
            $select_stmt = $conn->prepare($select_query);
            $select_stmt->bind_param("i", $payroll_id);
            $select_stmt->execute();
            $result = $select_stmt->get_result();
            $updated_record = $result->fetch_assoc();
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = 'Payroll status updated successfully';
            $response['data'] = [
                'id' => $payroll_id,
                'status' => $status,
                'employee_name' => $updated_record['first_name'] . ' ' . $updated_record['last_name']
            ];
        } else {
            $conn->rollback();
            throw new Exception('Error updating payroll: ' . $conn->error);
        }
        
        $update_stmt->close();
        if (isset($select_stmt)) $select_stmt->close();
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("Update payroll error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Save Edited Payroll (with all changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_edited_payroll'])) {
    // Ensure we only output JSON
    header('Content-Type: application/json');
    ob_clean(); // Clear any previous output
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Log received data for debugging
        error_log("=== SAVE EDITED PAYROLL START ===");
        error_log("POST data: " . print_r($_POST, true));
        
        $payroll_id = isset($_POST['payroll_id']) ? $_POST['payroll_id'] : '';
        $date_from = isset($_POST['date_from']) ? $_POST['date_from'] : '';
        $date_to = isset($_POST['date_to']) ? $_POST['date_to'] : '';
        $base_salary = isset($_POST['base_salary']) ? $_POST['base_salary'] : 0;
        $total_deductions = isset($_POST['total_deductions']) ? $_POST['total_deductions'] : 0;
        $net_pay = isset($_POST['net_pay']) ? $_POST['net_pay'] : 0;
        $total_work_hours = isset($_POST['total_work_hours']) ? $_POST['total_work_hours'] : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : 'pending';
        $deductions_json = isset($_POST['deductions']) ? $_POST['deductions'] : '[]';
        
        error_log("Payroll ID: $payroll_id");
        error_log("Date From: $date_from");
        error_log("Date To: $date_to");
        error_log("Base Salary: $base_salary");
        error_log("Total Deductions: $total_deductions");
        error_log("Net Pay: $net_pay");
        error_log("Work Hours: $total_work_hours");
        error_log("Status: $status");
        error_log("Deductions JSON: $deductions_json");
        
        if (!$payroll_id || !$date_from || !$date_to) {
            $response['message'] = 'Payroll ID and dates are required';
            echo json_encode($response);
            exit;
        }
        
        // Validate numeric values
        if (!is_numeric($base_salary) || !is_numeric($total_deductions) || !is_numeric($net_pay) || !is_numeric($total_work_hours)) {
            $response['message'] = 'Invalid numeric values';
            echo json_encode($response);
            exit;
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        // First, get the current payroll to verify it exists
        $check_query = "SELECT id FROM payroll WHERE id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $payroll_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $conn->rollback();
            $response['message'] = 'Payroll record not found';
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        // Update payroll with all changes - ensure all fields are updated correctly
        $update_query = "UPDATE payroll SET 
            date_from = ?, 
            date_to = ?, 
            base_salary = ?, 
            total_deductions = ?, 
            net_pay = ?, 
            total_work_hours = ?, 
            status = ? 
            WHERE id = ?";
            
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception("Prepare update failed: " . $conn->error);
        }
        
        // Log the values being updated
        error_log("Updating payroll ID: $payroll_id with:");
        error_log("Base Salary: $base_salary");
        error_log("Total Deductions: $total_deductions");
        error_log("Net Pay: $net_pay");
        error_log("Work Hours: $total_work_hours");
        
        $update_stmt->bind_param("ssddddsi", 
            $date_from, 
            $date_to, 
            $base_salary, 
            $total_deductions, 
            $net_pay, 
            $total_work_hours, 
            $status, 
            $payroll_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception('Error updating payroll: ' . $update_stmt->error);
        }
        $update_stmt->close();
        error_log("Payroll update successful - main table updated");
        
        // Clear old payroll_deductions
        $clear_stmt = $conn->prepare("DELETE FROM payroll_deductions WHERE payroll_id = ?");
        if (!$clear_stmt) {
            throw new Exception("Prepare clear deductions failed: " . $conn->error);
        }
        $clear_stmt->bind_param("i", $payroll_id);
        $clear_stmt->execute();
        $clear_stmt->close();
        error_log("Cleared old deductions");
        
        // Save the deductions used for this payroll
        $deductions = json_decode($deductions_json, true);
        error_log("Deductions decoded: " . print_r($deductions, true));
        
        if (!empty($deductions) && is_array($deductions)) {
            // Check if payroll_deductions table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'payroll_deductions'");
            if ($table_check->num_rows > 0) {
                
                // Prepare two different statements - one with ID, one without
                $insert_with_id = $conn->prepare("INSERT INTO payroll_deductions (payroll_id, deduction_id, deduction_name, amount) VALUES (?, ?, ?, ?)");
                $insert_without_id = $conn->prepare("INSERT INTO payroll_deductions (payroll_id, deduction_id, deduction_name, amount) VALUES (?, NULL, ?, ?)");
                
                if (!$insert_with_id || !$insert_without_id) {
                    throw new Exception("Prepare insert deductions failed: " . $conn->error);
                }
                
                foreach ($deductions as $deduction) {
                    $deduction_id = isset($deduction['id']) ? intval($deduction['id']) : 0;
                    $deduction_name = isset($deduction['deduction_name']) ? $deduction['deduction_name'] : '';
                    $amount = isset($deduction['amount']) ? floatval($deduction['amount']) : 0;
                    
                    if ($deduction_id > 0) {
                        // This deduction exists in the deduction table
                        $insert_with_id->bind_param("iisd", $payroll_id, $deduction_id, $deduction_name, $amount);
                        if (!$insert_with_id->execute()) {
                            error_log("Error inserting deduction with ID: " . $insert_with_id->error);
                        } else {
                            error_log("Inserted deduction with ID $deduction_id: $deduction_name - $amount");
                        }
                    } else {
                        // This is a custom deduction not in the deduction table - set deduction_id to NULL
                        $insert_without_id->bind_param("isd", $payroll_id, $deduction_name, $amount);
                        if (!$insert_without_id->execute()) {
                            error_log("Error inserting deduction without ID: " . $insert_without_id->error);
                        } else {
                            error_log("Inserted custom deduction: $deduction_name - $amount (ID set to NULL)");
                        }
                    }
                }
                
                $insert_with_id->close();
                $insert_without_id->close();
            } else {
                error_log("payroll_deductions table does not exist");
            }
        }
        
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Payroll updated successfully';
        $response['payroll_id'] = $payroll_id;
        error_log("=== SAVE EDITED PAYROLL SUCCESS ===");
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        $response['message'] = 'Error: ' . $e->getMessage();
        error_log("=== SAVE EDITED PAYROLL ERROR: " . $e->getMessage() . " ===");
        error_log("Stack trace: " . $e->getTraceAsString());
    }
    
    // Ensure clean JSON output
    echo json_encode($response);
    exit;
}

// Handle Main Page Payroll Search - FIXED to handle deleted employees
if (isset($_GET['search_payroll'])) {
    header('Content-Type: application/json');
    
    $search_term = isset($_GET['term']) ? $_GET['term'] : '';
    $response = ['success' => true, 'payrolls' => []];
    
    if (!empty($search_term)) {
        $search_term = '%' . $conn->real_escape_string($search_term) . '%';
        $query = "SELECT p.id, p.date_from, p.date_to, p.base_salary, p.total_deductions, p.net_pay, p.status,
                         COALESCE(CONCAT(e.first_name, ' ', e.last_name), 'Deleted Employee') AS employee_name, 
                         COALESCE(e.position, 'N/A') as position, 
                         COALESCE(e.id, 'N/A') as employee_id, 
                         COALESCE(e.daily_salary, 0) as daily_salary
                  FROM payroll p
                  LEFT JOIN employees e ON e.id = p.employee_id
                  WHERE CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) LIKE ? 
                     OR COALESCE(e.position, '') LIKE ? 
                     OR COALESCE(e.id, 0) LIKE ?
                     OR p.date_from LIKE ?
                     OR p.date_to LIKE ?
                  ORDER BY p.date_from DESC
                  LIMIT 20";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssss", $search_term, $search_term, $search_term, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $response['payrolls'][] = [
                'id' => $row['id'],
                'employee_name' => $row['employee_name'],
                'employee_id' => $row['employee_id'],
                'position' => $row['position'],
                'date_from' => date('M d, Y', strtotime($row['date_from'])),
                'date_to' => date('M d, Y', strtotime($row['date_to'])),
                'net_pay' => $row['net_pay'],
                'status' => $row['status']
            ];
        }
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX request for table refresh - FIXED to handle deleted employees
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $month = isset($_GET['month']) ? $_GET['month'] : date('m');
    $year = isset($_GET['year']) ? $_GET['year'] : date('Y');
    
    $sql = "
        SELECT p.id, p.date_from, p.date_to, 
               COALESCE(p.base_salary, 0) as base_salary, 
               COALESCE(p.total_deductions, 0) as total_deductions, 
               COALESCE(p.net_pay, 0) as net_pay, 
               COALESCE(p.total_work_hours, 0) as total_work_hours, 
               p.status,
               COALESCE(CONCAT(e.first_name, ' ', e.last_name), 'Deleted Employee') AS employee_name, 
               COALESCE(e.position, 'N/A') as position, 
               COALESCE(e.id, 'N/A') as employee_id, 
               COALESCE(e.daily_salary, 0) as daily_salary,
               COALESCE(e.contact_num, 'N/A') as contact_num
        FROM payroll p
        LEFT JOIN employees e ON e.id = p.employee_id
        WHERE MONTH(p.date_from) = ? AND YEAR(p.date_from) = ?
        ORDER BY p.date_from DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// Get custom deductions for dropdown
$custom_deductions_query = "SELECT name FROM custom_deductions ORDER BY name";
$custom_deductions_result = $conn->query($custom_deductions_query);

// Get all employees for filter dropdown
$employees_filter_query = "SELECT id, first_name, last_name FROM employees WHERE status = 'active' ORDER BY first_name";
$employees_filter_result = $conn->query($employees_filter_query);

// Get all sites for filter dropdown
$sites_query = "SELECT id, site_name FROM site_monitoring ORDER BY site_name";
$sites_result = $conn->query($sites_query);

// Build the main query with filters
$sql = "
    SELECT p.id, p.date_from, p.date_to, 
           COALESCE(p.base_salary, 0) as base_salary, 
           COALESCE(p.total_deductions, 0) as total_deductions, 
           COALESCE(p.net_pay, 0) as net_pay, 
           COALESCE(p.total_work_hours, 0) as total_work_hours, 
           p.status,
           COALESCE(CONCAT(e.first_name, ' ', e.last_name), 'Deleted Employee') AS employee_name, 
           COALESCE(e.position, 'N/A') as position, 
           e.id as employee_id, 
           COALESCE(e.daily_salary, 0) as daily_salary,
           COALESCE(e.contact_num, 'N/A') as contact_num,
           COALESCE(s.site_name, 'N/A') as site_name
    FROM payroll p
    LEFT JOIN employees e ON e.id = p.employee_id
    LEFT JOIN site_employee se ON se.employee_id = e.id
    LEFT JOIN site_monitoring s ON s.id = se.site_id
    WHERE MONTH(p.date_from) = ? AND YEAR(p.date_from) = ?
";

// Add filters if set
$params = [$month, $year];
$types = "ii";

if (!empty($employee_filter)) {
    $sql .= " AND e.id = ?";
    $params[] = $employee_filter;
    $types .= "i";
}

if (!empty($site_filter)) {
    $sql .= " AND s.id = ?";
    $params[] = $site_filter;
    $types .= "i";
}

if (!empty($search_term)) {
    $sql .= " AND (CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.position LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$sql .= " ORDER BY p.date_from DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Calculate summary totals
$summary_query = "
    SELECT 
        COUNT(DISTINCT p.employee_id) as total_employees,
        COALESCE(SUM(p.base_salary), 0) as total_payroll,
        COALESCE(SUM(p.total_deductions), 0) as total_deductions,
        COALESCE(SUM(p.net_pay), 0) as total_net_salary
    FROM payroll p
    LEFT JOIN employees e ON e.id = p.employee_id
    WHERE MONTH(p.date_from) = ? AND YEAR(p.date_from) = ?
";

$summary_stmt = $conn->prepare($summary_query);
$summary_stmt->bind_param("ii", $month, $year);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();

$total_employees = $summary['total_employees'] ?? 0;
$total_payroll = $summary['total_payroll'] ?? 0;
$total_deductions = $summary['total_deductions'] ?? 0;
$total_net_salary = $summary['total_net_salary'] ?? 0;

// Fetch all active employees for the generate modal
$employees_query = "SELECT id, first_name, last_name, position, COALESCE(daily_salary, 0) as daily_salary, address, contact_num, employment_type FROM employees WHERE status = 'active' ORDER BY first_name, last_name";
$employees_result = $conn->query($employees_query);
?>

<!-- The rest of your HTML and JavaScript remains exactly the same - only the displayPayrollSummary function below is modified to show separate rows for Day Overtime, Regular Night, and Night Overtime -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, height=device-height">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Payroll Management System</title>
<link rel="stylesheet" href="./assets/css/payrollList2.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Full height layout fix */
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body {
        display: flex;
        flex-direction: column;
        padding-top: var(--header-height, 60px) !important;
        background-color: #f5f7fa;
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
    
    /* Payroll Summary Cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 25px;
    }
    
    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: all 0.3s ease;
        border: 1px solid #e9ecef;
    }
    
    .summary-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(117, 230, 218, 0.15);
        border-color: #75e6da;
    }
    
    .summary-info h3 {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 500;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .summary-info .value {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1e293b;
        margin-top: 5px;
    }
    
    .summary-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
    }
    
    /* Filter Section */
    .filters-section {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border: 1px solid #e9ecef;
    }
    
    .filters-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filters-title i {
        color: #75e6da;
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr auto;
        gap: 15px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .filter-group label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .filter-control {
        padding: 10px 15px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.95rem;
        transition: all 0.3s;
        background: white;
    }
    
    .filter-control:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        outline: none;
    }
    
    .filter-control:hover {
        border-color: #75e6da;
    }
    
    .search-group {
        position: relative;
    }
    
    .search-group i {
        position: absolute;
        right: 12px;
        top: 70%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
    }
    
    .filter-actions {
        display: flex;
        gap: 10px;
    }
    
    .filter-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
        white-space: nowrap;
    }
    
    .filter-btn.primary {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        box-shadow: 0 2px 8px rgba(117, 230, 218, 0.3);
    }
    
    .filter-btn.primary:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(117, 230, 218, 0.4);
    }
    
    .filter-btn.secondary {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    
    .filter-btn.secondary:hover {
        background: #e2e8f0;
        transform: translateY(-2px);
    }
    
    .generate-payroll-btn {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3);
    }
    
    .generate-payroll-btn:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(117, 230, 218, 0.4);
    }
    
    /* Payroll Table */
    .payroll-table-container {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border: 1px solid #e9ecef;
        overflow-x: auto;
    }
    
    .payroll-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }
    
    .payroll-table th {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        padding: 15px 12px;
        text-align: center;
        font-weight: 600;
        color: #1e293b;
        border-bottom: 2px solid #e2e8f0;
        white-space: nowrap;
    }
    
    .payroll-table td {
        padding: 15px 12px;
        text-align: center;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }
    
    .payroll-table tbody tr {
        transition: background-color 0.2s ease;
    }
    
    .payroll-table tbody tr:nth-child(even) {
        background-color: #f8fafc;
    }
    
    .payroll-table tbody tr:hover {
        background-color: #e6f7f5;
    }
    
    .payroll-table td:first-child,
    .payroll-table th:first-child {
        text-align: left;
        padding-left: 15px;
    }
    
    .employee-info-cell {
        text-align: left;
    }
    
    .employee-name {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }
    
    .employee-details {
        font-size: 0.8rem;
        color: #64748b;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .employee-details span {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .employee-details i {
        width: 14px;
        color: #75e6da;
    }
    
    .net-pay-highlight {
        font-weight: 700;
        color: #00838f;
        font-size: 1rem;
    }
    
    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 30px;
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
    
    .status-unpaid {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    /* Action Buttons - Print button removed */
    .action-buttons {
        display: flex;
        gap: 8px;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 8px 12px;
        border: none;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s;
        white-space: nowrap;
    }
    
    .action-btn.view {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        box-shadow: 0 2px 6px rgba(117, 230, 218, 0.3);
    }
    
    .action-btn.view:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(117, 230, 218, 0.4);
    }
    
    .action-btn.edit {
        background: linear-gradient(135deg, #4CAF50, #2E7D32);
        color: white;
        box-shadow: 0 2px 6px rgba(76, 175, 80, 0.3);
    }
    
    .action-btn.edit:hover {
        background: linear-gradient(135deg, #2E7D32, #1B5E20);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
    }
    
    .action-btn.delete {
        background: linear-gradient(135deg, #f44336, #d32f2f);
        color: white;
        box-shadow: 0 2px 6px rgba(244, 67, 54, 0.3);
    }
    
    .action-btn.delete:hover {
        background: linear-gradient(135deg, #d32f2f, #b71c1c);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(244, 67, 54, 0.4);
    }
    
    /* Modal Styles */
    .modal {
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

    .modal.show {
        display: flex;
    }

    .modal-content {
        background-color: white;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
        animation: modalSlideIn 0.3s ease;
    }

    .modal-content.large {
        max-width: 900px;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        padding: 20px 25px;
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        border-radius: 16px 16px 0 0;
        display: flex;
        align-items: center;
        gap: 10px;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .modal-header.info {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
    }

    .modal-header.success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
    }

    .modal-header.warning {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
    }

    .modal-header.primary {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
    }

    .modal-header i {
        font-size: 20px;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        flex: 1;
    }

    .close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.3s;
    }

    .close-btn:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .modal-body {
        padding: 25px;
    }

    .modal-footer {
        padding: 15px 25px;
        background-color: #f8fafc;
        border-top: 1px solid #e9ecef;
        border-radius: 0 0 16px 16px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .modal-btn {
        padding: 10px 24px;
        border: none;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .modal-btn.cancel {
        background: #e2e8f0;
        color: #334155;
    }

    .modal-btn.cancel:hover {
        background: #cbd5e1;
    }

    .modal-btn.confirm {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
    }

    .modal-btn.confirm:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(117, 230, 218, 0.3);
    }

    .modal-btn.edit {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
    }

    .modal-btn.edit:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
    }

    .modal-btn.delete {
        background: linear-gradient(135deg, #f44336, #d32f2f);
        color: white;
    }

    .modal-btn.delete:hover {
        background: linear-gradient(135deg, #d32f2f, #b71c1c);
    }

    .modal-btn.primary {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
    }

    .modal-btn.primary:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
    }

    /* Employee Selection Grid */
    .employee-selection-grid {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 5px;
        margin-top: 10px;
    }

    .employee-grid-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        cursor: pointer;
        transition: all 0.2s;
        border-radius: 8px;
    }

    .employee-grid-item:hover {
        background: #e6f7f5;
    }

    .employee-grid-item.selected {
        background: #d1f0ec;
        border-left: 4px solid #75e6da;
    }

    .employee-grid-item:last-child {
        border-bottom: none;
    }

    .employee-grid-info {
        flex: 1;
    }

    .employee-grid-name {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 4px;
    }

    .employee-grid-details {
        font-size: 0.85rem;
        color: #7f8c8d;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    /* Date Picker */
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
        padding: 12px 45px 12px 15px;
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
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 0.9rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .calendar-dropdown-btn:hover {
        background: #62d4c8;
        transform: translateY(-50%) scale(1.1);
        box-shadow: 0 4px 8px rgba(117, 230, 218, 0.3);
    }
    
    .calendar-wrapper {
        position: absolute;
        top: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        z-index: 10000;
        display: none;
        width: 280px;
    }
    
    .calendar-wrapper.show {
        display: block;
    }
    
    .calendar-box {
        width: 100%;
        background: white;
        border-radius: 12px;
        overflow: hidden;
    }
    
    .calendar-header {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        padding: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .calendar-month-year {
        font-weight: 600;
        font-size: 0.95rem;
    }
    
    .calendar-nav {
        display: flex;
        gap: 8px;
    }
    
    .calendar-nav-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 1rem;
        line-height: 1;
    }
    
    .calendar-nav-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }
    
    .calendar-selectors {
        display: flex;
        gap: 5px;
        padding: 10px 10px 5px 10px;
        background: white;
    }
    
    .calendar-select {
        flex: 1;
        padding: 4px 6px;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #2c3e50;
        background: white;
        cursor: pointer;
        transition: all 0.3s;
        outline: none;
    }
    
    .calendar-select:hover {
        border-color: #75e6da;
    }
    
    .calendar-select:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 2px rgba(117, 230, 218, 0.2);
    }
    
    .calendar-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: #f8f9fa;
        padding: 8px 5px;
        text-align: center;
        font-weight: 600;
        font-size: 0.7rem;
        color: #2c3e50;
        border-bottom: 1px solid #e9ecef;
    }
    
    .calendar-days-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 2px;
        padding: 5px;
        background: white;
    }
    
    .calendar-day {
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4px;
        cursor: pointer;
        border-radius: 50%;
        transition: all 0.2s;
        font-size: 0.8rem;
        color: #2c3e50;
        text-decoration: none;
        border: none;
        background: none;
        width: 100%;
        min-width: 24px;
        min-height: 24px;
    }
    
    .calendar-day:hover {
        background: #e6f7f5;
        color: #2E7D32;
    }
    
    .calendar-day.selected {
        background: #75e6da;
        color: white;
        font-weight: 600;
    }
    
    .calendar-day.today {
        border: 2px solid #75e6da;
        font-weight: 600;
    }
    
    .calendar-day.weekend {
        color: #e74c3c;
    }
    
    .calendar-day.other-month {
        color: #bdc3c7;
    }
    
    .calendar-footer {
        padding: 8px;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
    }
    
    .calendar-action-btn {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        border: none;
    }
    
    .calendar-action-btn.clear {
        background: #f8f9fa;
        color: #7f8c8d;
        border: 1px solid #bdc3c7;
    }
    
    .calendar-action-btn.clear:hover {
        background: #e74c3c;
        color: white;
        border-color: #e74c3c;
    }
    
    .calendar-action-btn.today {
        background: #75e6da;
        color: white;
        border: 1px solid #75e6da;
    }
    
    .calendar-action-btn.today:hover {
        background: #62d4c8;
    }

    /* Form Controls */
    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #2c3e50;
        font-size: 14px;
    }

    .form-group label i {
        margin-right: 8px;
        color: #75e6da;
    }

    .form-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 0.95rem;
        transition: all 0.3s;
        box-sizing: border-box;
    }

    .form-control:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        outline: none;
    }

    .form-control[readonly] {
        background-color: #f8f9fa;
        cursor: not-allowed;
    }

    .date-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    /* Employee Details Card */
    .employee-details-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 15px;
        margin: 15px 0;
        border-left: 4px solid #75e6da;
        position: relative;
    }

    .employee-details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-top: 10px;
    }

    .detail-item {
        padding: 8px;
        background: white;
        border-radius: 8px;
    }

    .detail-label {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 2px;
    }

    .detail-value {
        font-weight: 600;
        color: #2c3e50;
    }

    .change-employee-btn {
        position: absolute;
        top: -10px;
        right: -10px;
        background: #dc3545;
        color: white;
        border: none;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    .change-employee-btn:hover {
        background: #c82333;
        transform: scale(1.1);
    }

    /* Payroll Summary Styles */
    .payroll-summary-container {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
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
    
    /* Workday Type Summary Table */
    .workday-summary {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        margin-bottom: 25px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
        border: 1px solid #e2e8f0;
    }
    
    .workday-header {
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
    
    .workday-header i {
        color: #75e6da;
    }
    
    .workday-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .workday-table th {
        background: #f8fafc;
        padding: 12px 10px;
        text-align: center;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .workday-table td {
        padding: 10px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.9rem;
    }
    
    .workday-table tr:last-child td {
        border-bottom: none;
    }
    
    .workday-type-name {
        font-weight: 600;
        color: #1e293b;
        text-align: left;
        padding-left: 15px;
    }
    
    .workday-count {
        font-weight: 500;
    }
    
    .workday-present {
        color: #059669;
        font-weight: 600;
    }
    
    .workday-absent {
        color: #dc2626;
        font-weight: 600;
    }
    
    .workday-leave {
        color: #d97706;
        font-weight: 600;
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
        background: linear-gradient(90deg, #75e6da, #62d4c8);
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
    
    /* Enhanced Deductions Section */
    .deductions-section-enhanced {
        background: white;
        border-radius: 16px;
        padding: 0;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
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
    
    .add-deduction-btn-enhanced {
        background: white;
        border: 1px solid #75e6da;
        color: #0f172a;
        padding: 6px 16px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s;
    }
    
    .add-deduction-btn-enhanced:hover {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        border-color: #75e6da;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(117, 230, 218, 0.3);
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
    
    .deduction-delete-enhanced {
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        font-size: 1rem;
        padding: 5px;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    
    .deduction-delete-enhanced:hover {
        background: #fee2e2;
        color: #dc2626;
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
    
    .empty-deductions-enhanced p {
        margin: 0;
        font-size: 0.9rem;
    }
    
    .total-summary-card {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        border-radius: 16px;
        padding: 20px;
        margin-top: 20px;
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
    
    /* Deductions Section */
    .deductions-section {
        margin: 20px 0;
    }

    .deductions-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .deductions-header h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 16px;
    }

    .add-deduction-btn {
        background: linear-gradient(135deg, #ff9800, #f57c00);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s;
    }

    .add-deduction-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .deductions-list {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        max-height: 200px;
        overflow-y: auto;
    }

    .deduction-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        position: relative;
    }

    .deduction-item:last-child {
        border-bottom: none;
    }

    .deduction-info {
        flex: 1;
    }

    .deduction-name {
        font-weight: 600;
        color: #2c3e50;
    }

    .deduction-amount {
        color: #dc3545;
        font-weight: 600;
        margin-right: 35px;
    }

    .deduction-actions {
        display: flex;
        gap: 5px;
    }

    .deduction-delete {
        background: none;
        border: none;
        color: #dc3545;
        cursor: pointer;
        font-size: 1.1rem;
        padding: 5px 10px;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .deduction-delete:hover {
        background: #fee;
        color: #c82333;
        transform: scale(1.1);
    }

    .empty-deductions {
        text-align: center;
        padding: 30px 20px;
        color: #999;
    }

    .empty-deductions i {
        font-size: 2.5rem;
        margin-bottom: 10px;
        color: #ddd;
    }

    /* Add Deduction Modal */
    .deduction-modal-content {
        max-width: 500px;
    }

    .deduction-form-group {
        margin-bottom: 15px;
    }

    .deduction-form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #2c3e50;
        font-size: 13px;
    }

    .deduction-form-group label i {
        margin-right: 5px;
        color: #75e6da;
    }

    .deduction-form-control {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 13px;
        box-sizing: border-box;
        transition: all 0.3s;
    }

    .deduction-form-control:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        outline: none;
    }

    .deduction-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    /* Loading Overlay */
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.9);
        z-index: 10000;
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

    /* Success Modal */
    .success-message {
        text-align: center;
        padding: 20px;
        font-size: 16px;
        color: #155724;
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

    /* Delete Confirmation Modal */
    .delete-icon {
        font-size: 48px;
        color: #dc3545;
        text-align: center;
        margin-bottom: 15px;
    }

    .delete-message {
        text-align: center;
        margin-bottom: 20px;
        font-size: 16px;
        color: #333;
    }

    .delete-details {
        background: #f8fafc;
        padding: 15px;
        border-radius: 8px;
        margin: 15px 0;
        font-size: 14px;
    }

    .delete-warning {
        color: #dc3545;
        font-size: 13px;
        font-style: italic;
        margin-top: 10px;
        text-align: center;
    }

    /* Payroll Steps */
    .payroll-step {
        display: none;
    }

    .payroll-step.active {
        display: block;
    }

    .step-indicators {
        display: flex;
        margin-bottom: 25px;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 10px;
    }

    .step-indicator {
        flex: 1;
        text-align: center;
        position: relative;
    }

    .step-indicator.active .step-number {
        background: #75e6da;
        color: white;
    }

    .step-indicator.completed .step-number {
        background: #28a745;
        color: white;
    }

    .step-number {
        width: 32px;
        height: 32px;
        background: #e0e0e0;
        color: #666;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 8px;
        font-weight: 600;
    }

    .step-label {
        font-size: 0.9rem;
        color: #666;
        font-weight: 500;
    }

    .step-indicator.active .step-label {
        color: #75e6da;
        font-weight: 600;
    }

    /* Notification Modal */
    .notification-modal {
        display: none;
        position: fixed;
        z-index: 10001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        align-items: center;
        justify-content: center;
    }

    .notification-modal.show {
        display: flex;
    }

    .notification-modal-content {
        background: white;
        border-radius: 16px;
        width: 400px;
        max-width: 90%;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: modalSlideIn 0.3s ease;
    }

    .notification-modal-header {
        padding: 20px;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 16px 16px 0 0;
    }

    .notification-modal-header.success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
    }

    .notification-modal-header.error {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
    }

    .notification-modal-header.warning {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        color: #856404;
    }

    .notification-modal-header.info {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        color: #0c5460;
    }

    .notification-modal-header i {
        font-size: 24px;
    }

    .notification-modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        flex: 1;
    }

    .notification-modal-body {
        padding: 25px;
        text-align: center;
        font-size: 15px;
    }

    .notification-modal-body p {
        margin: 0;
    }

    .notification-modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: center;
    }

    .notification-modal-btn {
        padding: 10px 30px;
        border: none;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .notification-modal-btn.success {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
    }

    .notification-modal-btn.success:hover {
        background: linear-gradient(135deg, #62d4c8, #4fb3aa);
    }

    /* Table responsive */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 20px;
    }

    /* Filter controls */
    .controls {
        background: white;
        padding: 20px;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        margin: 20px 0;
        border: 1px solid #e9ecef;
    }

    .filter-btn.old {
        background: #6c757d;
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

    .filter-btn.old:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    /* Existing Payroll Warning Modal */
    .warning-icon {
        font-size: 48px;
        color: #ffc107;
        text-align: center;
        margin-bottom: 15px;
    }

    /* Recalculate Button */
    .recalculate-btn {
        background: #17a2b8;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s;
        margin-right: 10px;
    }

    .recalculate-btn:hover {
        background: #138496;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .recalculate-btn i {
        font-size: 0.9rem;
    }
    
    /* Employee Type Badge */
    .employment-type-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .employment-type-badge.regular {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .employment-type-badge.non-regular {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
</style>
</head>
<body>

<?php include_once("./includes/header.php"); ?>

<main class="content">
    <div class="content-wrapper">
        <!-- Payroll Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-info">
                    <h3>Total Employees</h3>
                    <div class="value"><?php echo $total_employees; ?></div>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-info">
                    <h3>Total Payroll</h3>
                    <div class="value">₱<?php echo number_format($total_payroll, 2); ?></div>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-info">
                    <h3>Total Deductions</h3>
                    <div class="value">₱<?php echo number_format($total_deductions, 2); ?></div>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-minus-circle"></i>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-info">
                    <h3>Net Salary</h3>
                    <div class="value">₱<?php echo number_format($total_net_salary, 2); ?></div>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="filters-title">
                <i class="fas fa-filter"></i>
                Filter Payroll Records
            </div>
            <form method="GET" action="payrollList.php" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="month">Month</label>
                        <select name="month" id="month" class="filter-control">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0,0,0,$m,10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="year">Year</label>
                        <input type="number" name="year" id="year" value="<?php echo $year; ?>" min="2000" max="2100" class="filter-control">
                    </div>
                    
                    <div class="filter-group">
                        <label for="employee_id">Employee</label>
                        <select name="employee_id" id="employee_id" class="filter-control">
                            <option value="">All Employees</option>
                            <?php if ($employees_filter_result && $employees_filter_result->num_rows > 0): ?>
                                <?php $employees_filter_result->data_seek(0); ?>
                                <?php while ($emp = $employees_filter_result->fetch_assoc()): ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $employee_filter == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="site_id">Site/Department</label>
                        <select name="site_id" id="site_id" class="filter-control">
                            <option value="">All Sites</option>
                            <?php if ($sites_result && $sites_result->num_rows > 0): ?>
                                <?php $sites_result->data_seek(0); ?>
                                <?php while ($site = $sites_result->fetch_assoc()): ?>
                                    <option value="<?php echo $site['id']; ?>" <?php echo $site_filter == $site['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($site['site_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group search-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" class="filter-control" placeholder="Search by name, position" value="<?php echo htmlspecialchars($search_term); ?>">
                        <i class="fas fa-search"></i>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="filter-btn primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" class="filter-btn secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        <!-- Generate Payroll Button moved inside filters container -->
                        <button type="button" onclick="openGeneratePayrollModal()" class="filter-btn primary">
                            <i class="fas fa-calculator"></i> Generate Payroll
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Payroll Table -->
        <div class="payroll-table-container">
            <div class="table-responsive">
                <table class="payroll-table">
                    <thead>
                        <tr>
                            <th>Employee Information</th>
                            <th>Site</th>
                            <th>Days Worked</th>
                            <th>Hours Worked</th>
                            <th>Gross Salary</th>
                            <th>Deductions</th>
                            <th>Net Salary</th>
                            <th>Pay Period</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="payrollTableBody">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $date_from = date('M d, Y', strtotime($row['date_from']));
                                $date_to = date('M d, Y', strtotime($row['date_to']));
                                $status = $row['status'] ?? 'pending';
                                
                                // Calculate days worked (difference between dates + 1)
                                $start = new DateTime($row['date_from']);
                                $end = new DateTime($row['date_to']);
                                $days_worked = $start->diff($end)->days + 1;
                                
                                // Set status class
                                if ($status == 'paid') {
                                    $statusClass = 'status-paid';
                                } elseif ($status == 'pending') {
                                    $statusClass = 'status-pending';
                                } else {
                                    $statusClass = 'status-unpaid';
                                }
                                
                                // Set status icon
                                if ($status == 'paid') {
                                    $statusIcon = 'fa-check-circle';
                                } elseif ($status == 'pending') {
                                    $statusIcon = 'fa-clock';
                                } else {
                                    $statusIcon = 'fa-exclamation-circle';
                                }
                            ?>
                                <tr class="payroll-row" data-id="<?php echo $row['id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($row['employee_name'])); ?>" data-position="<?php echo htmlspecialchars(strtolower($row['position'])); ?>" data-date-from="<?php echo $row['date_from']; ?>" data-date-to="<?php echo $row['date_to']; ?>">
                                    <td>
                                        <div class="employee-info-cell">
                                            <div class="employee-name"><?php echo htmlspecialchars($row['employee_name'] ?? 'Deleted Employee'); ?></div>
                                            <div class="employee-details">
                                                <span><i class="fas fa-id-badge"></i> ID: <?php echo $row['employee_id'] ?? 'N/A'; ?></span>
                                                <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($row['position'] ?? 'N/A'); ?></span>
                                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['contact_num'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['site_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo $days_worked; ?></td>
                                    <td><?php echo number_format(floatval($row['total_work_hours']), 2); ?></td>
                                    <td>₱<?php echo number_format(floatval($row['base_salary']), 2); ?></td>
                                    <td>₱<?php echo number_format(floatval($row['total_deductions']), 2); ?></td>
                                    <td class="net-pay-highlight">₱<?php echo number_format(floatval($row['net_pay']), 2); ?></td>
                                    <td>
                                        <div>
                                            <div><?php echo $date_from; ?></div>
                                            <div><?php echo $date_to; ?></div>
                                            <div class="date-range" style="font-size: 0.8rem; color: #64748b;">
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
                                            <button onclick="viewPayroll(<?php echo $row['id']; ?>)" class="action-btn view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="editPayroll(<?php echo $row['id']; ?>)" class="action-btn edit" title="Edit Payroll">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="openDeletePayrollModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['employee_name']); ?>', '<?php echo $date_from; ?>', '<?php echo $date_to; ?>', '₱<?php echo number_format(floatval($row['net_pay']), 2); ?>')" 
                                               class="action-btn delete" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <p>No payroll records found for the selected filters</p>
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

<!-- Generate Payroll Modal (Step 1 - Select Date and Employee) -->
<div id="generatePayrollModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header info">
            <i class="fas fa-calculator"></i>
            <h3 id="modalStepTitle">Generate Payroll - Step 1: Select Date and Employee</h3>
            <button class="close-btn" onclick="closeModal('generatePayrollModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="step-indicators">
                <div class="step-indicator active" id="step1Indicator">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Date & Employee</div>
                </div>
                <div class="step-indicator" id="step2Indicator">
                    <div class="step-number">2</div>
                    <div class="step-label">Review & Add Deductions</div>
                </div>
                <div class="step-indicator" id="step3Indicator">
                    <div class="step-number">3</div>
                    <div class="step-label">Save Payroll</div>
                </div>
            </div>

            <!-- Step 1 Content -->
            <div id="step1Content" class="payroll-step active">
                <div class="date-row">
                    <div class="form-group">
                        <label for="payroll_date_from">
                            <i class="fas fa-calendar-alt"></i> Date From
                        </label>
                        <div class="date-picker-wrapper">
                            <div class="date-input-group">
                                <input type="date" id="payroll_date_from" class="date-field" value="<?php echo date('Y-m-01'); ?>">
                                <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('date_from')">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="calendar-wrapper" id="calendar_date_from"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="payroll_date_to">
                            <i class="fas fa-calendar-alt"></i> Date To
                        </label>
                        <div class="date-picker-wrapper">
                            <div class="date-input-group">
                                <input type="date" id="payroll_date_to" class="date-field" value="<?php echo date('Y-m-t'); ?>">
                                <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('date_to')">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="calendar-wrapper" id="calendar_date_to"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Select Employee</label>
                    <div class="employee-search-container">
                        <input type="text" id="employeeSearch" class="employee-search-input" placeholder="Search employee by name or ID...">
                    </div>
                    
                    <!-- Employee Grid Display - Without Radio Buttons -->
                    <div class="employee-selection-grid" id="employeeGrid">
                        <?php if ($employees_result && $employees_result->num_rows > 0): ?>
                            <?php 
                            $employees_result->data_seek(0);
                            while ($emp = $employees_result->fetch_assoc()): 
                                $emp_type_class = $emp['employment_type'] == 'regular' ? 'regular' : 'non-regular';
                                $emp_type_label = $emp['employment_type'] == 'regular' ? 'Regular' : 'Non Regular';
                            ?>
                            <div class="employee-grid-item" onclick="selectEmployeeFromGrid(<?php echo $emp['id']; ?>)">
                                <div class="employee-grid-info">
                                    <div class="employee-grid-name">
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                        <span class="employment-type-badge <?php echo $emp_type_class; ?>">
                                            <?php echo $emp_type_label; ?>
                                        </span>
                                    </div>
                                    <div class="employee-grid-details">
                                        <span><i class="fas fa-id-badge"></i> <?php echo $emp['id']; ?></span>
                                        <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($emp['position']); ?></span>
                                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($emp['contact_num'] ?? 'N/A'); ?></span>
                                        <span><i class="fas fa-money-bill-wave"></i> ₱<?php echo number_format($emp['daily_salary'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="selectedEmployeeInfo" style="display: none;">
                    <div class="employee-details-card">
                        <button class="change-employee-btn" onclick="changeEmployee()" title="Change Employee">
                            <i class="fas fa-times"></i>
                        </button>
                        <h4><i class="fas fa-user-circle"></i> Selected Employee</h4>
                        <div class="employee-details-grid">
                            <div class="detail-item">
                                <div class="detail-label">Name</div>
                                <div class="detail-value" id="selectedEmpName"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">ID</div>
                                <div class="detail-value" id="selectedEmpId"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Position</div>
                                <div class="detail-value" id="selectedEmpPosition"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Contact</div>
                                <div class="detail-value" id="selectedEmpContact"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Address</div>
                                <div class="detail-value" id="selectedEmpAddress"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Daily Salary</div>
                                <div class="detail-value" id="selectedEmpSalary"></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Employment Type</div>
                                <div class="detail-value" id="selectedEmpType"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2 Content - Enhanced UI -->
            <div id="step2Content" class="payroll-step">
                <div id="payrollSummary"></div>
                
                <!-- Enhanced Deductions Section -->
                <div class="deductions-section-enhanced">
                    <div class="deductions-header-enhanced">
                        <h5>
                            <i class="fas fa-minus-circle"></i>
                            Deductions Applied
                        </h5>
                        <button class="add-deduction-btn-enhanced" onclick="openAddDeductionModal()">
                            <i class="fas fa-plus"></i> Add Deduction
                        </button>
                    </div>
                    <div class="deductions-list-enhanced" id="deductionsList">
                        <div class="empty-deductions-enhanced">
                            <i class="fas fa-receipt"></i>
                            <p>No deductions added yet</p>
                            <p style="font-size: 0.8rem; margin-top: 5px;">Click "Add Deduction" to include deductions</p>
                        </div>
                    </div>
                </div>
                
                <!-- Total Summary Card -->
                <div id="totalSummaryCard" class="total-summary-card" style="display: none;">
                    <div class="total-summary-label">
                        <i class="fas fa-calculator"></i> Net Pay Total
                    </div>
                    <div class="total-summary-value" id="totalNetPayDisplay">₱0.00</div>
                </div>
            </div>

            <!-- Step 3 Content -->
            <div id="step3Content" class="payroll-step">
                <div class="form-group">
                    <label><i class="fas fa-check-circle"></i> Review and Save</label>
                    <div style="background: #e6f7f5; padding: 25px; border-radius: 12px; text-align: center;">
                        <i class="fas fa-check-circle" style="font-size: 48px; color: #00838f; margin-bottom: 15px;"></i>
                        <h4 style="color: #006064; margin-bottom: 10px;">Ready to Save</h4>
                        <p style="color: #2c3e50;">Please review the payroll details above. Once saved, the payroll record will be added to the list.</p>
                    </div>
                </div>
            </div>

            <input type="hidden" id="currentEmployeeId">
            <input type="hidden" id="currentBaseSalary">
            <input type="hidden" id="currentTotalDeductions">
            <input type="hidden" id="currentNetPay">
            <input type="hidden" id="currentWorkHours">
        </div>
        <div class="modal-footer">
            <button class="modal-btn cancel" id="step1CancelBtn" onclick="closeModal('generatePayrollModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="modal-btn cancel" id="step2BackBtn" style="display: none;" onclick="goToStep(1)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button class="modal-btn cancel" id="step3BackBtn" style="display: none;" onclick="goToStep(2)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button class="modal-btn confirm" id="step1NextBtn" onclick="processPayrollStep1()">
                Next <i class="fas fa-arrow-right"></i>
            </button>
            <button class="modal-btn confirm" id="step2NextBtn" style="display: none;" onclick="goToStep(3)">
                Next <i class="fas fa-arrow-right"></i>
            </button>
            <button class="modal-btn primary" id="step3SaveBtn" style="display: none;" onclick="savePayroll()">
                <i class="fas fa-save"></i> Save Payroll
            </button>
        </div>
    </div>
</div>

<!-- Add Deduction Modal -->
<div id="addDeductionModal" class="modal">
    <div class="modal-content deduction-modal-content">
        <div class="modal-header primary">
            <i class="fas fa-plus-circle"></i>
            <h3>Add Custom Deduction</h3>
            <button class="close-btn" onclick="closeModal('addDeductionModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="deductionEmployeeId">
            
            <div class="deduction-form-group">
                <label><i class="fas fa-tag"></i> Deduction Name</label>
                <select id="deductionName" class="deduction-form-control">
                    <option value="">Select deduction type</option>
                    <option value="Tax">Tax (Withholding)</option>
                    <option value="SSS">SSS</option>
                    <option value="Pag-IBIG">Pag-IBIG</option>
                    <option value="PhilHealth">PhilHealth</option>
                    <option value="Cash Advance">Cash Advance</option>
                    <option value="Uniform">Uniform</option>
                    <option value="Other">Other (Custom)</option>
                </select>
            </div>
            
            <div class="deduction-form-group" id="customNameGroup" style="display: none;">
                <label><i class="fas fa-pencil-alt"></i> Custom Name</label>
                <input type="text" id="customDeductionName" class="deduction-form-control" placeholder="Enter deduction name">
            </div>
            
            <div class="deduction-form-group">
                <label><i class="fas fa-dollar-sign"></i> Amount</label>
                <input type="number" id="deductionAmount" class="deduction-form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            
            <div class="deduction-form-row">
                <div class="deduction-form-group">
                    <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="deductionStartDate" class="deduction-form-control">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('deduction_start')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_deduction_start"></div>
                    </div>
                </div>
                <div class="deduction-form-group">
                    <label><i class="fas fa-calendar-alt"></i> End Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="deductionEndDate" class="deduction-form-control">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('deduction_end')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_deduction_end"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeModal('addDeductionModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="modal-btn confirm" onclick="addDeduction()">
                <i class="fas fa-plus"></i> Add Deduction
            </button>
        </div>
    </div>
</div>

<!-- Edit Payroll Modal - Enhanced to match Generate Payroll UI -->
<div id="editPayrollModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header warning">
            <i class="fas fa-edit"></i>
            <h3>Edit Payroll</h3>
            <button class="close-btn" onclick="closeModal('editPayrollModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit_payroll_id">
            <input type="hidden" id="edit_employee_id">
            <input type="hidden" id="edit_original_base_salary">
            <input type="hidden" id="edit_original_work_hours">
            <input type="hidden" id="edit_original_date_from">
            <input type="hidden" id="edit_original_date_to">
            <input type="hidden" id="edit_original_status">
            
            <div class="form-group">
                <label><i class="fas fa-user"></i> Employee</label>
                <input type="text" id="edit_employee_name" class="form-control" readonly>
            </div>
            
            <div class="date-row">
                <div class="form-group">
                    <label for="edit_date_from">
                        <i class="fas fa-calendar-alt"></i> Date From
                    </label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="edit_date_from" class="date-field" onchange="recalculateEditPayroll()">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('edit_from')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_edit_from"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_date_to">
                        <i class="fas fa-calendar-alt"></i> Date To
                    </label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="edit_date_to" class="date-field" onchange="recalculateEditPayroll()">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('edit_to')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_edit_to"></div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Payroll Summary Section -->
            <div id="editPayrollSummary" class="payroll-summary-container" style="margin-top: 0;">
                <!-- Content will be populated dynamically -->
            </div>
            
            <!-- Deductions Section Enhanced -->
            <div class="deductions-section-enhanced">
                <div class="deductions-header-enhanced">
                    <h5>
                        <i class="fas fa-minus-circle"></i>
                        Deductions
                    </h5>
                    <button class="add-deduction-btn-enhanced" onclick="openEditAddDeductionModal()">
                        <i class="fas fa-plus"></i> Add Deduction
                    </button>
                </div>
                <div class="deductions-list-enhanced" id="editDeductionsList">
                    <!-- Deductions will be populated dynamically -->
                </div>
            </div>
            
            <!-- Total Summary Card for Edit -->
            <div id="editTotalSummaryCard" class="total-summary-card" style="margin-top: 20px; display: none;">
                <div class="total-summary-label">
                    <i class="fas fa-calculator"></i> Net Pay Total
                </div>
                <div class="total-summary-value" id="editTotalNetPayDisplay">₱0.00</div>
            </div>
            
            <div class="form-group" style="margin-top: 20px;">
                <label for="edit_status">
                    <i class="fas fa-tag"></i> Status
                </label>
                <select id="edit_status" class="form-control">
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeModal('editPayrollModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="modal-btn edit" onclick="saveEditedPayroll()">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- View Payroll Modal - Enhanced to match Generate Payroll UI -->
<div id="viewPayrollModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header info">
            <i class="fas fa-eye"></i>
            <h3>Payroll Details</h3>
            <button class="close-btn" onclick="closeModal('viewPayrollModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewPayrollContent">
            <!-- Content will be populated dynamically with enhanced UI -->
        </div>
        <div class="modal-footer">
            <button class="modal-btn confirm" onclick="closeModal('viewPayrollModal')">
                <i class="fas fa-check"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Delete Payroll Confirmation Modal -->
<div id="deletePayrollModal" class="modal">
    <div class="modal-content">
        <div class="modal-header danger">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Delete Payroll Record</h3>
            <button class="close-btn" onclick="closeModal('deletePayrollModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="delete-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <div class="delete-message">
                Are you sure you want to delete this payroll record?
            </div>
            <div class="delete-details" id="deletePayrollDetails">
                <!-- Payroll details will be displayed here -->
            </div>
            <div class="delete-warning">
                <i class="fas fa-info-circle"></i> This action cannot be undone.
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeModal('deletePayrollModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="modal-btn delete" onclick="confirmDeletePayroll()">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- Generate Payroll Success Modal -->
<div id="generateSuccessModal" class="modal">
    <div class="modal-content">
        <div class="modal-header success">
            <i class="fas fa-check-circle"></i>
            <h3>Payroll Generated Successfully</h3>
            <button class="close-btn" onclick="closeModal('generateSuccessModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p id="successMessage" class="success-message"></p>
        </div>
        <div class="modal-footer">
            <button class="modal-btn confirm" onclick="closeModalAndRefresh()">
                <i class="fas fa-check"></i> OK
            </button>
        </div>
    </div>
</div>

<!-- Existing Payroll Warning Modal -->
<div id="existingPayrollModal" class="modal">
    <div class="modal-content">
        <div class="modal-header warning">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Payroll Already Exists</h3>
            <button class="close-btn" onclick="closeModal('existingPayrollModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="warning-icon">
                <i class="fas fa-clock"></i>
            </div>
            <p style="text-align: center; font-size: 16px; margin-bottom: 20px;">The payroll is existing.</p>
            <p style="text-align: center; color: #666;">A payroll record already exists for this employee and date range.</p>
        </div>
        <div class="modal-footer">
            <button class="modal-btn confirm" onclick="closeModal('existingPayrollModal')">
                <i class="fas fa-check"></i> OK
            </button>
        </div>
    </div>
</div>

<!-- Edit Add Deduction Modal -->
<div id="editAddDeductionModal" class="modal">
    <div class="modal-content deduction-modal-content">
        <div class="modal-header primary">
            <i class="fas fa-plus-circle"></i>
            <h3>Add Deduction to Edit</h3>
            <button class="close-btn" onclick="closeModal('editAddDeductionModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editDeductionEmployeeId">
            
            <div class="deduction-form-group">
                <label><i class="fas fa-tag"></i> Deduction Name</label>
                <select id="editDeductionName" class="deduction-form-control">
                    <option value="">Select deduction type</option>
                    <option value="Tax">Tax (Withholding)</option>
                    <option value="SSS">SSS</option>
                    <option value="Pag-IBIG">Pag-IBIG</option>
                    <option value="PhilHealth">PhilHealth</option>
                    <option value="Cash Advance">Cash Advance</option>
                    <option value="Uniform">Uniform</option>
                    <option value="Other">Other (Custom)</option>
                </select>
            </div>
            
            <div class="deduction-form-group" id="editCustomNameGroup" style="display: none;">
                <label><i class="fas fa-pencil-alt"></i> Custom Name</label>
                <input type="text" id="editCustomDeductionName" class="deduction-form-control" placeholder="Enter deduction name">
            </div>
            
            <div class="deduction-form-group">
                <label><i class="fas fa-dollar-sign"></i> Amount</label>
                <input type="number" id="editDeductionAmount" class="deduction-form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            
            <div class="deduction-form-row">
                <div class="deduction-form-group">
                    <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="editDeductionStartDate" class="deduction-form-control">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('edit_deduction_start')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_edit_deduction_start"></div>
                    </div>
                </div>
                <div class="deduction-form-group">
                    <label><i class="fas fa-calendar-alt"></i> End Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="date" id="editDeductionEndDate" class="deduction-form-control">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('edit_deduction_end')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="calendar-wrapper" id="calendar_edit_deduction_end"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeModal('editAddDeductionModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="modal-btn confirm" onclick="addEditDeduction()">
                <i class="fas fa-plus"></i> Add Deduction
            </button>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div id="notificationModal" class="notification-modal">
    <div class="notification-modal-content">
        <div class="notification-modal-header" id="notificationHeader">
            <i class="fas" id="notificationIcon"></i>
            <h3 id="notificationTitle"></h3>
            <button class="close-btn" onclick="closeNotificationModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="notification-modal-body">
            <p id="notificationMessage"></p>
        </div>
        <div class="notification-modal-footer">
            <button class="notification-modal-btn" id="notificationBtn" onclick="closeNotificationModal()">OK</button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <p>Processing...</p>
</div>

<?php include_once("./modal/logout-modal.php"); ?>
<?php include_once("./includes/footer.php"); ?>

<script>
    // Global variables
    let employees = [];
    let selectedEmployee = null;
    let currentPayrollData = null;
    let currentDeductions = [];
    let currentStep = 1;
    let currentDeletePayrollId = null;
    let editPayrollId = null;
    let editEmployeeId = null;
    let editDeductions = [];
    let editOriginalData = null;
    
    // Calendar variables
    let activeCalendar = null;
    let calendarDates = {
        date_from: { currentDate: new Date(), selectedDate: '<?php echo date('Y-m-01'); ?>' },
        date_to: { currentDate: new Date(), selectedDate: '<?php echo date('Y-m-t'); ?>' },
        deduction_start: { currentDate: new Date(), selectedDate: null },
        deduction_end: { currentDate: new Date(), selectedDate: null },
        edit_from: { currentDate: new Date(), selectedDate: null },
        edit_to: { currentDate: new Date(), selectedDate: null },
        edit_deduction_start: { currentDate: new Date(), selectedDate: null },
        edit_deduction_end: { currentDate: new Date(), selectedDate: null }
    };

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        loadEmployees();
        initializeAllCalendars();
        
        // Set min dates for date inputs
        const dateFrom = document.getElementById('payroll_date_from');
        const dateTo = document.getElementById('payroll_date_to');
        
        if (dateFrom) {
            dateFrom.addEventListener('change', function() {
                dateTo.min = this.value;
                if (dateTo.value < this.value) {
                    dateTo.value = this.value;
                }
            });
        }

        // Modal employee search
        const modalSearchInput = document.getElementById('employeeSearch');
        if (modalSearchInput) {
            modalSearchInput.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                filterEmployeeGrid(query);
            });
        }
        
        // Close calendar when clicking outside
        document.addEventListener('click', function(e) {
            if (activeCalendar && !e.target.closest('.date-picker-wrapper')) {
                document.getElementById(activeCalendar).style.display = 'none';
                activeCalendar = null;
            }
        });
    });

    // Format date to Month Day, Year
    function formatDateToLong(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    // Override the selectCalendarDate function to format dates
    function selectCalendarDate(calendarId, dateStr) {
        calendarDates[calendarId].selectedDate = dateStr;
        
        let inputId = '';
        switch(calendarId) {
            case 'date_from':
                inputId = 'payroll_date_from';
                break;
            case 'date_to':
                inputId = 'payroll_date_to';
                break;
            case 'deduction_start':
                inputId = 'deductionStartDate';
                break;
            case 'deduction_end':
                inputId = 'deductionEndDate';
                break;
            case 'edit_from':
                inputId = 'edit_date_from';
                break;
            case 'edit_to':
                inputId = 'edit_date_to';
                break;
            case 'edit_deduction_start':
                inputId = 'editDeductionStartDate';
                break;
            case 'edit_deduction_end':
                inputId = 'editDeductionEndDate';
                break;
        }
        
        if (inputId) {
            // Set the value in YYYY-MM-DD format for the input
            document.getElementById(inputId).value = dateStr;
        }
        
        document.getElementById(`calendar_${calendarId}`).style.display = 'none';
        activeCalendar = null;
        
        if (calendarId === 'date_from') {
            document.getElementById('payroll_date_to').min = dateStr;
        } else if (calendarId === 'edit_from') {
            document.getElementById('edit_date_to').min = dateStr;
            recalculateEditPayroll();
        } else if (calendarId === 'edit_to') {
            recalculateEditPayroll();
        }
    }

    // Reset filters function
    function resetFilters() {
        document.getElementById('month').value = '<?php echo date('m'); ?>';
        document.getElementById('year').value = '<?php echo date('Y'); ?>';
        document.getElementById('employee_id').value = '';
        document.getElementById('site_id').value = '';
        document.getElementById('search').value = '';
        document.getElementById('filterForm').submit();
    }

    // Filter employee grid in modal
    function filterEmployeeGrid(query) {
        const gridItems = document.querySelectorAll('.employee-grid-item');
        let visibleCount = 0;
        
        gridItems.forEach(item => {
            const name = item.querySelector('.employee-grid-name').textContent.toLowerCase();
            const details = item.querySelector('.employee-grid-details').textContent.toLowerCase();
            
            if (name.includes(query) || details.includes(query) || query === '') {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Initialize all calendars
    function initializeAllCalendars() {
        const calendarIds = ['date_from', 'date_to', 'deduction_start', 'deduction_end', 'edit_from', 'edit_to', 'edit_deduction_start', 'edit_deduction_end'];
        calendarIds.forEach(id => {
            const calendarElement = document.getElementById(`calendar_${id}`);
            if (calendarElement) {
                generateCalendarHTML(id, calendarElement);
            }
        });
    }

    // Generate calendar HTML
    function generateCalendarHTML(calendarId, container) {
        const date = calendarDates[calendarId].currentDate || new Date();
        const year = date.getFullYear();
        const month = date.getMonth();
        const selectedDate = calendarDates[calendarId].selectedDate;
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        
        let html = `
            <div class="calendar-box">
                <div class="calendar-header">
                    <div class="calendar-month-year">${date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</div>
                    <div class="calendar-nav">
                        <button type="button" class="calendar-nav-btn" onclick="navigateCalendar('${calendarId}', -1)">‹</button>
                        <button type="button" class="calendar-nav-btn" onclick="navigateCalendar('${calendarId}', 1)">›</button>
                    </div>
                </div>
                
                <div class="calendar-selectors">
                    <select id="monthSelect_${calendarId}" class="calendar-select" onchange="changeCalendarMonthYear('${calendarId}')">
                        <option value="0" ${month === 0 ? 'selected' : ''}>January</option>
                        <option value="1" ${month === 1 ? 'selected' : ''}>February</option>
                        <option value="2" ${month === 2 ? 'selected' : ''}>March</option>
                        <option value="3" ${month === 3 ? 'selected' : ''}>April</option>
                        <option value="4" ${month === 4 ? 'selected' : ''}>May</option>
                        <option value="5" ${month === 5 ? 'selected' : ''}>June</option>
                        <option value="6" ${month === 6 ? 'selected' : ''}>July</option>
                        <option value="7" ${month === 7 ? 'selected' : ''}>August</option>
                        <option value="8" ${month === 8 ? 'selected' : ''}>September</option>
                        <option value="9" ${month === 9 ? 'selected' : ''}>October</option>
                        <option value="10" ${month === 10 ? 'selected' : ''}>November</option>
                        <option value="11" ${month === 11 ? 'selected' : ''}>December</option>
                    </select>
                    
                    <select id="yearSelect_${calendarId}" class="calendar-select" onchange="changeCalendarMonthYear('${calendarId}')">
        `;
        
        for (let y = year - 10; y <= year + 10; y++) {
            html += `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`;
        }
        
        html += `
                    </select>
                </div>
                
                <div class="calendar-weekdays">
                    <div>Su</div>
                    <div>Mo</div>
                    <div>Tu</div>
                    <div>We</div>
                    <div>Th</div>
                    <div>Fr</div>
                    <div>Sa</div>
                </div>
                
                <div class="calendar-days-grid">
        `;
        
        // Previous month days
        const prevMonthDays = new Date(year, month, 0).getDate();
        for (let i = firstDay - 1; i >= 0; i--) {
            const day = prevMonthDays - i;
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            html += `<div class="calendar-day other-month" onclick="selectCalendarDate('${calendarId}', '${dateStr}')">${day}</div>`;
        }
        
        // Current month days
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
            const isSelected = selectedDate === dateStr;
            const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
            
            let classes = 'calendar-day';
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
            html += `<div class="calendar-day other-month" onclick="selectCalendarDate('${calendarId}', '${dateStr}')">${day}</div>`;
        }
        
        html += `
                </div>
                
                <div class="calendar-footer">
                    <button type="button" class="calendar-action-btn clear" onclick="clearCalendarDate('${calendarId}')">
                        <i class="fas fa-times"></i> Clear
                    </button>
                    <button type="button" class="calendar-action-btn today" onclick="setCalendarToday('${calendarId}')">
                        <i class="fas fa-calendar-check"></i> Today
                    </button>
                </div>
            </div>
        `;
        
        container.innerHTML = html;
    }

    // Toggle calendar
    function toggleCalendar(calendarId) {
        const calendar = document.getElementById(`calendar_${calendarId}`);
        if (calendar) {
            if (calendar.style.display === 'block') {
                calendar.style.display = 'none';
                activeCalendar = null;
            } else {
                document.querySelectorAll('.calendar-wrapper').forEach(cal => {
                    cal.style.display = 'none';
                });
                calendar.style.display = 'block';
                activeCalendar = `calendar_${calendarId}`;
                generateCalendarHTML(calendarId, calendar);
            }
        }
    }

    // Navigate calendar month
    function navigateCalendar(calendarId, direction) {
        const date = calendarDates[calendarId].currentDate;
        date.setMonth(date.getMonth() + direction);
        const calendar = document.getElementById(`calendar_${calendarId}`);
        generateCalendarHTML(calendarId, calendar);
    }

    // Change calendar month/year
    function changeCalendarMonthYear(calendarId) {
        const monthSelect = document.getElementById(`monthSelect_${calendarId}`);
        const yearSelect = document.getElementById(`yearSelect_${calendarId}`);
        
        if (monthSelect && yearSelect) {
            const newMonth = parseInt(monthSelect.value);
            const newYear = parseInt(yearSelect.value);
            
            calendarDates[calendarId].currentDate = new Date(newYear, newMonth, 1);
            const calendar = document.getElementById(`calendar_${calendarId}`);
            generateCalendarHTML(calendarId, calendar);
        }
    }

    // Clear calendar date
    function clearCalendarDate(calendarId) {
        calendarDates[calendarId].selectedDate = null;
        
        let inputId = '';
        switch(calendarId) {
            case 'date_from':
                inputId = 'payroll_date_from';
                break;
            case 'date_to':
                inputId = 'payroll_date_to';
                break;
            case 'deduction_start':
                inputId = 'deductionStartDate';
                break;
            case 'deduction_end':
                inputId = 'deductionEndDate';
                break;
            case 'edit_from':
                inputId = 'edit_date_from';
                break;
            case 'edit_to':
                inputId = 'edit_date_to';
                break;
            case 'edit_deduction_start':
                inputId = 'editDeductionStartDate';
                break;
            case 'edit_deduction_end':
                inputId = 'editDeductionEndDate';
                break;
        }
        
        if (inputId) {
            document.getElementById(inputId).value = '';
        }
        
        const calendar = document.getElementById(`calendar_${calendarId}`);
        generateCalendarHTML(calendarId, calendar);
    }

    // Set calendar to today
    function setCalendarToday(calendarId) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;
        
        calendarDates[calendarId].currentDate = new Date(dateStr);
        selectCalendarDate(calendarId, dateStr);
    }

    // Load employees from PHP
    function loadEmployees() {
        <?php if ($employees_result && $employees_result->num_rows > 0): ?>
            <?php 
            $employees_result->data_seek(0);
            while ($emp = $employees_result->fetch_assoc()): 
            ?>
            employees.push({
                id: <?php echo $emp['id']; ?>,
                name: '<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'], ENT_QUOTES); ?>',
                first_name: '<?php echo htmlspecialchars($emp['first_name'], ENT_QUOTES); ?>',
                last_name: '<?php echo htmlspecialchars($emp['last_name'], ENT_QUOTES); ?>',
                position: '<?php echo htmlspecialchars($emp['position'], ENT_QUOTES); ?>',
                address: '<?php echo htmlspecialchars($emp['address'], ENT_QUOTES); ?>',
                contact: '<?php echo htmlspecialchars($emp['contact_num'] ?? '', ENT_QUOTES); ?>',
                daily_salary: <?php echo floatval($emp['daily_salary']); ?>,
                employment_type: '<?php echo $emp['employment_type']; ?>'
            });
            <?php endwhile; ?>
        <?php endif; ?>
    }

    // Select employee from grid
    function selectEmployeeFromGrid(employeeId) {
        const employee = employees.find(e => e.id === employeeId);
        if (!employee) return;
        
        // Remove selected class from all grid items
        document.querySelectorAll('.employee-grid-item').forEach(item => {
            item.classList.remove('selected');
        });
        
        // Add selected class to clicked item
        const selectedItem = Array.from(document.querySelectorAll('.employee-grid-item')).find(
            item => item.querySelector('.employee-grid-name').textContent.includes(employee.name)
        );
        if (selectedItem) {
            selectedItem.classList.add('selected');
        }
        
        // Update selected display
        selectedEmployee = employee;
        document.getElementById('selectedEmployeeInfo').style.display = 'block';
        document.getElementById('selectedEmpName').textContent = employee.name;
        document.getElementById('selectedEmpId').textContent = employee.id;
        document.getElementById('selectedEmpPosition').textContent = employee.position;
        document.getElementById('selectedEmpContact').textContent = employee.contact || 'N/A';
        document.getElementById('selectedEmpAddress').textContent = employee.address || 'N/A';
        document.getElementById('selectedEmpSalary').textContent = '₱' + parseFloat(employee.daily_salary).toFixed(2);
        document.getElementById('selectedEmpType').textContent = employee.employment_type === 'regular' ? 'Regular' : 'Non Regular';
        document.getElementById('currentEmployeeId').value = employee.id;
        document.getElementById('employeeSearch').value = employee.name;
    }

    // Change employee
    function changeEmployee() {
        selectedEmployee = null;
        document.getElementById('selectedEmployeeInfo').style.display = 'none';
        document.getElementById('employeeSearch').value = '';
        document.getElementById('currentEmployeeId').value = '';
        
        // Remove selected class from all grid items
        document.querySelectorAll('.employee-grid-item').forEach(item => {
            item.classList.remove('selected');
        });
    }

    // Modal functions
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('show');
        document.body.classList.add('modal-open');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
        document.body.classList.remove('modal-open');
        
        if (modalId === 'generatePayrollModal') {
            resetPayrollModal();
        } else if (modalId === 'addDeductionModal') {
            resetDeductionModal();
        } else if (modalId === 'editAddDeductionModal') {
            resetEditDeductionModal();
        }
        
        document.querySelectorAll('.calendar-wrapper').forEach(cal => {
            cal.style.display = 'none';
        });
        activeCalendar = null;
    }

    function closeModalAndRefresh() {
        closeModal('generateSuccessModal');
        location.reload();
    }

    // Notification modal functions
    function showNotificationModal(message, type = 'info') {
        const modal = document.getElementById('notificationModal');
        const header = document.getElementById('notificationHeader');
        const icon = document.getElementById('notificationIcon');
        const title = document.getElementById('notificationTitle');
        const messageEl = document.getElementById('notificationMessage');
        const btn = document.getElementById('notificationBtn');
        
        header.className = 'notification-modal-header ' + type;
        btn.className = 'notification-modal-btn ' + type;
        
        if (type === 'success') {
            icon.className = 'fas fa-check-circle';
            title.textContent = 'Success';
        } else if (type === 'error') {
            icon.className = 'fas fa-exclamation-circle';
            title.textContent = 'Error';
        } else if (type === 'warning') {
            icon.className = 'fas fa-exclamation-triangle';
            title.textContent = 'Warning';
        } else {
            icon.className = 'fas fa-info-circle';
            title.textContent = 'Information';
        }
        
        messageEl.textContent = message;
        modal.classList.add('show');
    }

    function closeNotificationModal() {
        document.getElementById('notificationModal').classList.remove('show');
    }

    // Open generate payroll modal
    function openGeneratePayrollModal() {
        resetPayrollModal();
        goToStep(1);
        openModal('generatePayrollModal');
    }

    // Reset payroll modal
    function resetPayrollModal() {
        currentStep = 1;
        currentPayrollData = null;
        currentDeductions = [];
        selectedEmployee = null;
        
        document.getElementById('selectedEmployeeInfo').style.display = 'none';
        document.getElementById('employeeSearch').value = '';
        document.getElementById('payroll_date_from').value = '<?php echo date('Y-m-01'); ?>';
        document.getElementById('payroll_date_to').value = '<?php echo date('Y-m-t'); ?>';
        document.getElementById('currentEmployeeId').value = '';
        
        // Remove selected class from all grid items
        document.querySelectorAll('.employee-grid-item').forEach(item => {
            item.classList.remove('selected');
        });
        
        calendarDates.date_from.selectedDate = '<?php echo date('Y-m-01'); ?>';
        calendarDates.date_to.selectedDate = '<?php echo date('Y-m-t'); ?>';
        
        updateStepIndicators(1);
        document.getElementById('modalStepTitle').textContent = 'Generate Payroll - Step 1: Select Date and Employee';
    }

    // Go to step
    function goToStep(step) {
        currentStep = step;
        updateStepIndicators(step);
        
        document.getElementById('step1Content').classList.toggle('active', step === 1);
        document.getElementById('step2Content').classList.toggle('active', step === 2);
        document.getElementById('step3Content').classList.toggle('active', step === 3);
        
        document.getElementById('step1CancelBtn').style.display = step === 1 ? 'inline-block' : 'none';
        document.getElementById('step2BackBtn').style.display = step === 2 ? 'inline-block' : 'none';
        document.getElementById('step3BackBtn').style.display = step === 3 ? 'inline-block' : 'none';
        document.getElementById('step1NextBtn').style.display = step === 1 ? 'inline-block' : 'none';
        document.getElementById('step2NextBtn').style.display = step === 2 ? 'inline-block' : 'none';
        document.getElementById('step3SaveBtn').style.display = step === 3 ? 'inline-block' : 'none';
        
        // Update modal title
        const titles = [
            'Generate Payroll - Step 1: Select Date and Employee',
            'Generate Payroll - Step 2: Review and Add Deductions',
            'Generate Payroll - Step 3: Save Payroll'
        ];
        document.getElementById('modalStepTitle').textContent = titles[step - 1];
        
        // If going to step 2, update total summary card
        if (step === 2 && currentPayrollData) {
            updateTotalSummaryCard();
        }
    }

    // Update step indicators
    function updateStepIndicators(activeStep) {
        const step1 = document.getElementById('step1Indicator');
        const step2 = document.getElementById('step2Indicator');
        const step3 = document.getElementById('step3Indicator');
        
        step1.classList.toggle('active', activeStep === 1);
        step2.classList.toggle('active', activeStep === 2);
        step3.classList.toggle('active', activeStep === 3);
        
        step1.classList.toggle('completed', activeStep > 1);
        step2.classList.toggle('completed', activeStep > 2);
        step3.classList.toggle('completed', false);
    }

    // Update total summary card
    function updateTotalSummaryCard() {
        if (!currentPayrollData) return;
        
        const totalNetPay = parseFloat(currentPayrollData.net_pay || 0);
        document.getElementById('totalNetPayDisplay').textContent = '₱' + totalNetPay.toFixed(2);
        document.getElementById('totalSummaryCard').style.display = 'flex';
    }

    // Process payroll step 1
    async function processPayrollStep1() {
        const employeeId = document.getElementById('currentEmployeeId').value;
        const dateFrom = document.getElementById('payroll_date_from').value;
        const dateTo = document.getElementById('payroll_date_to').value;
        
        if (!employeeId) {
            showNotificationModal('Please select an employee', 'error');
            return;
        }
        
        if (!dateFrom || !dateTo) {
            showNotificationModal('Please select date range', 'error');
            return;
        }
        
        showLoading();
        
        try {
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'generate_payroll_ajax=1&date_from=' + encodeURIComponent(dateFrom) + 
                      '&date_to=' + encodeURIComponent(dateTo) + 
                      '&employee_id=' + encodeURIComponent(employeeId)
            });
            
            const text = await response.text();
            
            try {
                const data = JSON.parse(text);
                
                if (data.success) {
                    currentPayrollData = data.payroll;
                    currentDeductions = data.payroll.deductions || [];
                    displayPayrollSummary(data.payroll);
                    goToStep(2);
                    hideLoading();
                } else if (data.exists) {
                    // Show existing payroll modal
                    hideLoading();
                    openModal('existingPayrollModal');
                } else {
                    showNotificationModal(data.message, 'error');
                    hideLoading();
                }
            } catch (e) {
                console.error('Failed to parse JSON:', text);
                showNotificationModal('Server returned invalid response. Check console for details.', 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error processing payroll: ' + error.message, 'error');
            hideLoading();
        }
    }

    // Display payroll summary (Enhanced UI) - MODIFIED TO SHOW SEPARATE ROWS FOR DAY OVERTIME, REGULAR NIGHT, AND NIGHT OVERTIME
    function displayPayrollSummary(data) {
        const container = document.getElementById('payrollSummary');
        const emp = data.employee;
        
        // Format dates to Month Day, Year format
        const dateFrom = formatDateToLong(data.date_from);
        const dateTo = formatDateToLong(data.date_to);
        
        // Build workday type summary table HTML - ONLY SHOW WORKDAY TYPES THAT HAVE RECORDS
        let workdaySummaryHtml = '';
        if (data.workday_type_summary && Object.keys(data.workday_type_summary).length > 0) {
            workdaySummaryHtml = `
                <div class="workday-summary">
                    <div class="workday-header">
                        <i class="fas fa-calendar-alt"></i>
                        Attendance Summary by Workday Type
                    </div>
                    <table class="workday-table">
                        <thead>
                            <tr>
                                <th>Workday Type</th>
                                <th>Total Days</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>On Leave</th>
                                <th>Total Hours</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            // Define preferred order for workday types
            const workdayOrder = [
                'Ordinary Working Day',
                'Rest Day / Sunday',
                'Special (Non-Working) Day',
                'Special Day that falls on Rest Day',
                'Regular Holiday',
                'Regular Holiday on the Rest Day',
                'Double Holiday',
                'Double Holiday on the Rest Day'
            ];
            
            // Get all workday types that exist in the data
            const existingTypes = Object.keys(data.workday_type_summary);
            
            // Sort them according to the preferred order
            const sortedTypes = workdayOrder.filter(type => existingTypes.includes(type));
            
            // Add any types that might not be in the predefined order (if any)
            existingTypes.forEach(type => {
                if (!workdayOrder.includes(type)) {
                    sortedTypes.push(type);
                }
            });
            
            sortedTypes.forEach(type => {
                const summary = data.workday_type_summary[type];
                workdaySummaryHtml += `
                    <tr>
                        <td class="workday-type-name">${type}</td>
                        <td class="workday-count">${summary.count}</td>
                        <td class="workday-present">${summary.present_count}</td>
                        <td class="workday-absent">${summary.absent_count}</td>
                        <td class="workday-leave">${summary.leave_count}</td>
                        <td>${summary.total_hours.toFixed(2)}</td>
                    </tr>
                `;
            });
            
            workdaySummaryHtml += `
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        // Build deductions HTML for salary breakdown
        let deductionsBreakdownHtml = '';
        if (data.deductions && data.deductions.length > 0) {
            data.deductions.forEach(d => {
                deductionsBreakdownHtml += `
                    <div class="breakdown-row total-deductions">
                        <div class="breakdown-label">
                            <i class="fas fa-minus-circle"></i>
                            ${d.deduction_name}
                        </div>
                        <div class="breakdown-value">- ₱${parseFloat(d.amount).toFixed(2)}</div>
                    </div>
                `;
            });
        }
        
        // Add holiday guaranteed pay if any
        let holidayGuaranteedHtml = '';
        if (data.holiday_guaranteed_pay > 0) {
            holidayGuaranteedHtml = `
                <div class="breakdown-row">
                    <div class="breakdown-label">
                        <i class="fas fa-gift"></i>
                        Holiday Guaranteed Pay (Absent on Paid Holidays)
                    </div>
                    <div class="breakdown-value">₱${parseFloat(data.holiday_guaranteed_pay).toFixed(2)}</div>
                </div>
            `;
        }
        
        // Add day overtime row (separate from night overtime)
        let dayOvertimeHtml = '';
        if (data.overtime_hours && data.overtime_hours > 0) {
            dayOvertimeHtml = `
                <div class="breakdown-row">
                    <div class="breakdown-label">
                        <i class="fas fa-clock"></i>
                        Day Overtime Hours
                        <span class="badge">${parseFloat(data.overtime_hours).toFixed(2)} hrs</span>
                    </div>
                    <div class="breakdown-value highlight">₱${parseFloat(data.overtime_pay).toFixed(2)}</div>
                </div>
            `;
        }
        
        // Add regular night hours row
        let regularNightHtml = '';
        if (data.regular_night_hours && data.regular_night_hours > 0) {
            regularNightHtml = `
                <div class="breakdown-row">
                    <div class="breakdown-label">
                        <i class="fas fa-moon"></i>
                        Regular Night Hours
                        <span class="badge">${parseFloat(data.regular_night_hours).toFixed(2)} hrs</span>
                    </div>
                    <div class="breakdown-value highlight">₱${parseFloat(data.regular_night_pay).toFixed(2)}</div>
                </div>
            `;
        }
        
        // Add night overtime row
        let nightOvertimeHtml = '';
        if (data.night_shift_overtime_hours && data.night_shift_overtime_hours > 0) {
            nightOvertimeHtml = `
                <div class="breakdown-row">
                    <div class="breakdown-label">
                        <i class="fas fa-moon"></i>
                        Night Overtime Hours
                        <span class="badge">${parseFloat(data.night_shift_overtime_hours).toFixed(2)} hrs</span>
                    </div>
                    <div class="breakdown-value highlight">₱${parseFloat(data.night_shift_overtime_pay).toFixed(2)}</div>
                </div>
            `;
        }
        
        const html = `
            <div class="payroll-summary-container">
                <div class="summary-header">
                    <h4>
                        <i class="fas fa-file-invoice"></i>
                        Payroll Summary
                    </h4>
                    <div class="employee-badge">
                        <i class="fas fa-user-circle"></i>
                        ${emp.first_name} ${emp.last_name}
                        <span class="employment-type-badge ${emp.employment_type === 'regular' ? 'regular' : 'non-regular'}">
                            ${emp.employment_type === 'regular' ? 'Regular' : 'Non Regular'}
                        </span>
                    </div>
                </div>
                
                <!-- Period Info -->
                <div style="background: #e6f7f5; padding: 10px 15px; border-radius: 40px; margin-bottom: 20px; display: inline-block;">
                    <i class="fas fa-calendar-alt" style="color: #00838f; margin-right: 8px;"></i>
                    <span style="font-weight: 600; color: #006064;">${dateFrom} - ${dateTo}</span>
                </div>
                
                <!-- Attendance Stats Cards -->
                <div class="attendance-stats">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stat-value">${data.total_days}</div>
                        <div class="stat-label">Total Days</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value">${data.days_present}</div>
                        <div class="stat-label">Present</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                        <div class="stat-value">${data.days_on_leave}</div>
                        <div class="stat-label">On Leave</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-value">${parseFloat(data.total_work_hours).toFixed(1)}</div>
                        <div class="stat-label">Work Hours</div>
                    </div>
                </div>
                
                <!-- Workday Type Summary -->
                ${workdaySummaryHtml}
                
                <!-- Salary Breakdown -->
                <div class="salary-breakdown">
                    <div class="breakdown-header">
                        <i class="fas fa-coins"></i>
                        Salary Computation
                    </div>
                    
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-clock"></i>
                            Total Work Hours
                            <span class="badge">${parseFloat(data.total_work_hours).toFixed(2)} hrs</span>
                        </div>
                        <div class="breakdown-value">-</div>
                    </div>
                    
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-clock"></i>
                            Regular Day Hours
                            <span class="badge">${parseFloat(data.regular_hours).toFixed(2)} hrs</span>
                        </div>
                        <div class="breakdown-value">₱${parseFloat(data.regular_pay).toFixed(2)}</div>
                    </div>
                    
                    <!-- Day Overtime Row (Separate) -->
                    ${dayOvertimeHtml}
                    
                    <!-- Regular Night Row -->
                    ${regularNightHtml}
                    
                    <!-- Night Overtime Row -->
                    ${nightOvertimeHtml}
                    
                    ${holidayGuaranteedHtml}
                    
                    ${deductionsBreakdownHtml}
                    
                    <div class="breakdown-row total-deductions">
                        <div class="breakdown-label">
                            <i class="fas fa-calculator"></i>
                            Total Deductions
                        </div>
                        <div class="breakdown-value">- ₱${parseFloat(data.total_deductions).toFixed(2)}</div>
                    </div>
                    
                    <div class="breakdown-row net-pay">
                        <div class="breakdown-label">
                            <i class="fas fa-check-circle"></i>
                            Net Pay
                        </div>
                        <div class="breakdown-value">₱${parseFloat(data.net_pay).toFixed(2)}</div>
                    </div>
                </div>
                
                <!-- Hourly Rate Info -->
                <div class="hourly-rate-info">
                    <span><i class="fas fa-tag"></i> <strong>Hourly Rate:</strong> ₱${parseFloat(data.hourly_rate).toFixed(2)}</span>
                    <span class="rate-value"><i class="fas fa-calendar"></i> Daily Rate: ₱${parseFloat(data.employee.daily_salary).toFixed(2)}</span>
                </div>
            </div>
        `;
        
        container.innerHTML = html;
        
        document.getElementById('currentBaseSalary').value = data.base_salary;
        document.getElementById('currentTotalDeductions').value = data.total_deductions;
        document.getElementById('currentNetPay').value = data.net_pay;
        document.getElementById('currentWorkHours').value = data.total_work_hours;
        
        updateDeductionsList();
        updateTotalSummaryCard();
    }

    // Update deductions list
    function updateDeductionsList() {
        const container = document.getElementById('deductionsList');
        
        if (currentDeductions.length === 0) {
            container.innerHTML = `
                <div class="empty-deductions-enhanced">
                    <i class="fas fa-receipt"></i>
                    <p>No deductions added yet</p>
                    <p style="font-size: 0.8rem; margin-top: 5px;">Click "Add Deduction" to include deductions</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        let totalDeductions = 0;
        
        currentDeductions.forEach((d, index) => {
            totalDeductions += parseFloat(d.amount);
            // Determine icon based on deduction type
            let icon = 'fa-minus-circle';
            if (d.deduction_name.toLowerCase().includes('tax')) icon = 'fa-file-invoice';
            else if (d.deduction_name.toLowerCase().includes('sss')) icon = 'fa-shield-alt';
            else if (d.deduction_name.toLowerCase().includes('pag-ibig')) icon = 'fa-home';
            else if (d.deduction_name.toLowerCase().includes('philhealth')) icon = 'fa-hospital';
            else if (d.deduction_name.toLowerCase().includes('cash')) icon = 'fa-money-bill-wave';
            
            html += `
                <div class="deduction-item-enhanced">
                    <div class="deduction-info-enhanced">
                        <div class="deduction-icon">
                            <i class="fas ${icon}"></i>
                        </div>
                        <span class="deduction-name-enhanced">${d.deduction_name}</span>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <span class="deduction-amount-enhanced">- ₱${parseFloat(d.amount).toFixed(2)}</span>
                        <button class="deduction-delete-enhanced" onclick="removeDeduction(${index})" title="Remove">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Update total deductions display
        if (currentPayrollData) {
            currentPayrollData.total_deductions = totalDeductions;
            currentPayrollData.net_pay = parseFloat(currentPayrollData.base_salary) - totalDeductions;
            
            document.getElementById('currentTotalDeductions').value = totalDeductions;
            document.getElementById('currentNetPay').value = currentPayrollData.net_pay;
            
            // Update the total net pay card
            document.getElementById('totalNetPayDisplay').textContent = '₱' + currentPayrollData.net_pay.toFixed(2);
        }
    }

    // Open add deduction modal
    function openAddDeductionModal() {
        if (!selectedEmployee) {
            showNotificationModal('Please select an employee first', 'error');
            return;
        }
        
        document.getElementById('deductionEmployeeId').value = selectedEmployee.id;
        
        const dateFrom = document.getElementById('payroll_date_from').value;
        const dateTo = document.getElementById('payroll_date_to').value;
        document.getElementById('deductionStartDate').value = dateFrom;
        document.getElementById('deductionEndDate').value = dateTo;
        
        calendarDates.deduction_start.selectedDate = dateFrom;
        calendarDates.deduction_end.selectedDate = dateTo;
        
        document.getElementById('deductionName').value = '';
        document.getElementById('customDeductionName').value = '';
        document.getElementById('deductionAmount').value = '';
        document.getElementById('customNameGroup').style.display = 'none';
        
        openModal('addDeductionModal');
    }

    // Handle deduction name change
    document.getElementById('deductionName').addEventListener('change', function() {
        const customGroup = document.getElementById('customNameGroup');
        customGroup.style.display = this.value === 'Other' ? 'block' : 'none';
    });

    // Handle edit deduction name change
    document.getElementById('editDeductionName').addEventListener('change', function() {
        const customGroup = document.getElementById('editCustomNameGroup');
        customGroup.style.display = this.value === 'Other' ? 'block' : 'none';
    });

    // Add deduction
    async function addDeduction() {
        const employeeId = document.getElementById('deductionEmployeeId').value;
        let deductionName = document.getElementById('deductionName').value;
        const customName = document.getElementById('customDeductionName').value;
        const amount = document.getElementById('deductionAmount').value;
        const startDate = document.getElementById('deductionStartDate').value;
        const endDate = document.getElementById('deductionEndDate').value;
        
        if (!deductionName) {
            showNotificationModal('Please select deduction type', 'error');
            return;
        }
        
        if (deductionName === 'Other') {
            if (!customName) {
                showNotificationModal('Please enter deduction name', 'error');
                return;
            }
            deductionName = customName;
        }
        
        if (!amount || parseFloat(amount) <= 0) {
            showNotificationModal('Please enter a valid amount', 'error');
            return;
        }
        
        if (!startDate || !endDate) {
            showNotificationModal('Please select date range', 'error');
            return;
        }
        
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('add_custom_deduction', '1');
            formData.append('employee_id', employeeId);
            formData.append('deduction_name', deductionName);
            formData.append('amount', amount);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                currentDeductions.push({
                    id: data.deduction.id,
                    deduction_name: deductionName,
                    amount: parseFloat(amount)
                });
                
                recalculatePayroll();
                updateDeductionsList();
                closeModal('addDeductionModal');
                showNotificationModal('Deduction added successfully', 'success');
                hideLoading();
            } else {
                showNotificationModal(data.message, 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error adding deduction: ' + error.message, 'error');
            hideLoading();
        }
    }

    // Remove deduction
    function removeDeduction(index) {
        if (!confirm('Are you sure you want to remove this deduction?')) return;
        
        const deduction = currentDeductions[index];
        
        if (deduction.id) {
            deleteDeductionFromDB(deduction.id, index);
        } else {
            currentDeductions.splice(index, 1);
            recalculatePayroll();
            updateDeductionsList();
        }
    }

    // Delete deduction from database
    async function deleteDeductionFromDB(deductionId, index) {
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('delete_deduction', '1');
            formData.append('deduction_id', deductionId);
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                currentDeductions.splice(index, 1);
                recalculatePayroll();
                updateDeductionsList();
                showNotificationModal('Deduction removed', 'success');
            } else {
                showNotificationModal(data.message, 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error deleting deduction: ' + error.message, 'error');
        } finally {
            hideLoading();
        }
    }

    // Recalculate payroll after adding/removing deductions
    function recalculatePayroll() {
        if (!currentPayrollData) return;
        
        let totalDeductions = 0;
        currentDeductions.forEach(d => {
            totalDeductions += parseFloat(d.amount);
        });
        
        const baseSalary = parseFloat(currentPayrollData.base_salary);
        const netPay = baseSalary - totalDeductions;
        
        currentPayrollData.total_deductions = totalDeductions;
        currentPayrollData.net_pay = netPay;
        currentPayrollData.deductions = currentDeductions;
        
        document.getElementById('currentTotalDeductions').value = totalDeductions;
        document.getElementById('currentNetPay').value = netPay;
        
        displayPayrollSummary(currentPayrollData);
    }

    // Save payroll
    async function savePayroll() {
        const employeeId = document.getElementById('currentEmployeeId').value;
        const dateFrom = document.getElementById('payroll_date_from').value;
        const dateTo = document.getElementById('payroll_date_to').value;
        const baseSalary = document.getElementById('currentBaseSalary').value;
        const totalDeductions = document.getElementById('currentTotalDeductions').value;
        const netPay = document.getElementById('currentNetPay').value;
        const workHours = document.getElementById('currentWorkHours').value;
        
        if (!employeeId) {
            showNotificationModal('Employee ID missing', 'error');
            return;
        }
        
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('save_payroll', '1');
            formData.append('employee_id', employeeId);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('base_salary', baseSalary);
            formData.append('total_deductions', totalDeductions);
            formData.append('net_pay', netPay);
            formData.append('total_work_hours', workHours);
            formData.append('deductions', JSON.stringify(currentDeductions));
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const text = await response.text();
            
            try {
                const data = JSON.parse(text);
                
                if (data.success) {
                    closeModal('generatePayrollModal');
                    showSuccessModal('Payroll saved successfully!');
                } else {
                    showNotificationModal(data.message, 'error');
                }
            } catch (e) {
                console.error('Failed to parse JSON:', text);
                showNotificationModal('Server returned invalid response. Check console for details.', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error saving payroll: ' + error.message, 'error');
        } finally {
            hideLoading();
        }
    }

    // Show success modal
    function showSuccessModal(message) {
        document.getElementById('successMessage').textContent = message;
        openModal('generateSuccessModal');
    }

    // Enhanced View Payroll function - WITH CACHE BUSTING and deleted employee handling
    async function viewPayroll(payrollId) {
        showLoading();
        
        try {
            // Add timestamp to prevent caching
            const timestamp = Date.now();
            const response = await fetch(`payrollList.php?get_payroll=${payrollId}&t=${timestamp}`, {
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                const p = data.data;
                console.log('View payroll data loaded:', p); // Debug log
                
                const date_from = formatDateToLong(p.date_from);
                const date_to = formatDateToLong(p.date_to);
                
                // Calculate total days (for display)
                const start = new Date(p.date_from);
                const end = new Date(p.date_to);
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                
                // Build deductions HTML for salary breakdown
                let deductionsBreakdownHtml = '';
                let totalDeductions = 0;
                if (p.deductions && p.deductions.length > 0) {
                    p.deductions.forEach(d => {
                        totalDeductions += parseFloat(d.amount);
                        deductionsBreakdownHtml += `
                            <div class="breakdown-row total-deductions">
                                <div class="breakdown-label">
                                    <i class="fas fa-minus-circle"></i>
                                    ${d.deduction_name}
                                </div>
                                <div class="breakdown-value">- ₱${parseFloat(d.amount).toFixed(2)}</div>
                            </div>
                        `;
                    });
                }
                
                // Build deductions list for enhanced section
                let deductionsListHtml = '';
                if (p.deductions && p.deductions.length > 0) {
                    p.deductions.forEach(d => {
                        let icon = 'fa-minus-circle';
                        if (d.deduction_name.toLowerCase().includes('tax')) icon = 'fa-file-invoice';
                        else if (d.deduction_name.toLowerCase().includes('sss')) icon = 'fa-shield-alt';
                        else if (d.deduction_name.toLowerCase().includes('pag-ibig')) icon = 'fa-home';
                        else if (d.deduction_name.toLowerCase().includes('philhealth')) icon = 'fa-hospital';
                        else if (d.deduction_name.toLowerCase().includes('cash')) icon = 'fa-money-bill-wave';
                        
                        deductionsListHtml += `
                            <div class="deduction-item-enhanced">
                                <div class="deduction-info-enhanced">
                                    <div class="deduction-icon">
                                        <i class="fas ${icon}"></i>
                                    </div>
                                    <span class="deduction-name-enhanced">${d.deduction_name}</span>
                                </div>
                                <div style="display: flex; align-items: center;">
                                    <span class="deduction-amount-enhanced">- ₱${parseFloat(d.amount).toFixed(2)}</span>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    deductionsListHtml = `
                        <div class="empty-deductions-enhanced">
                            <i class="fas fa-receipt"></i>
                            <p>No deductions for this payroll</p>
                        </div>
                    `;
                }
                
                // Handle deleted employee case
                const employeeName = p.first_name === 'Deleted' ? 'Deleted Employee' : p.first_name + ' ' + p.last_name;
                const employmentType = p.employment_type || 'regular';
                const employmentTypeClass = employmentType === 'regular' ? 'regular' : 'non-regular';
                const employmentTypeLabel = employmentType === 'regular' ? 'Regular' : 'Non Regular';
                
                const html = `
                    <div class="payroll-summary-container" style="margin-top: 0;">
                        <div class="summary-header">
                            <h4>
                                <i class="fas fa-file-invoice"></i>
                                Payroll Details
                            </h4>
                            <div class="employee-badge">
                                <i class="fas fa-user-circle"></i>
                                ${employeeName}
                                <span class="employment-type-badge ${employmentTypeClass}">
                                    ${employmentTypeLabel}
                                </span>
                            </div>
                        </div>
                        
                        <!-- Period Info -->
                        <div style="background: #e6f7f5; padding: 10px 15px; border-radius: 40px; margin-bottom: 20px; display: inline-block;">
                            <i class="fas fa-calendar-alt" style="color: #00838f; margin-right: 8px;"></i>
                            <span style="font-weight: 600; color: #006064;">${date_from} - ${date_to}</span>
                        </div>
                        
                        <!-- Employee Details Card -->
                        <div class="employee-details-card" style="margin-top: 0; margin-bottom: 20px;">
                            <h4><i class="fas fa-id-card"></i> Employee Information</h4>
                            <div class="employee-details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Name</div>
                                    <div class="detail-value">${employeeName}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Position</div>
                                    <div class="detail-value">${p.position || 'N/A'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Daily Salary</div>
                                    <div class="detail-value">₱${parseFloat(p.daily_salary || 0).toFixed(2)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Contact</div>
                                    <div class="detail-value">${p.contact_num || 'N/A'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Employment Type</div>
                                    <div class="detail-value">${employmentTypeLabel}</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Attendance Stats Cards -->
                        <div class="attendance-stats">
                            <div class="stat-card">
                                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                                <div class="stat-value">${diffDays}</div>
                                <div class="stat-label">Total Days</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                                <div class="stat-value">${parseFloat(p.total_work_hours).toFixed(1)}</div>
                                <div class="stat-label">Work Hours</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="fas fa-tag"></i></div>
                                <div class="stat-value">${p.deductions ? p.deductions.length : 0}</div>
                                <div class="stat-label">Deductions</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="stat-value">${p.status}</div>
                                <div class="stat-label">Status</div>
                            </div>
                        </div>
                        
                        <!-- Salary Breakdown -->
                        <div class="salary-breakdown">
                            <div class="breakdown-header">
                                <i class="fas fa-coins"></i>
                                Salary Computation
                            </div>
                            
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-clock"></i>
                                    Total Work Hours
                                    <span class="badge">${parseFloat(p.total_work_hours).toFixed(2)} hrs</span>
                                </div>
                                <div class="breakdown-value">-</div>
                            </div>
                            
                            <div class="breakdown-row">
                                <div class="breakdown-label">
                                    <i class="fas fa-money-bill"></i>
                                    Base Salary
                                </div>
                                <div class="breakdown-value">₱${parseFloat(p.base_salary).toFixed(2)}</div>
                            </div>
                            
                            ${deductionsBreakdownHtml}
                            
                            <div class="breakdown-row total-deductions">
                                <div class="breakdown-label">
                                    <i class="fas fa-calculator"></i>
                                    Total Deductions
                                </div>
                                <div class="breakdown-value">- ₱${parseFloat(p.total_deductions).toFixed(2)}</div>
                            </div>
                            
                            <div class="breakdown-row net-pay">
                                <div class="breakdown-label">
                                    <i class="fas fa-check-circle"></i>
                                    Net Pay
                                </div>
                                <div class="breakdown-value">₱${parseFloat(p.net_pay).toFixed(2)}</div>
                            </div>
                        </div>
                        
                        <!-- Deductions Section -->
                        <div class="deductions-section-enhanced" style="margin-top: 20px;">
                            <div class="deductions-header-enhanced">
                                <h5>
                                    <i class="fas fa-minus-circle"></i>
                                    Deductions Applied
                                </h5>
                            </div>
                            <div class="deductions-list-enhanced">
                                ${deductionsListHtml}
                            </div>
                        </div>
                        
                        <!-- Status Badge -->
                        <div style="margin-top: 20px; text-align: right;">
                            <span class="status-badge ${p.status === 'paid' ? 'status-paid' : (p.status === 'pending' ? 'status-pending' : 'status-unpaid')}" style="font-size: 1rem; padding: 8px 20px;">
                                <i class="fas ${p.status === 'paid' ? 'fa-check-circle' : (p.status === 'pending' ? 'fa-clock' : 'fa-exclamation-circle')}"></i>
                                ${p.status.charAt(0).toUpperCase() + p.status.slice(1)}
                            </span>
                        </div>
                    </div>
                `;
                
                document.getElementById('viewPayrollContent').innerHTML = html;
                openModal('viewPayrollModal');
                hideLoading();
            } else {
                showNotificationModal('Error loading payroll data', 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error: ' + error.message, 'error');
            hideLoading();
        }
    }

    // Enhanced Edit Payroll function
    function editPayroll(payrollId) {
        fetch('payrollList.php?get_payroll=' + payrollId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const p = data.data;
                editPayrollId = p.id;
                editEmployeeId = p.employee_id;
                editDeductions = p.deductions || [];
                
                document.getElementById('edit_payroll_id').value = p.id;
                document.getElementById('edit_employee_id').value = p.employee_id;
                document.getElementById('edit_employee_name').value = p.first_name + ' ' + p.last_name;
                document.getElementById('edit_date_from').value = p.date_from;
                document.getElementById('edit_date_to').value = p.date_to;
                document.getElementById('edit_status').value = p.status;
                
                // Store original values
                document.getElementById('edit_original_base_salary').value = p.base_salary;
                document.getElementById('edit_original_work_hours').value = p.total_work_hours;
                document.getElementById('edit_original_date_from').value = p.date_from;
                document.getElementById('edit_original_date_to').value = p.date_to;
                document.getElementById('edit_original_status').value = p.status;
                
                calendarDates.edit_from.selectedDate = p.date_from;
                calendarDates.edit_to.selectedDate = p.date_to;
                
                // Store original data for comparison
                editOriginalData = {
                    base_salary: p.base_salary,
                    total_work_hours: p.total_work_hours,
                    date_from: p.date_from,
                    date_to: p.date_to,
                    status: p.status
                };
                
                // Store employee data for recalculation
                window.editEmployeeData = {
                    first_name: p.first_name,
                    last_name: p.last_name,
                    daily_salary: p.daily_salary,
                    employment_type: p.employment_type || 'regular'
                };
                
                // Create a payroll data object for the summary
                const payrollData = {
                    employee: {
                        first_name: p.first_name,
                        last_name: p.last_name,
                        daily_salary: p.daily_salary,
                        employment_type: p.employment_type || 'regular'
                    },
                    date_from: p.date_from,
                    date_to: p.date_to,
                    total_days: calculateDaysBetween(p.date_from, p.date_to),
                    total_work_hours: p.total_work_hours,
                    base_salary: p.base_salary,
                    total_deductions: p.total_deductions,
                    net_pay: p.net_pay,
                    deductions: p.deductions || []
                };
                
                // Store for recalculation
                currentPayrollData = payrollData;
                
                // Display enhanced edit payroll summary
                displayEditPayrollSummary(payrollData);
                
                openModal('editPayrollModal');
            } else {
                showNotificationModal('Error loading payroll data', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotificationModal('Error: ' + error.message, 'error');
        });
    }

    // Helper function to calculate days between dates
    function calculateDaysBetween(date1, date2) {
        const start = new Date(date1);
        const end = new Date(date2);
        const diffTime = Math.abs(end - start);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
    }

    // Display enhanced edit payroll summary
    function displayEditPayrollSummary(data) {
        const container = document.getElementById('editPayrollSummary');
        const emp = data.employee;
        
        // Format dates to Month Day, Year format
        const dateFrom = formatDateToLong(data.date_from);
        const dateTo = formatDateToLong(data.date_to);
        
        const employmentTypeClass = emp.employment_type === 'regular' ? 'regular' : 'non-regular';
        const employmentTypeLabel = emp.employment_type === 'regular' ? 'Regular' : 'Non Regular';
        
        // Build deductions HTML for salary breakdown
        let deductionsBreakdownHtml = '';
        let totalDeductions = 0;
        if (editDeductions && editDeductions.length > 0) {
            editDeductions.forEach(d => {
                totalDeductions += parseFloat(d.amount);
                deductionsBreakdownHtml += `
                    <div class="breakdown-row total-deductions">
                        <div class="breakdown-label">
                            <i class="fas fa-minus-circle"></i>
                            ${d.deduction_name}
                        </div>
                        <div class="breakdown-value">- ₱${parseFloat(d.amount).toFixed(2)}</div>
                    </div>
                `;
            });
        }
        
        const baseSalary = parseFloat(data.base_salary);
        const netPay = baseSalary - totalDeductions;
        
        // Get current status
        const currentStatus = document.getElementById('edit_status')?.value || data.status || 'pending';
        
        const html = `
            <div class="payroll-summary-container" style="margin-top: 0;">
                <div class="summary-header">
                    <h4>
                        <i class="fas fa-file-invoice"></i>
                        Payroll Summary
                    </h4>
                    <div class="employee-badge">
                        <i class="fas fa-user-circle"></i>
                        ${emp.first_name} ${emp.last_name}
                        <span class="employment-type-badge ${employmentTypeClass}">
                            ${employmentTypeLabel}
                        </span>
                    </div>
                </div>
                
                <!-- Period Info -->
                <div style="background: #e6f7f5; padding: 10px 15px; border-radius: 40px; margin-bottom: 20px; display: inline-block;">
                    <i class="fas fa-calendar-alt" style="color: #00838f; margin-right: 8px;"></i>
                    <span style="font-weight: 600; color: #006064;">${dateFrom} - ${dateTo}</span>
                </div>
                
                <!-- Salary Breakdown -->
                <div class="salary-breakdown">
                    <div class="breakdown-header">
                        <i class="fas fa-coins"></i>
                        Salary Computation
                    </div>
                    
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-clock"></i>
                            Total Work Hours
                            <span class="badge">${parseFloat(data.total_work_hours).toFixed(2)} hrs</span>
                        </div>
                        <div class="breakdown-value">-</div>
                    </div>
                    
                    <div class="breakdown-row">
                        <div class="breakdown-label">
                            <i class="fas fa-money-bill"></i>
                            Base Salary
                        </div>
                        <div class="breakdown-value">₱${baseSalary.toFixed(2)}</div>
                    </div>
                    
                    ${deductionsBreakdownHtml}
                    
                    <div class="breakdown-row total-deductions">
                        <div class="breakdown-label">
                            <i class="fas fa-calculator"></i>
                            Total Deductions
                        </div>
                        <div class="breakdown-value">- ₱${totalDeductions.toFixed(2)}</div>
                    </div>
                    
                    <div class="breakdown-row net-pay">
                        <div class="breakdown-label">
                            <i class="fas fa-check-circle"></i>
                            Net Pay
                        </div>
                        <div class="breakdown-value">₱${netPay.toFixed(2)}</div>
                    </div>
                </div>
                
                <!-- Current Status Display -->
                <div style="margin-top: 15px; text-align: right;">
                    <span class="status-badge ${currentStatus === 'paid' ? 'status-paid' : (currentStatus === 'pending' ? 'status-pending' : 'status-unpaid')}">
                        <i class="fas ${currentStatus === 'paid' ? 'fa-check-circle' : (currentStatus === 'pending' ? 'fa-clock' : 'fa-exclamation-circle')}"></i>
                        Current Status: ${currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)}
                    </span>
                </div>
            </div>
        `;
        
        container.innerHTML = html;
        
        // Update deductions list
        updateEditDeductionsList();
        
        // Update total summary card
        document.getElementById('editTotalNetPayDisplay').textContent = '₱' + netPay.toFixed(2);
        document.getElementById('editTotalSummaryCard').style.display = 'flex';
    }

    // Update edit deductions list
    function updateEditDeductionsList() {
        const container = document.getElementById('editDeductionsList');
        
        if (editDeductions.length === 0) {
            container.innerHTML = `
                <div class="empty-deductions-enhanced">
                    <i class="fas fa-receipt"></i>
                    <p>No deductions added yet</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        editDeductions.forEach((d, index) => {
            // Determine icon based on deduction type
            let icon = 'fa-minus-circle';
            if (d.deduction_name.toLowerCase().includes('tax')) icon = 'fa-file-invoice';
            else if (d.deduction_name.toLowerCase().includes('sss')) icon = 'fa-shield-alt';
            else if (d.deduction_name.toLowerCase().includes('pag-ibig')) icon = 'fa-home';
            else if (d.deduction_name.toLowerCase().includes('philhealth')) icon = 'fa-hospital';
            else if (d.deduction_name.toLowerCase().includes('cash')) icon = 'fa-money-bill-wave';
            
            html += `
                <div class="deduction-item-enhanced">
                    <div class="deduction-info-enhanced">
                        <div class="deduction-icon">
                            <i class="fas ${icon}"></i>
                        </div>
                        <span class="deduction-name-enhanced">${d.deduction_name}</span>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <span class="deduction-amount-enhanced">- ₱${parseFloat(d.amount).toFixed(2)}</span>
                        <button class="deduction-delete-enhanced" onclick="removeEditDeduction(${index})" title="Remove">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    // Open edit add deduction modal
    function openEditAddDeductionModal() {
        document.getElementById('editDeductionEmployeeId').value = editEmployeeId;
        
        const dateFrom = document.getElementById('edit_date_from').value;
        const dateTo = document.getElementById('edit_date_to').value;
        document.getElementById('editDeductionStartDate').value = dateFrom;
        document.getElementById('editDeductionEndDate').value = dateTo;
        
        calendarDates.edit_deduction_start.selectedDate = dateFrom;
        calendarDates.edit_deduction_end.selectedDate = dateTo;
        
        document.getElementById('editDeductionName').value = '';
        document.getElementById('editCustomDeductionName').value = '';
        document.getElementById('editDeductionAmount').value = '';
        document.getElementById('editCustomNameGroup').style.display = 'none';
        
        openModal('editAddDeductionModal');
    }

    // Add edit deduction - FIXED to update main payroll data
    async function addEditDeduction() {
        const employeeId = document.getElementById('editDeductionEmployeeId').value;
        let deductionName = document.getElementById('editDeductionName').value;
        const customName = document.getElementById('editCustomDeductionName').value;
        const amount = document.getElementById('editDeductionAmount').value;
        const startDate = document.getElementById('editDeductionStartDate').value;
        const endDate = document.getElementById('editDeductionEndDate').value;
        
        if (!deductionName) {
            showNotificationModal('Please select deduction type', 'error');
            return;
        }
        
        if (deductionName === 'Other') {
            if (!customName) {
                showNotificationModal('Please enter deduction name', 'error');
                return;
            }
            deductionName = customName;
        }
        
        if (!amount || parseFloat(amount) <= 0) {
            showNotificationModal('Please enter a valid amount', 'error');
            return;
        }
        
        if (!startDate || !endDate) {
            showNotificationModal('Please select date range', 'error');
            return;
        }
        
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('add_custom_deduction', '1');
            formData.append('employee_id', employeeId);
            formData.append('deduction_name', deductionName);
            formData.append('amount', amount);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                editDeductions.push({
                    id: data.deduction.id,
                    deduction_name: deductionName,
                    amount: parseFloat(amount)
                });
                
                // Recalculate with new deduction
                const baseSalary = parseFloat(currentPayrollData?.base_salary || 0);
                let totalDeductions = 0;
                editDeductions.forEach(d => {
                    totalDeductions += parseFloat(d.amount);
                });
                const netPay = baseSalary - totalDeductions;
                
                // Update currentPayrollData with new totals
                currentPayrollData.total_deductions = totalDeductions;
                currentPayrollData.net_pay = netPay;
                
                // Update display
                displayEditPayrollSummary(currentPayrollData);
                
                closeModal('editAddDeductionModal');
                showNotificationModal('Deduction added successfully', 'success');
                hideLoading();
            } else {
                showNotificationModal(data.message, 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error adding deduction: ' + error.message, 'error');
            hideLoading();
        }
    }

    // Remove edit deduction - FIXED to update main payroll data
    function removeEditDeduction(index) {
        if (!confirm('Are you sure you want to remove this deduction?')) return;
        
        const deduction = editDeductions[index];
        
        if (deduction.id) {
            deleteEditDeductionFromDB(deduction.id, index);
        } else {
            editDeductions.splice(index, 1);
            
            // Recalculate
            let totalDeductions = 0;
            editDeductions.forEach(d => {
                totalDeductions += parseFloat(d.amount);
            });
            const baseSalary = parseFloat(currentPayrollData?.base_salary || 0);
            const netPay = baseSalary - totalDeductions;
            
            // Update currentPayrollData with new totals
            currentPayrollData.total_deductions = totalDeductions;
            currentPayrollData.net_pay = netPay;
            
            displayEditPayrollSummary(currentPayrollData);
        }
    }

    // Delete edit deduction from database - FIXED to update main payroll data
    async function deleteEditDeductionFromDB(deductionId, index) {
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('delete_deduction', '1');
            formData.append('deduction_id', deductionId);
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                editDeductions.splice(index, 1);
                
                // Recalculate
                let totalDeductions = 0;
                editDeductions.forEach(d => {
                    totalDeductions += parseFloat(d.amount);
                });
                const baseSalary = parseFloat(currentPayrollData?.base_salary || 0);
                const netPay = baseSalary - totalDeductions;
                
                // Update currentPayrollData with new totals
                currentPayrollData.total_deductions = totalDeductions;
                currentPayrollData.net_pay = netPay;
                
                displayEditPayrollSummary(currentPayrollData);
                
                showNotificationModal('Deduction removed', 'success');
            } else {
                showNotificationModal(data.message, 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error deleting deduction: ' + error.message, 'error');
        } finally {
            hideLoading();
        }
    }

    // FIXED: Recalculate edit payroll when dates change - preserves all data
    async function recalculateEditPayroll() {
        const employeeId = editEmployeeId;
        const dateFrom = document.getElementById('edit_date_from').value;
        const dateTo = document.getElementById('edit_date_to').value;
        
        if (!employeeId || !dateFrom || !dateTo) return;
        
        showLoading();
        
        try {
            const formData = new URLSearchParams();
            formData.append('recalculate_payroll', '1');
            formData.append('employee_id', employeeId);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('current_deductions', JSON.stringify(editDeductions));
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Get employee details from stored data
                const employeeName = document.getElementById('edit_employee_name').value;
                const nameParts = employeeName.split(' ');
                const firstName = nameParts[0] || '';
                const lastName = nameParts.slice(1).join(' ') || '';
                
                // Get daily salary from stored data
                const dailySalary = window.editEmployeeData?.daily_salary || 0;
                const employmentType = window.editEmployeeData?.employment_type || 'regular';
                
                // Preserve the current status from the select dropdown
                const currentStatus = document.getElementById('edit_status').value;
                
                // Create a complete payroll data object with employee info
                const payrollData = {
                    ...data.payroll,
                    employee: {
                        first_name: firstName,
                        last_name: lastName,
                        daily_salary: dailySalary,
                        employment_type: employmentType
                    },
                    deductions: editDeductions,
                    status: currentStatus,
                    date_from: dateFrom,
                    date_to: dateTo
                };
                
                currentPayrollData = payrollData;
                displayEditPayrollSummary(payrollData);
                hideLoading();
            } else {
                showNotificationModal(data.message, 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error recalculating payroll: ' + error.message, 'error');
            hideLoading();
        }
    }

    // FIXED: Save all edited payroll changes with proper values
    async function saveEditedPayroll() {
        const payrollId = document.getElementById('edit_payroll_id').value;
        const dateFrom = document.getElementById('edit_date_from').value;
        const dateTo = document.getElementById('edit_date_to').value;
        const status = document.getElementById('edit_status').value;
        
        if (!payrollId || !dateFrom || !dateTo) {
            showNotificationModal('Missing required fields', 'error');
            return;
        }
        
        // Make sure currentPayrollData has the latest values
        const baseSalary = currentPayrollData?.base_salary || 0;
        const totalDeductions = currentPayrollData?.total_deductions || 0;
        const netPay = currentPayrollData?.net_pay || 0;
        const workHours = currentPayrollData?.total_work_hours || 0;
        
        // Check if data has changed
        const baseSalaryChanged = baseSalary !== parseFloat(document.getElementById('edit_original_base_salary').value);
        const workHoursChanged = workHours !== parseFloat(document.getElementById('edit_original_work_hours').value);
        const datesChanged = dateFrom !== document.getElementById('edit_original_date_from').value || 
                            dateTo !== document.getElementById('edit_original_date_to').value;
        const statusChanged = status !== document.getElementById('edit_original_status').value;
        
        if (!baseSalaryChanged && !workHoursChanged && !datesChanged && !statusChanged && editDeductions.length === 0) {
            showNotificationModal('No changes detected', 'info');
            closeModal('editPayrollModal');
            return;
        }
        
        showLoading();
        closeModal('editPayrollModal');
        
        try {
            const formData = new URLSearchParams();
            formData.append('save_edited_payroll', '1');
            formData.append('payroll_id', payrollId);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('base_salary', baseSalary);
            formData.append('total_deductions', totalDeductions);
            formData.append('net_pay', netPay);
            formData.append('total_work_hours', workHours);
            formData.append('status', status);
            formData.append('deductions', JSON.stringify(editDeductions));
            
            const response = await fetch('payrollList.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                },
                body: formData
            });
            
            const text = await response.text();
            console.log('Raw server response:', text);
            
            // Try to parse the response
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse JSON response:', text);
                showNotificationModal('Server returned invalid response. Please check error logs.', 'error');
                hideLoading();
                return;
            }
            
            if (data.success) {
                hideLoading();
                showNotificationModal(data.message + ' Refreshing...', 'success');
                
                // Get current month and year from the filter controls
                const month = document.getElementById('month')?.value || '<?php echo $month; ?>';
                const year = document.getElementById('year')?.value || '<?php echo $year; ?>';
                
                // Force a complete page reload with cache busting
                setTimeout(() => {
                    window.location.href = `payrollList.php?month=${month}&year=${year}&t=${Date.now()}`;
                }, 1500);
            } else {
                showNotificationModal('Error: ' + data.message, 'error');
                hideLoading();
            }
        } catch (error) {
            console.error('Error:', error);
            showNotificationModal('Error saving payroll: ' + error.message, 'error');
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
        
        openModal('deletePayrollModal');
    }

    // Confirm delete payroll
    function confirmDeletePayroll() {
        if (!currentDeletePayrollId) return;
        window.location.href = 'payrollList.php?delete_id=' + currentDeletePayrollId;
    }

    // Reset deduction modal
    function resetDeductionModal() {
        document.getElementById('deductionName').value = '';
        document.getElementById('customDeductionName').value = '';
        document.getElementById('deductionAmount').value = '';
        document.getElementById('customNameGroup').style.display = 'none';
    }

    // Reset edit deduction modal
    function resetEditDeductionModal() {
        document.getElementById('editDeductionName').value = '';
        document.getElementById('editCustomDeductionName').value = '';
        document.getElementById('editDeductionAmount').value = '';
        document.getElementById('editCustomNameGroup').style.display = 'none';
    }

    // Loading functions
    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modals = ['generatePayrollModal', 'editPayrollModal', 'viewPayrollModal', 
                       'generateSuccessModal', 'addDeductionModal', 'deletePayrollModal', 
                       'notificationModal', 'existingPayrollModal', 'editAddDeductionModal'];
        
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                closeModal(modalId);
            }
        });
    });

    // Handle escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modals = ['generatePayrollModal', 'editPayrollModal', 'viewPayrollModal', 
                           'generateSuccessModal', 'addDeductionModal', 'deletePayrollModal', 
                           'notificationModal', 'existingPayrollModal', 'editAddDeductionModal'];
            
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal && modal.classList.contains('show')) {
                    closeModal(modalId);
                }
            });
            
            if (activeCalendar) {
                document.getElementById(activeCalendar).style.display = 'none';
                activeCalendar = null;
            }
        }
    });
</script>

</body>
</html>