<?php
// search_products.php - FIXED VERSION WITH STOCK AND CATEGORY
require_once 'config.php';
requireLogin();

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// I-enable ang error reporting para makita ang error
error_reporting(E_ALL);
ini_set('display_errors', 1);

$term = isset($_GET['term']) ? $_GET['term'] : '';

if (empty($term)) {
    echo json_encode([]);
    exit();
}

// Kunin ang products kasama ang stock at category
$search = "%$term%";
$sql = "SELECT 
            id, 
            name, 
            item_no, 
            description, 
            category, 
            quantity, 
            unit 
        FROM products 
        WHERE item_no LIKE ? 
           OR name LIKE ? 
           OR description LIKE ? 
           OR category LIKE ? 
        ORDER BY 
            CASE 
                WHEN item_no = ? THEN 1
                WHEN item_no LIKE ? THEN 2
                ELSE 3
            END
        LIMIT 20";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$exact_term = $term;
$like_term = "%$term%";

// 6 parameters lang - isa para sa bawat placeholder
$stmt->bind_param("ssssss", $like_term, $like_term, $like_term, $like_term, $exact_term, $like_term);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    // Siguraduhing may value ang quantity at hindi NULL
    $quantity = isset($row['quantity']) ? (int)$row['quantity'] : 0;
    
    $products[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'] ?: '',
        'item_no' => $row['item_no'] ?: '',
        'description' => $row['description'] ?: '',
        'category' => $row['category'] ?: '',
        'quantity' => $quantity,
        'unit' => $row['unit'] ?: 'pcs'
    ];
}

// I-set ang header para sure na JSON
header('Content-Type: application/json');
echo json_encode($products);
?>