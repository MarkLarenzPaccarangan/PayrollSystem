<?php
// get_site_deployed_items.php - FIXED: Now properly subtracts deductions
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$site_name = isset($_GET['site_name']) ? $_GET['site_name'] : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : '';

if (empty($site_name)) {
    echo json_encode(['success' => false, 'message' => 'Site name is required']);
    exit();
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// If no date selected, use today's date
if (empty($selected_date)) {
    $selected_date = date('Y-m-d');
}

// ============================================================
// FIXED: Calculate net quantity by subtracting deductions from out movements
// ============================================================
$sql = "SELECT 
            p.id as product_id,
            p.item_no,
            p.name as product_name,
            p.description,
            p.category,
            p.unit,
            COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) as out_quantity,
            COALESCE(SUM(CASE WHEN sm.type = 'deduct' THEN sm.quantity ELSE 0 END), 0) as deducted_quantity,
            COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN sm.type = 'deduct' THEN sm.quantity ELSE 0 END), 0) as total_quantity
        FROM products p
        LEFT JOIN stock_movements sm ON p.id = sm.product_id 
            AND (sm.type = 'out' OR sm.type = 'deduct')
            AND sm.site_location = ?
            AND DATE(sm.created_at) <= ?
            AND (sm.status = 'active' OR sm.status IS NULL OR sm.status = '')
        WHERE p.id IN (
            SELECT DISTINCT product_id 
            FROM stock_movements 
            WHERE (type = 'out' OR type = 'deduct')
            AND site_location = ?
            AND DATE(created_at) <= ?
            AND (status = 'active' OR status IS NULL OR status = '')
        )
        GROUP BY p.id, p.item_no, p.name, p.description, p.category, p.unit
        HAVING total_quantity > 0
        ORDER BY p.item_no ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    $conn->close();
    exit();
}

$stmt->bind_param("ssss", $site_name, $selected_date, $site_name, $selected_date);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$total_quantity = 0;

while ($row = $result->fetch_assoc()) {
    // Get category - prioritize from products, then from canvas_items
    $category = $row['category'];
    $unit = $row['unit'];
    
    // If category is empty, try to get from canvas_items
    if (empty($category) && !empty($row['item_no'])) {
        $canvas_query = $conn->prepare("SELECT category, unit FROM canvas_items WHERE item_no = ?");
        $canvas_query->bind_param("s", $row['item_no']);
        $canvas_query->execute();
        $canvas_result = $canvas_query->get_result();
        if ($canvas_result->num_rows > 0) {
            $canvas_data = $canvas_result->fetch_assoc();
            if (empty($category)) {
                $category = $canvas_data['category'];
            }
            if (empty($unit)) {
                $unit = $canvas_data['unit'];
            }
        }
        $canvas_query->close();
    }
    
    // If still empty, set default
    if (empty($category)) {
        $category = 'Accessories';
    }
    
    // Get unit, default to 'pcs'
    if (empty($unit)) {
        $unit = 'pcs';
    }
    
    // Get description
    $description = $row['description'];
    if (empty($description) && !empty($row['product_name'])) {
        // Try to extract from product name if it has format "Item No - Description"
        if (strpos($row['product_name'], ' - ') !== false) {
            $parts = explode(' - ', $row['product_name'], 2);
            $description = trim($parts[1]);
        } else {
            $description = $row['product_name'];
        }
    }
    
    $quantity = intval($row['total_quantity']);
    
    // Only add items with positive quantity
    if ($quantity > 0) {
        $total_quantity += $quantity;
        
        $items[] = [
            'product_id' => $row['product_id'],
            'item_no' => $row['item_no'] ?: 'N/A',
            'description' => $description ?: 'N/A',
            'category' => $category,
            'unit' => $unit,
            'quantity' => $quantity
        ];
    }
}

echo json_encode([
    'success' => true, 
    'items' => $items,
    'selected_date' => $selected_date,
    'total_quantity' => $total_quantity,
    'total_items' => count($items),
    'message' => count($items) > 0 ? 'Items loaded successfully' : 'No items deployed to this site up to this date'
]);

$stmt->close();
$conn->close();
?>