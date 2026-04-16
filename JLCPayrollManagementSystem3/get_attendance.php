<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

include_once("connection.php");

$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$employee_id || !$date) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    $sql = "SELECT id, employee_id, date, status, pm_status, night_status,
                   time_in_am, time_out_am, 
                   time_in_pm, time_out_pm,
                   time_in_night, time_out_night,
                   remarks, leave_type, workday_type,
                   site_assignment_am, site_assignment_pm, site_assignment_night,
                   total_hours
            FROM attendance 
            WHERE employee_id = ? AND DATE(date) = DATE(?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("is", $employee_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $row['success'] = true;
        
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = '';
            }
        }
        
        echo json_encode($row);
    } else {
        echo json_encode([
            'success' => true,
            'id' => '',
            'employee_id' => $employee_id,
            'date' => $date,
            'status' => '',
            'pm_status' => '',
            'night_status' => '',
            'time_in_am' => '',
            'time_out_am' => '',
            'time_in_pm' => '',
            'time_out_pm' => '',
            'time_in_night' => '',
            'time_out_night' => '',
            'remarks' => '',
            'leave_type' => '',
            'workday_type' => '',
            'site_assignment_am' => '',
            'site_assignment_pm' => '',
            'site_assignment_night' => '',
            'total_hours' => '0.00'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error in get_attendance.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>