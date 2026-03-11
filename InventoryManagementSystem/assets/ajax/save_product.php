<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$description = trim($_POST['description'] ?? '');
$price = floatval($_POST['price'] ?? 0);
$quantity = intval($_POST['quantity'] ?? 0);

// Validate inputs
if (empty($name) || empty($category) || $price <= 0 || $quantity < 0) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
    exit;
}

if ($id > 0) {
    // Update existing product
    $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, description = ?, price = ?, quantity = ? WHERE id = ?");
    $stmt->bind_param("sssdii", $name, $category, $description, $price, $quantity, $id);
} else {
    // Insert new product
    $stmt = $conn->prepare("INSERT INTO products (name, category, description, price, quantity) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdi", $name, $category, $description, $price, $quantity);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Product saved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>