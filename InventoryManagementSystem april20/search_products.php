<?php
// search_products.php - DATE-AWARE STOCK CALCULATION FOR PULL OUT MODAL
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

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$term = isset($_GET['term']) ? trim($_GET['term']) : '';
$pullout_date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (empty($term)) {
    echo json_encode([]);
    exit();
}

// Get products with stock calculated AS OF the selected pull-out date
$search = "%$term%";
$sql = "SELECT 
            p.id, 
            p.name, 
            p.item_no, 
            p.description, 
            p.category, 
            p.unit,
            COALESCE(
                (SELECT SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END) 
                 FROM stock_movements sm 
                 WHERE sm.product_id = p.id AND DATE(sm.created_at) <= ?), 0
            ) -
            COALESCE(
                (SELECT SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END) 
                 FROM stock_movements sm 
                 WHERE sm.product_id = p.id AND DATE(sm.created_at) <= ?), 0
            ) as available_stock
        FROM products p
        WHERE p.item_no LIKE ? 
           OR p.name LIKE ? 
           OR p.description LIKE ? 
           OR p.category LIKE ? 
        ORDER BY 
            CASE 
                WHEN p.item_no = ? THEN 1
                WHEN p.item_no LIKE ? THEN 2
                ELSE 3
            END,
            p.item_no ASC
        LIMIT 20";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$exact_term = $term;
$like_term = "%$term%";

// 8 parameters: pullout_date (2x), search terms (4x), exact_term, like_term
$stmt->bind_param("ssssssss", 
    $pullout_date,     // for stock-in calculation
    $pullout_date,     // for stock-out calculation
    $like_term,        // item_no LIKE
    $like_term,        // name LIKE
    $like_term,        // description LIKE
    $like_term,        // category LIKE
    $exact_term,       // exact match for item_no
    $like_term         // starts with for item_no
);

$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    // Ensure quantity is not NULL and is an integer
    $quantity = isset($row['available_stock']) ? (int)$row['available_stock'] : 0;
    
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

// Set header to ensure JSON response
header('Content-Type: application/json');
echo json_encode($products);

$stmt->close();
$conn->close();
?>