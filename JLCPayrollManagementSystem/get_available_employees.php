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
    $query = "SELECT e.id, e.first_name, e.last_name, e.position 
              FROM employees e 
              WHERE e.id NOT IN (
                  SELECT COALESCE(employee_id, 0) 
                  FROM site_employee 
                  WHERE employee_id IS NOT NULL
              )
              AND e.status = 'active'
              AND e.is_active = 1
              AND (e.is_archived = 0 OR e.is_archived IS NULL)
              ORDER BY e.first_name, e.last_name";

    $stmt = $conn->prepare($query);
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