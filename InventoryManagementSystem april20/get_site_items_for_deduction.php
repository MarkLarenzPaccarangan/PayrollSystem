<?php
// get_site_items_for_deduction.php - Get items deployed to a site for deduction (GROUPED by product)
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Database connection
$host = DB_HOST;
$username = DB_USER;
$password = DB_PASS;
$database = DB_NAME;

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Get parameters
$site_name = isset($_GET['site_name']) ? trim($_GET['site_name']) : '';

if (empty($site_name)) {
    echo json_encode(['success' => false, 'message' => 'Site name is required']);
    exit();
}

// GROUP BY product_id para isang row lang per item, at i-SUM ang quantity
$sql = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.item_no,
            p.description,
            p.category,
            p.unit,
            SUM(sm.quantity) as total_quantity,
            GROUP_CONCAT(sm.id) as movement_ids,
            MIN(sm.created_at) as first_deployed_date
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.id
        WHERE sm.type = 'out' 
        AND sm.site_location = ?
        GROUP BY p.id, p.name, p.item_no, p.description, p.category, p.unit
        HAVING total_quantity > 0
        ORDER BY p.item_no ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $site_name);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'product_id' => $row['product_id'],
        'total_quantity' => intval($row['total_quantity']),
        'product_name' => $row['product_name'],
        'item_no' => $row['item_no'],
        'description' => $row['description'],
        'category' => $row['category'],
        'unit' => $row['unit'] ?? 'pcs',
        'movement_ids' => $row['movement_ids'],
        'first_deployed_date' => $row['first_deployed_date']
    ];
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'total_items' => count($items),
    'total_quantity' => array_sum(array_column($items, 'total_quantity'))
]);

$conn->close();
?>