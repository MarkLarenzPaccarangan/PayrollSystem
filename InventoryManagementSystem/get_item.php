<?php
// get_item.php - API LANG ITO, WAG MAG-INCLUDE NG HEADER
require_once 'config.php';

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM canvas_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Item not found']);
    }
    
    $stmt->close();
}

$conn->close();
?>