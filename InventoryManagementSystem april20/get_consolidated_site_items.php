<?php
// get_consolidated_site_items.php - Get CONSOLIDATED items for a site (grouped by product)
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Set header to return JSON
header('Content-Type: application/json');

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

// QUERY: Get products with category and unit from canvas_items (the source of truth)
$sql = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.item_no,
            p.description,
            COALESCE(ci.category, p.category, '—') as category,
            COALESCE(ci.unit, p.unit, 'pcs') as unit,
            SUM(sm.quantity) as total_quantity
        FROM stock_movements sm
        INNER JOIN products p ON sm.product_id = p.id
        LEFT JOIN canvas_items ci ON p.item_no = ci.item_no
        WHERE sm.type = 'out' 
        AND sm.site_location = ?
        GROUP BY p.id, p.name, p.item_no, p.description, ci.category, p.category, ci.unit, p.unit
        ORDER BY p.item_no ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
    $conn->close();
    exit();
}

$stmt->bind_param("s", $site_name);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    // Get category - prefer from canvas_items
    $category = $row['category'];
    
    // If category is '0' or empty, try to get from canvas_items directly
    if ($category === '0' || $category === '' || $category === null) {
        $item_no = $row['item_no'];
        $canvas_sql = "SELECT category FROM canvas_items WHERE item_no = ? LIMIT 1";
        $canvas_stmt = $conn->prepare($canvas_sql);
        if ($canvas_stmt) {
            $canvas_stmt->bind_param("s", $item_no);
            $canvas_stmt->execute();
            $canvas_result = $canvas_stmt->get_result();
            if ($canvas_row = $canvas_result->fetch_assoc()) {
                $category = $canvas_row['category'] ?: '—';
            }
            $canvas_stmt->close();
        }
    }
    
    // If still empty or '0', set to '—'
    if (empty($category) || $category === '0') {
        $category = '—';
    }
    
    // Get unit - prefer from canvas_items
    $unit = $row['unit'];
    if ($unit === '0' || $unit === '' || $unit === null) {
        $item_no = $row['item_no'];
        $canvas_sql = "SELECT unit FROM canvas_items WHERE item_no = ? LIMIT 1";
        $canvas_stmt = $conn->prepare($canvas_sql);
        if ($canvas_stmt) {
            $canvas_stmt->bind_param("s", $item_no);
            $canvas_stmt->execute();
            $canvas_result = $canvas_stmt->get_result();
            if ($canvas_row = $canvas_result->fetch_assoc()) {
                $unit = $canvas_row['unit'] ?: 'pcs';
            }
            $canvas_stmt->close();
        }
    }
    
    // If still empty or '0', set to 'pcs'
    if (empty($unit) || $unit === '0') {
        $unit = 'pcs';
    }
    
    $items[] = [
        'product_id' => $row['product_id'],
        'product_name' => $row['product_name'],
        'item_no' => $row['item_no'] ?? 'N/A',
        'description' => $row['description'] ?? '',
        'category' => $category,
        'unit' => $unit,
        'total_quantity' => intval($row['total_quantity'])
    ];
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'total_items' => count($items),
    'site_name' => $site_name
]);

$stmt->close();
$conn->close();
?>