<?php
// delete_price.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get price_id from POST
$price_id = isset($_POST['price_id']) ? $_POST['price_id'] : null;

if (!$price_id) {
    echo json_encode(['success' => false, 'message' => 'Missing price_id field']);
    exit;
}

// Convert to integer
$price_id = intval($price_id);

if ($price_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid price_id value']);
    exit;
}

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// SIMPLE DELETE - direct lang para sigurado
$sql = "DELETE FROM company_prices WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $price_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Price deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>