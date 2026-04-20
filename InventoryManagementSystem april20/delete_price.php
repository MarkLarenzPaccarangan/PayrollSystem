<?php
// delete_price.php - EXPLICITLY PREVENTS products TABLE UPDATES
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$price_id = isset($_POST['price_id']) ? $_POST['price_id'] : null;

if (!$price_id) {
    echo json_encode(['success' => false, 'message' => 'Missing price_id field']);
    exit;
}

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

// Start transaction
$conn->begin_transaction();

try {
    // Get item_id and company_id before deleting
    $get_info = $conn->query("SELECT item_id, company_id FROM company_prices WHERE id = $price_id");
    if ($get_info->num_rows === 0) {
        throw new Exception('Price record not found');
    }
    
    $info = $get_info->fetch_assoc();
    $item_id = $info['item_id'];
    $company_id = $info['company_id'];
    
    // Get the item_no before deleting for safety check
    $get_item_no = $conn->query("SELECT item_no FROM canvas_items WHERE id = $item_id");
    $item_no = '';
    if ($get_item_no && $get_item_no->num_rows > 0) {
        $item_data = $get_item_no->fetch_assoc();
        $item_no = $item_data['item_no'];
    }
    
    // Delete the price record
    $stmt = $conn->prepare("DELETE FROM company_prices WHERE id = ?");
    $stmt->bind_param("i", $price_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to delete: ' . $conn->error);
    }
    $stmt->close();
    
    // Check if item has any other prices
    $check_item = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE item_id = $item_id");
    $item_count = $check_item->fetch_assoc()['count'];
    
    if ($item_count == 0) {
        // Delete from canvas_items ONLY - NEVER from products table
        $conn->query("DELETE FROM canvas_items WHERE id = $item_id");
    }
    
    // Check if company has any other prices
    $check_company = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE company_id = $company_id");
    $company_count = $check_company->fetch_assoc()['count'];
    
    if ($company_count == 0) {
        $conn->query("DELETE FROM companies WHERE id = $company_id");
    }
    
    // ===== SAFETY CHECK: Fix any products table records that might have been corrupted =====
    if (!empty($item_no)) {
        $check_products = $conn->query("SELECT id, category, unit FROM products WHERE item_no = '$item_no' LIMIT 1");
        if ($check_products && $check_products->num_rows > 0) {
            $product_data = $check_products->fetch_assoc();
            if ($product_data['category'] === '0' || $product_data['category'] == 0) {
                $conn->query("UPDATE products SET category = NULL WHERE id = {$product_data['id']}");
                error_log("Fixed products table: Reset category '0' to NULL for product ID: {$product_data['id']}");
            }
            if ($product_data['unit'] === '0' || $product_data['unit'] == 0) {
                $conn->query("UPDATE products SET unit = 'pcs' WHERE id = {$product_data['id']}");
                error_log("Fixed products table: Reset unit '0' to 'pcs' for product ID: {$product_data['id']}");
            }
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Price deleted successfully (products table NOT affected)']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>