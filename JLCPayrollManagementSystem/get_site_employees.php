<?php
session_start();
include 'connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$site_id = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($site_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit;
}

try {
    $columns = ['e.id', 'e.first_name', 'e.last_name', 'e.position'];
    
    $email_check = $conn->query("SHOW COLUMNS FROM employees LIKE 'email'");
    if ($email_check && $email_check->num_rows > 0) {
        $columns[] = 'e.email';
    }
    
    $columns_str = implode(', ', $columns);
    $query = "SELECT $columns_str 
              FROM employees e 
              INNER JOIN site_employee se ON e.id = se.employee_id 
              WHERE se.site_id = ?
              ORDER BY e.first_name, e.last_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'employees' => $employees,
        'count' => count($employees)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>