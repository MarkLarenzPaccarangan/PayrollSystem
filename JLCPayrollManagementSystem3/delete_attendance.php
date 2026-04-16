
<?php
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once("connection.php");

// Get POST data
$employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
$date = isset($_POST['date']) ? $_POST['date'] : '';

if (!$employee_id || !$date) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    // Get record info for logging
    $info_sql = "SELECT a.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name 
                 FROM attendance a 
                 JOIN employees e ON a.employee_id = e.id 
                 WHERE a.employee_id = ? AND DATE(a.date) = DATE(?)";
    $info_stmt = $conn->prepare($info_sql);
    $info_stmt->bind_param("is", $employee_id, $date);
    $info_stmt->execute();
    $info_result = $info_stmt->get_result();
    $record = $info_result->fetch_assoc();
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
        exit;
    }
    
    // Delete the record
    $delete_sql = "DELETE FROM attendance WHERE employee_id = ? AND DATE(date) = DATE(?)";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("is", $employee_id, $date);
    
    if ($delete_stmt->execute()) {
        error_log("Deleted attendance for employee ID: $employee_id on date: $date");
        echo json_encode([
            'success' => true, 
            'message' => 'Attendance record deleted successfully'
        ]);
    } else {
        throw new Exception("Delete failed: " . $delete_stmt->error);
    }
    
    $delete_stmt->close();
    $info_stmt->close();
    
} catch (Exception $e) {
    error_log("DELETE ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>

