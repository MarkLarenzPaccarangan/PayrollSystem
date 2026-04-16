<?php
session_start();
include_once("connection.php");

$employee_id = 1; // Palitan ng employee ID na ginamit mo
$date = date('Y-m-d'); // Today's date

echo "<h2>Check Holiday Type Save</h2>";

// Check kung may record
$sql = "SELECT id, employee_id, date, status, holiday_type, leave_type 
        FROM attendance 
        WHERE employee_id = ? AND DATE(date) = DATE(?)
        ORDER BY id DESC LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $employee_id, $date);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "<h3>Record Found:</h3>";
    echo "<pre>";
    print_r($row);
    echo "</pre>";
    
    echo "<strong>Status:</strong> " . $row['status'] . "<br>";
    echo "<strong>Holiday Type:</strong> " . ($row['holiday_type'] ?: 'EMPTY') . "<br>";
    echo "<strong>Leave Type:</strong> " . ($row['leave_type'] ?: 'EMPTY') . "<br>";
    
    if (!empty($row['holiday_type'])) {
        echo "<p style='color:green'>✓ Holiday type ay naka-save: " . $row['holiday_type'] . "</p>";
    } else {
        echo "<p style='color:red'>✗ Holiday type ay walang laman</p>";
    }
} else {
    echo "<p style='color:red'>Walang record na mahanap para sa employee $employee_id sa date $date</p>";
}

// Show recent records
echo "<h3>Recent 5 Attendance Records:</h3>";
$recent = $conn->query("SELECT id, employee_id, date, status, holiday_type FROM attendance ORDER BY id DESC LIMIT 5");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Employee</th><th>Date</th><th>Status</th><th>Holiday Type</th></tr>";
while ($r = $recent->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $r['id'] . "</td>";
    echo "<td>" . $r['employee_id'] . "</td>";
    echo "<td>" . $r['date'] . "</td>";
    echo "<td>" . $r['status'] . "</td>";
    echo "<td style='background-color:" . (!empty($r['holiday_type']) ? '#d4edda' : '#fff') . "'>" . ($r['holiday_type'] ?: '-') . "</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>