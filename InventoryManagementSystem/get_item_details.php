<?php
// get_item_details.php - Fetch item details by item number for auto-description
require_once 'config.php';

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'found' => false, 
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]);
    exit;
}

header('Content-Type: application/json');

// Get item number from request (GET parameter)
$item_no = isset($_GET['item_no']) ? trim($_GET['item_no']) : '';

// Debug: Log the request (optional)
error_log("get_item_details.php called with item_no: " . $item_no);

if (empty($item_no)) {
    echo json_encode([
        'success' => false, 
        'found' => false, 
        'message' => 'Item number is required'
    ]);
    $conn->close();
    exit;
}

// Search for item in canvas_items by item_no
$query = $conn->prepare("SELECT id, item_no, description, category, unit FROM canvas_items WHERE item_no = ?");
$query->bind_param("s", $item_no);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $item = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'found' => true,
        'id' => $item['id'],
        'item_no' => $item['item_no'],
        'description' => $item['description'],
        'category' => $item['category'] ?? '',
        'unit' => $item['unit'] ?? 'pcs'
    ]);
} else {
    echo json_encode([
        'success' => true,
        'found' => false,
        'message' => 'Item not found',
        'item_no' => $item_no
    ]);
}

$query->close();
$conn->close();
?>