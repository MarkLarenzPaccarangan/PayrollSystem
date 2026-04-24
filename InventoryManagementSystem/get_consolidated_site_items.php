<?php
// get_consolidated_site_items.php - FIXED VERSION
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

$site_name = isset($_GET['site_name']) ? trim($_GET['site_name']) : '';

if (empty($site_name)) {
    echo json_encode(['success' => false, 'message' => 'Site name is required']);
    exit();
}

// ============================================================
// FIXED: Calculate net quantity by subtracting deductions
// ============================================================
$sql = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.item_no,
            p.description,
            COALESCE(ci.category, p.category, '—') as category,
            COALESCE(ci.unit, p.unit, 'pcs') as unit,
            COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) as out_quantity,
            COALESCE(SUM(CASE WHEN sm.type = 'deduct' THEN sm.quantity ELSE 0 END), 0) as deducted_quantity,
            COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN sm.type = 'deduct' THEN sm.quantity ELSE 0 END), 0) as total_quantity
        FROM stock_movements sm
        INNER JOIN products p ON sm.product_id = p.id
        LEFT JOIN canvas_items ci ON p.item_no = ci.item_no
        WHERE (sm.type = 'out' OR sm.type = 'deduct')
        AND sm.site_location = ?
        AND (sm.status = 'active' OR sm.status IS NULL OR sm.status = '')
        GROUP BY p.id, p.name, p.item_no, p.description, ci.category, p.category, ci.unit, p.unit
        HAVING total_quantity > 0
        ORDER BY p.item_no ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare error: ' . $conn->error]);
    $conn->close();
    exit();
}

$stmt->bind_param("s", $site_name);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $category = $row['category'];
    if (empty($category) || $category === '0' || $category === '—') {
        $category = '—';
    }
    
    $unit = $row['unit'];
    if (empty($unit) || $unit === '0') {
        $unit = 'pcs';
    }
    
    $total_qty = intval($row['total_quantity']);
    if ($total_qty > 0) {
        $items[] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'item_no' => $row['item_no'] ?? 'N/A',
            'description' => $row['description'] ?? '',
            'category' => $category,
            'unit' => $unit,
            'total_quantity' => $total_qty
        ];
    }
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