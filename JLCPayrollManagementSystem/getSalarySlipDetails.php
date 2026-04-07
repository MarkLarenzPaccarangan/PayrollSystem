<?php
include 'connection.php';

// Get employee ID from GET request
$employee_id = $_GET['employee_id'];

// Fetch employee details
$query = "
    SELECT 
        e.id, 
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
        e.monthly_salary, 
        e.net_salary, 
        e.contact_num, 
        e.position, 
        b.branch_name, 
        b.department_manager, 
        b.department_address, 
        SUM(d.amount) AS total_deductions
    FROM employees e
    LEFT JOIN branch_employee be ON e.id = be.employee_id
    LEFT JOIN branch b ON be.branch_id = b.id
    LEFT JOIN deduction d ON e.id = d.employee_id
    WHERE e.id = ?
    GROUP BY e.id
";

// Prepare and execute the statement
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if data is found
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode($row);  // Return data as JSON
} else {
    echo json_encode(['error' => 'Employee not found']);
}
?>
