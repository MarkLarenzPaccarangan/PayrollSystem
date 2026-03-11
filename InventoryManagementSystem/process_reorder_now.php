<?php
// process_reorder_now.php - Order Now from Purchase History (FIXED - NO DUPLICATES)
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get current user
$current_user = getCurrentUser();
$user_id = $current_user['id'] ?? 3;

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$purchase_id = intval($input['purchase_id'] ?? 0);

if (!$purchase_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Check if purchase is already completed
    $check_status = "SELECT status FROM purchases WHERE id = ?";
    $check_stmt = $conn->prepare($check_status);
    $check_stmt->bind_param("i", $purchase_id);
    $check_stmt->execute();
    $status_result = $check_stmt->get_result();
    $current_status = $status_result->fetch_assoc();
    
    if ($current_status && $current_status['status'] == 'completed') {
        throw new Exception("This order is already completed.");
    }
    
    // Get the original purchase with all details
    $query = "SELECT p.*, cp.quantity as current_stock, ci.unit 
              FROM purchases p
              LEFT JOIN company_prices cp ON p.price_id = cp.id
              LEFT JOIN canvas_items ci ON cp.item_id = ci.id
              WHERE p.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $purchase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $original = $result->fetch_assoc();
    
    if (!$original) {
        throw new Exception("Purchase not found");
    }
    
    // Check current stock from company_prices
    $stock_query = "SELECT quantity FROM company_prices WHERE id = ?";
    $stock_stmt = $conn->prepare($stock_query);
    $stock_stmt->bind_param("i", $original['price_id']);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $current_stock = $stock_data['quantity'] ?? 0;
    
    if ($current_stock < $original['quantity_purchased']) {
        throw new Exception("Insufficient stock. Available: " . $current_stock);
    }
    
    // Calculate new stock
    $new_stock = $current_stock - $original['quantity_purchased'];
    
    // Update company_prices stock
    $update_stock = "UPDATE company_prices SET quantity = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_stock);
    $update_stmt->bind_param("ii", $new_stock, $original['price_id']);
    $update_stmt->execute();
    
    // Check/Create product in products table
    $check_product = "SELECT id, name, quantity FROM products WHERE item_no = ? OR description LIKE ? LIMIT 1";
    $search_desc = "%" . $original['description'] . "%";
    $check_stmt = $conn->prepare($check_product);
    $check_stmt->bind_param("ss", $original['item_no'], $search_desc);
    $check_stmt->execute();
    $product_result = $check_stmt->get_result();
    
    $unit = $original['unit'] ?? 'pcs';
    $product_id = null;
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $product_id = $product['id'];
        
        // Update existing product stock
        $update_product = "UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?";
        $update_product_stmt = $conn->prepare($update_product);
        $update_product_stmt->bind_param("ii", $original['quantity_purchased'], $product_id);
        $update_product_stmt->execute();
    } else {
        // Create new product
        $product_name = $original['item_no'] . ' - ' . $original['description'];
        
        // Check if price column exists
        $price_check = $conn->query("SHOW COLUMNS FROM products LIKE 'price'");
        $has_price_column = $price_check->num_rows > 0;
        
        if ($has_price_column) {
            $insert_product = "INSERT INTO products (name, item_no, description, quantity, unit, price, low_stock_threshold, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, 10, NOW(), NOW())";
            $insert_product_stmt = $conn->prepare($insert_product);
            $insert_product_stmt->bind_param("sssisd", $product_name, $original['item_no'], $original['description'], 
                                           $original['quantity_purchased'], $unit, $original['price_per_unit']);
        } else {
            $insert_product = "INSERT INTO products (name, item_no, description, quantity, unit, low_stock_threshold, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, 10, NOW(), NOW())";
            $insert_product_stmt = $conn->prepare($insert_product);
            $insert_product_stmt->bind_param("sssis", $product_name, $original['item_no'], $original['description'], 
                                           $original['quantity_purchased'], $unit);
        }
        
        $insert_product_stmt->execute();
        $product_id = $conn->insert_id;
    }
    
    // Create stock movement
    $reference = "PURCHASE #" . $original['purchase_number'];
    $notes = "Ordered from " . $original['company_name'] . " - Qty: " . $original['quantity_purchased'];
    
    $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by, created_at) 
                     VALUES (?, 'in', ?, ?, ?, ?, NOW())";
    $movement_stmt = $conn->prepare($movement_sql);
    $movement_stmt->bind_param("iissi", $product_id, $original['quantity_purchased'], $reference, $notes, $user_id);
    $movement_stmt->execute();
    $movement_id = $conn->insert_id;
    
    // UPDATE THE ORIGINAL PURCHASE TO COMPLETED (HUWAG nang gumawa ng bago)
    $update_original = "UPDATE purchases SET 
                        status = 'completed', 
                        stock_movement_id = ?,
                        available_after = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
    $update_original_stmt = $conn->prepare($update_original);
    $update_original_stmt->bind_param("iii", $movement_id, $new_stock, $purchase_id);
    $update_original_stmt->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order completed successfully!',
        'purchase_number' => $original['purchase_number'],
        'movement_id' => $movement_id,
        'item_no' => $original['item_no'],
        'description' => $original['description'],
        'quantity' => $original['quantity_purchased'],
        'date' => date('Y-m-d')
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>