<?php
session_start();
include_once("connection.php");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

// Get parameters
$employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
$date = isset($_POST['date']) ? $_POST['date'] : '';

if (!$employee_id || !$date) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    // UPDATED: Include workday_type and leave_type in the query
    $check_sql = "SELECT id, status, pm_status, night_status,
                         time_in_am, time_out_am, 
                         time_in_pm, time_out_pm,
                         time_in_night, time_out_night,
                         remarks, leave_type, workday_type
                  FROM attendance 
                  WHERE employee_id = ? AND DATE(date) = DATE(?)";
    
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $check_stmt->bind_param("is", $employee_id, $date);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $attendance = $result->fetch_assoc();
        
        // Format time for display
        $formatted_times = [
            'time_in_am_formatted' => !empty($attendance['time_in_am']) && $attendance['time_in_am'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_in_am'])) : '',
            'time_out_am_formatted' => !empty($attendance['time_out_am']) && $attendance['time_out_am'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_out_am'])) : '',
            'time_in_pm_formatted' => !empty($attendance['time_in_pm']) && $attendance['time_in_pm'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_in_pm'])) : '',
            'time_out_pm_formatted' => !empty($attendance['time_out_pm']) && $attendance['time_out_pm'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_out_pm'])) : '',
            'time_in_night_formatted' => !empty($attendance['time_in_night']) && $attendance['time_in_night'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_in_night'])) : '',
            'time_out_night_formatted' => !empty($attendance['time_out_night']) && $attendance['time_out_night'] != '00:00:00' ? date('h:i A', strtotime($attendance['time_out_night'])) : ''
        ];
        
        // Return the attendance data with all fields
        echo json_encode([
            'exists' => true,
            'attendance' => [
                'id' => $attendance['id'],
                'status' => $attendance['status'],
                'pm_status' => $attendance['pm_status'] ?? 'Absent',
                'night_status' => $attendance['night_status'] ?? 'Absent',
                'time_in_am' => $attendance['time_in_am'],
                'time_out_am' => $attendance['time_out_am'],
                'time_in_pm' => $attendance['time_in_pm'],
                'time_out_pm' => $attendance['time_out_pm'],
                'time_in_night' => $attendance['time_in_night'],
                'time_out_night' => $attendance['time_out_night'],
                'remarks' => $attendance['remarks'],
                'leave_type' => $attendance['leave_type'],
                'workday_type' => $attendance['workday_type'],
                'formatted_times' => $formatted_times
            ]
        ]);
    } else {
        echo json_encode([
            'exists' => false
        ]);
    }
    
    $check_stmt->close();
    
} catch (Exception $e) {
    error_log("Error in check_attendance.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>