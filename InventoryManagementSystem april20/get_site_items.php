<?php
// get_site_items.php - Get items deployed to a specific site (UPDATED)
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Set JSON header
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

// Get site_id from request
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

if ($site_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit();
}

// Get site name first
$site_sql = "SELECT id, site_name, location_description FROM sites WHERE id = ?";
$site_stmt = $conn->prepare($site_sql);
if (!$site_stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$site_stmt->bind_param("i", $site_id);
$site_stmt->execute();
$site_result = $site_stmt->get_result();
$site = $site_result->fetch_assoc();

if (!$site) {
    echo json_encode(['success' => false, 'message' => 'Site not found']);
    exit();
}

$site_name = $site['site_name'];

// ========== IMPROVED QUERY: Get all deployed items for this site ==========
// This query sums quantities by product and gets the latest movement details
$sql = "SELECT 
            p.id as product_id,
            p.item_no,
            p.name as product_name,
            p.description,
            p.category,
            p.unit,
            p.price,
            SUM(sm.quantity) as total_quantity,
            MAX(sm.id) as latest_movement_id,
            MAX(sm.created_at) as latest_movement_date,
            GROUP_CONCAT(DISTINCT sm.id ORDER BY sm.id DESC) as movement_ids
        FROM stock_movements sm
        INNER JOIN products p ON sm.product_id = p.id
        WHERE sm.type = 'out' 
        AND sm.site_location = ?
        GROUP BY p.id, p.item_no, p.name, p.description, p.category, p.unit, p.price
        HAVING total_quantity > 0
        ORDER BY p.item_no ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("s", $site_name);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$total_items_count = 0;
$total_quantity = 0;

while ($row = $result->fetch_assoc()) {
    // Get the latest movement details for reference
    $latest_sql = "SELECT id as movement_id, reference, notes, created_at 
                   FROM stock_movements 
                   WHERE id = ?";
    $latest_stmt = $conn->prepare($latest_sql);
    if ($latest_stmt) {
        $latest_stmt->bind_param("i", $row['latest_movement_id']);
        $latest_stmt->execute();
        $latest_result = $latest_stmt->get_result();
        $latest = $latest_result->fetch_assoc();
    } else {
        $latest = ['movement_id' => $row['latest_movement_id'], 'reference' => '', 'notes' => ''];
    }
    
    $current_quantity = intval($row['total_quantity']);
    $total_quantity += $current_quantity;
    $total_items_count++;
    
    // Format item_no and description properly
    $item_no = $row['item_no'];
    if (empty($item_no) && strpos($row['product_name'], ' - ') !== false) {
        $parts = explode(' - ', $row['product_name'], 2);
        $item_no = trim($parts[0]);
    }
    
    $description = $row['description'];
    if (empty($description) && strpos($row['product_name'], ' - ') !== false) {
        $parts = explode(' - ', $row['product_name'], 2);
        $description = trim($parts[1]);
    } elseif (empty($description)) {
        $description = $row['product_name'];
    }
    
    $items[] = [
        'movement_id' => intval($latest['movement_id'] ?? 0),
        'product_id' => intval($row['product_id']),
        'item_no' => $item_no ?: 'N/A',
        'description' => $description ?: 'N/A',
        'category' => $row['category'] ?: 'Uncategorized',
        'unit' => $row['unit'] ?: 'pcs',
        'current_quantity' => $current_quantity,
        'price' => floatval($row['price'] ?? 0),
        'total_value' => $current_quantity * floatval($row['price'] ?? 0),
        'reference' => $latest['reference'] ?? '',
        'notes' => $latest['notes'] ?? '',
        'latest_movement_date' => $latest['created_at'] ?? null
    ];
}

// Close statements
if (isset($latest_stmt)) $latest_stmt->close();
$stmt->close();
$site_stmt->close();

// Return the data
echo json_encode([
    'success' => true,
    'site_id' => $site_id,
    'site_name' => $site_name,
    'items' => $items,
    'total_items' => $total_items_count,
    'total_quantity' => $total_quantity,
    'message' => count($items) > 0 ? 'Items loaded successfully' : 'No items deployed to this site'
]);

$conn->close();
?>