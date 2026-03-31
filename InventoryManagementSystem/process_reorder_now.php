<?php
// process_reorder_now.php - Order Now from Purchase History (FIXED - CREATE NEW PRODUCT FOR NEW ITEM NO)
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
    $check_status = "SELECT status, stock_updated FROM purchases WHERE id = ?";
    $check_stmt = $conn->prepare($check_status);
    $check_stmt->bind_param("i", $purchase_id);
    $check_stmt->execute();
    $status_result = $check_stmt->get_result();
    $current_status = $status_result->fetch_assoc();
    
    if ($current_status && $current_status['status'] == 'completed') {
        throw new Exception("This order is already completed.");
    }
    
    if ($current_status && $current_status['stock_updated'] == 1) {
        throw new Exception("Stock for this order has already been updated.");
    }
    
    // Get the original purchase with all details
    $query = "SELECT p.*, cp.quantity as current_stock, ci.unit, ci.category, ci.id as canvas_item_id
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
    
    // Debug: Log the original purchase data
    error_log("=== REORDER NOW - Original purchase data: " . print_r($original, true));
    
    $correct_item_no = $original['item_no'];
    $correct_description = $original['description'];
    $price_per_unit = floatval($original['price_per_unit']);
    $quantity_purchased = intval($original['quantity_purchased']);
    $price_id = $original['price_id'];
    $company_name = $original['company_name'];
    $category = $original['category'];
    $unit = $original['unit'] ?? 'pcs';
    
    error_log("=== REORDER NOW - Purchase item_no: " . $correct_item_no);
    
    // Check current stock from company_prices
    $stock_query = "SELECT quantity FROM company_prices WHERE id = ?";
    $stock_stmt = $conn->prepare($stock_query);
    $stock_stmt->bind_param("i", $price_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $current_stock = $stock_data['quantity'] ?? 0;
    
    if ($current_stock < $quantity_purchased) {
        throw new Exception("Insufficient stock. Available: " . $current_stock);
    }
    
    // Calculate new stock
    $new_stock = $current_stock - $quantity_purchased;
    
    // Update company_prices stock
    $update_stock = "UPDATE company_prices SET quantity = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_stock);
    $update_stmt->bind_param("ii", $new_stock, $price_id);
    $update_stmt->execute();
    
    // ========== CRITICAL FIX: ALWAYS CREATE NEW PRODUCT FOR EACH UNIQUE ITEM NO ==========
    // Check if product already exists with this EXACT item_no
    $check_existing = "SELECT id, name, quantity FROM products WHERE item_no = ?";
    $check_stmt = $conn->prepare($check_existing);
    $check_stmt->bind_param("s", $correct_item_no);
    $check_stmt->execute();
    $existing_result = $check_stmt->get_result();
    
    $product_id = null;
    
    if ($existing_result->num_rows > 0) {
        // Product already exists with this item_no - UPDATE it (add stock)
        $existing_product = $existing_result->fetch_assoc();
        $product_id = $existing_product['id'];
        
        // Update existing product stock
        $update_product = "UPDATE products SET 
                           quantity = quantity + ?,
                           updated_at = NOW() 
                           WHERE id = ?";
        $update_product_stmt = $conn->prepare($update_product);
        $update_product_stmt->bind_param("ii", $quantity_purchased, $product_id);
        $update_product_stmt->execute();
        
        error_log("=== UPDATED existing product with item_no: $correct_item_no, ID: $product_id, New quantity: " . ($existing_product['quantity'] + $quantity_purchased));
        
    } else {
        // ========== CREATE NEW PRODUCT FOR THIS ITEM NO ==========
        // This ensures we preserve the old product (no.90) and create a new one (no.91)
        $product_name = $correct_item_no . ' - ' . $correct_description;
        
        $insert_product = "INSERT INTO products (name, item_no, description, quantity, unit, price, category, low_stock_threshold, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 10, NOW(), NOW())";
        $insert_product_stmt = $conn->prepare($insert_product);
        $insert_product_stmt->bind_param("sssidsd", $product_name, $correct_item_no, $correct_description, 
                                       $quantity_purchased, $unit, $price_per_unit, $category);
        $insert_product_stmt->execute();
        $product_id = $conn->insert_id;
        
        error_log("=== CREATED NEW product with item_no: $correct_item_no, ID: $product_id");
    }
    
    // Verify the product was created/updated correctly
    $verify_sql = "SELECT id, item_no, description, quantity FROM products WHERE id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("i", $product_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    $verified_product = $verify_result->fetch_assoc();
    error_log("=== VERIFIED product: ID: {$verified_product['id']}, item_no: {$verified_product['item_no']}, quantity: {$verified_product['quantity']}");
    
    // ========== Handle date for stock movement ==========
    $reference = "PURCHASE #" . $original['purchase_number'];
    $notes = "Ordered from " . $company_name . " - Qty: " . $quantity_purchased;
    
    // Get the delivery_date from the purchase record
    $delivery_date = $original['delivery_date'];
    $movement_date = null;
    
    // Format the date properly
    if (!empty($delivery_date) && $delivery_date != '0000-00-00') {
        $timestamp = strtotime($delivery_date);
        if ($timestamp !== false && $timestamp > 0) {
            $movement_date = date('Y-m-d H:i:s', $timestamp);
            error_log("=== Using delivery_date: $delivery_date -> $movement_date");
        } else {
            $movement_date = date('Y-m-d H:i:s');
            error_log("=== Failed to parse delivery_date, using current time: $movement_date");
        }
    } else {
        if (!empty($original['purchase_date']) && $original['purchase_date'] != '0000-00-00 00:00:00') {
            $timestamp = strtotime($original['purchase_date']);
            if ($timestamp !== false && $timestamp > 0) {
                $movement_date = date('Y-m-d H:i:s', $timestamp);
                error_log("=== Using purchase_date: " . $original['purchase_date'] . " -> $movement_date");
            } else {
                $movement_date = date('Y-m-d H:i:s');
                error_log("=== Using current time as fallback: $movement_date");
            }
        } else {
            $movement_date = date('Y-m-d H:i:s');
            error_log("=== No valid date, using current time: $movement_date");
        }
    }
    
    // Insert stock movement with the determined date
    $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by, created_at) 
                     VALUES (?, 'in', ?, ?, ?, ?, ?)";
    $movement_stmt = $conn->prepare($movement_sql);
    $movement_stmt->bind_param("iissis", $product_id, $quantity_purchased, $reference, $notes, $user_id, $movement_date);
    
    if (!$movement_stmt->execute()) {
        throw new Exception("Failed to insert stock movement: " . $conn->error);
    }
    
    $movement_id = $conn->insert_id;
    error_log("=== Stock movement inserted with ID: $movement_id, created_at: $movement_date, product_id: $product_id");
    
    // UPDATE THE ORIGINAL PURCHASE TO COMPLETED
    $update_original = "UPDATE purchases SET 
                        status = 'completed', 
                        stock_updated = 1,
                        stock_movement_id = ?,
                        product_id = ?,
                        available_after = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
    $update_original_stmt = $conn->prepare($update_original);
    $update_original_stmt->bind_param("iiii", $movement_id, $product_id, $new_stock, $purchase_id);
    $update_original_stmt->execute();
    
    $conn->commit();
    
    // Format the date for redirect
    $display_date = date('Y-m-d', strtotime($movement_date));
    
    echo json_encode([
        'success' => true,
        'message' => 'Order completed successfully!',
        'purchase_number' => $original['purchase_number'],
        'movement_id' => $movement_id,
        'product_id' => $product_id,
        'item_no' => $correct_item_no,
        'description' => $correct_description,
        'quantity' => $quantity_purchased,
        'date' => $display_date,
        'redirect_url' => "stock_tracker.php?date=" . $display_date . "&purchased=success&movement=" . $movement_id,
        'verified_item_no' => $verified_product['item_no']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    error_log("=== REORDER NOW ERROR: " . $e->getMessage());
}

$conn->close();
?>