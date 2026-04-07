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

// Debug: Log POST data
error_log("========================================");
error_log("ADD_ATTENDANCE.PHP ACCESSED");
error_log("POST Data: " . print_r($_POST, true));
error_log("========================================");

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data with validation
    $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : ''; // AM Status
    $pm_status = isset($_POST['pm_status']) ? trim($_POST['pm_status']) : ''; // PM Status
    $night_status = isset($_POST['night_status']) ? trim($_POST['night_status']) : ''; // Night Status
    $site = isset($_POST['site']) ? trim($_POST['site']) : '';
    $attendance_id = isset($_POST['attendance_id']) ? intval($_POST['attendance_id']) : 0;
    
    // Get leave type - standalone, can be set regardless of status
    $leave_type = isset($_POST['leave_type']) ? trim($_POST['leave_type']) : NULL;
    if (empty($leave_type)) {
        $leave_type = NULL;
    }
    
    // Get workday type - standalone, can be set regardless of status
    $workday_type = isset($_POST['workday_type']) ? trim($_POST['workday_type']) : NULL;
    if (empty($workday_type)) {
        $workday_type = NULL;
    }
    
    // Time fields – set to NULL only if empty, but keep '00:00:00' as valid (midnight)
    $time_in_am = (!empty($_POST['time_in_am'])) ? trim($_POST['time_in_am']) : NULL;
    $time_out_am = (!empty($_POST['time_out_am'])) ? trim($_POST['time_out_am']) : NULL;
    $time_in_pm = (!empty($_POST['time_in_pm'])) ? trim($_POST['time_in_pm']) : NULL;
    $time_out_pm = (!empty($_POST['time_out_pm'])) ? trim($_POST['time_out_pm']) : NULL;
    $time_in_night = (!empty($_POST['time_in_night'])) ? trim($_POST['time_in_night']) : NULL;
    $time_out_night = (!empty($_POST['time_out_night'])) ? trim($_POST['time_out_night']) : NULL;

    function calculateHours($time_in, $time_out) {
        // Check if either time is empty
        if (empty($time_in) || empty($time_out)) {
            return 0;
        }
        
        // Special handling for 12:00 AM (00:00:00) as end time
        // If time_out is exactly 00:00:00 (midnight), treat it as end of day
        $is_midnight_out = ($time_out === '00:00:00');
        
        // Convert to timestamps
        $time_in_ts = strtotime($time_in);
        
        if ($is_midnight_out) {
            // For midnight (12:00 AM), set time_out to 24:00 (next day 00:00)
            // We'll calculate hours until midnight of the same day
            $time_out_ts = strtotime($time_in);
            // Get the date part and add 1 day, then set to 00:00:00
            $next_day = strtotime('+1 day', strtotime(date('Y-m-d', $time_out_ts)));
            $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        } else {
            $time_out_ts = strtotime($time_out);
        }
        
        // Check if conversion failed
        if ($time_in_ts === false || $time_out_ts === false) {
            return 0;
        }
        
        // If time_out is less than time_in, it means it's next day
        if ($time_out_ts < $time_in_ts && !$is_midnight_out) {
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
    
    // Calculate total hours (including night session)
    $am_hours = calculateHours($time_in_am, $time_out_am);
    $pm_hours = calculateHours($time_in_pm, $time_out_pm);
    $night_hours = calculateHours($time_in_night, $time_out_night);
    $total_hours = $am_hours + $pm_hours + $night_hours;
    
    // If AM is Absent, set AM time fields to NULL
    if ($status === 'Absent') {
        $time_in_am = $time_out_am = NULL;
    }
    
    // If PM is Absent, set PM time fields to NULL
    if ($pm_status === 'Absent') {
        $time_in_pm = $time_out_pm = NULL;
    }
    
    // If Night is Absent, set Night time fields to NULL
    if ($night_status === 'Absent') {
        $time_in_night = $time_out_night = NULL;
    }
    
    // If AM status is Present but no times, set to No Record
    if ($status === 'Present' && !$time_in_am && !$time_out_am) {
        $status = 'No Record';
    }
    if ($status === 'Present' && ($time_in_am || $time_out_am)) {
        // Keep as Present - valid times exist
    }

    // Similarly for PM
    if ($pm_status === 'Present' && !$time_in_pm && !$time_out_pm) {
        $pm_status = 'No Record';
    }
    if ($pm_status === 'Present' && ($time_in_pm || $time_out_pm)) {
        // Keep as Present
    }

    // Similarly for Night
    if ($night_status === 'Present' && !$time_in_night && !$time_out_night) {
        $night_status = 'No Record';
    }
    if ($night_status === 'Present' && ($time_in_night || $time_out_night)) {
        // Keep as Present
    }
    
    error_log("Processing attendance for:");
    error_log("- Attendance ID: " . $attendance_id);
    error_log("- Employee ID: " . $employee_id);
    error_log("- Date: " . $date);
    error_log("- AM Status: " . $status);
    error_log("- PM Status: " . $pm_status);
    error_log("- Night Status: " . $night_status);
    error_log("- Leave Type: " . ($leave_type ? $leave_type : 'NULL'));
    error_log("- Workday Type: " . ($workday_type ? $workday_type : 'NULL'));
    
    // Validate required fields
    if (empty($employee_id) || empty($date)) {
        $_SESSION['error'] = "Please select employee and date";
        error_log("VALIDATION FAILED: Missing required fields");
        header("Location: attendance.php?date=" . urlencode($date));
        exit;
    }
    
    try {
        // FIXED: Check if this is an edit (has attendance_id) OR check by employee_id and date
        $existing_record = null;
        $is_update = false;
        
        // FIRST: Check if we have an attendance_id (this is an edit)
        if ($attendance_id > 0) {
            $check_sql = "SELECT id FROM attendance WHERE id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $attendance_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $existing_record = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($existing_record) {
                $is_update = true;
                error_log("Found existing record by ID: " . $attendance_id . " - This is an UPDATE operation");
            }
        }
        
        // SECOND: If no record found by ID, check by employee_id and date (for new records)
        if (!$existing_record) {
            $check_sql = "SELECT id FROM attendance WHERE employee_id = ? AND DATE(date) = DATE(?)";
            error_log("Checking existing record by employee and date: " . $check_sql);
            
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $check_stmt->bind_param("is", $employee_id, $date);
            if (!$check_stmt->execute()) {
                throw new Exception("Execute failed: " . $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            $existing_record = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($existing_record) {
                $attendance_id = $existing_record['id'];
                $is_update = true;
                error_log("Found existing record by employee/date ID: " . $attendance_id . " - This is an UPDATE operation");
            } else {
                $is_update = false;
                error_log("No existing record found - This is an INSERT operation");
            }
        }
        
        if ($is_update) {
            // Update existing record
            error_log("Updating existing record ID: " . $attendance_id);
            
            // Check if columns exist
            $columns_check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_in_night'");
            $has_night_columns = $columns_check && $columns_check->num_rows > 0;
            
            $workday_check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'workday_type'");
            $has_workday_column = $workday_check && $workday_check->num_rows > 0;
            
            if ($has_night_columns && $has_workday_column) {
                // Update with night session columns and workday type
                $update_sql = "UPDATE attendance SET 
                              employee_id = ?,
                              date = ?,
                              status = ?,
                              pm_status = ?,
                              night_status = ?,
                              time_in_am = ?, 
                              time_out_am = ?, 
                              time_in_pm = ?,
                              time_out_pm = ?,
                              time_in_night = ?,
                              time_out_night = ?,
                              site = ?,
                              leave_type = ?,
                              workday_type = ?,
                              total_hours = ?,
                              updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?";
                
                error_log("Update SQL: " . $update_sql);
                
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Update prepare failed: " . $conn->error);
                }
                
                $update_stmt->bind_param("isssssssssssssdi", 
                    $employee_id,
                    $date,
                    $status,
                    $pm_status,
                    $night_status,
                    $time_in_am, 
                    $time_out_am, 
                    $time_in_pm, 
                    $time_out_pm,
                    $time_in_night,
                    $time_out_night,
                    $site, 
                    $leave_type, 
                    $workday_type, 
                    $total_hours,
                    $attendance_id
                );
                
                if ($update_stmt->execute()) {
                    $_SESSION['success'] = "✅ Attendance record updated successfully for Employee ID: $employee_id";
                    error_log("UPDATE SUCCESSFUL - Affected rows: " . $update_stmt->affected_rows);
                } else {
                    throw new Exception("Update execute failed: " . $update_stmt->error);
                }
                
                $update_stmt->close();
            } else {
                $_SESSION['error'] = "Database needs to be updated. Please add workday_type column.";
                header("Location: attendance.php?date=" . urlencode($date));
                exit;
            }
        } else {
            // Insert new record
            error_log("Inserting new record");
            
            // Check if columns exist
            $columns_check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'time_in_night'");
            $has_night_columns = $columns_check && $columns_check->num_rows > 0;
            
            $workday_check = $conn->query("SHOW COLUMNS FROM attendance LIKE 'workday_type'");
            $has_workday_column = $workday_check && $workday_check->num_rows > 0;
            
            if ($has_night_columns && $has_workday_column) {
                // Insert with night session columns and workday type
                $insert_sql = "INSERT INTO attendance (
                              employee_id, 
                              date, 
                              status,
                              pm_status,
                              night_status,
                              time_in_am, 
                              time_out_am, 
                              time_in_pm, 
                              time_out_pm,
                              time_in_night,
                              time_out_night,
                              site, 
                              leave_type,
                              workday_type,
                              total_hours,
                              created_at
                              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                
                error_log("Insert SQL: " . $insert_sql);
                
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) {
                    throw new Exception("Insert prepare failed: " . $conn->error);
                }
                
                $insert_stmt->bind_param("isssssssssssssd", 
                    $employee_id, 
                    $date, 
                    $status,
                    $pm_status,
                    $night_status,
                    $time_in_am, 
                    $time_out_am, 
                    $time_in_pm, 
                    $time_out_pm,
                    $time_in_night,
                    $time_out_night,
                    $site, 
                    $leave_type,
                    $workday_type,
                    $total_hours
                );
                
                if ($insert_stmt->execute()) {
                    $new_id = $insert_stmt->insert_id;
                    $_SESSION['success'] = "✅ Attendance record added successfully for Employee ID: $employee_id";
                    error_log("INSERT SUCCESSFUL - New ID: " . $new_id);
                } else {
                    throw new Exception("Insert execute failed: " . $insert_stmt->error);
                }
                
                $insert_stmt->close();
            } else {
                $_SESSION['error'] = "Database needs to be updated. Please add workday_type column.";
                header("Location: attendance.php?date=" . urlencode($date));
                exit;
            }
        }
        
    } catch (Exception $e) {
        error_log("DATABASE ERROR: " . $e->getMessage());
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
    
    // Get employee name for enhanced success message
    if (isset($_SESSION['success'])) {
        $name_sql = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?";
        $name_stmt = $conn->prepare($name_sql);
        if ($name_stmt) {
            $name_stmt->bind_param("i", $employee_id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            if ($name_row = $name_result->fetch_assoc()) {
                $_SESSION['success'] = "✅ Attendance record for " . $name_row['full_name'] . " on " . date('F j, Y', strtotime($date)) . " has been saved successfully.";
            }
            $name_stmt->close();
        }
    }
    
    // Redirect back to attendance page
    $redirect_url = "attendance.php?date=" . urlencode($date);
    error_log("Redirecting to: " . $redirect_url);
    
    header("Location: " . $redirect_url);
    exit;
    
} else {
    error_log("INVALID REQUEST METHOD: " . $_SERVER["REQUEST_METHOD"]);
    $_SESSION['error'] = "Invalid request method";
    header("Location: attendance.php");
    exit;
}

$conn->close();
?>