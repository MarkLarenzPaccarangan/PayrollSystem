<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

include_once("connection.php");

$query = "SELECT id, site_name FROM site_monitoring WHERE is_active = 1 ORDER BY site_name";
$result = $conn->query($query);

$sites = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sites[] = [
            'id' => $row['id'],
            'name' => $row['site_name']
        ];
    }
}

echo json_encode(['success' => true, 'sites' => $sites]);
$conn->close();
?>