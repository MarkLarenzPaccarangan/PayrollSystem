<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

include_once("connection.php");

// Fetch sites from site_monitoring table (from Employee Tracking)
$sql = "SELECT id, site_name, site_address, is_others 
        FROM site_monitoring 
        ORDER BY site_name ASC";

$result = $conn->query($sql);

$sites = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sites[] = [
            'id' => $row['id'],
            'site_name' => $row['site_name'],
            'address' => $row['site_address'],
            'is_others' => $row['is_others']
        ];
    }
}

echo json_encode([
    'success' => true,
    'sites' => $sites
]);

$conn->close();
?>