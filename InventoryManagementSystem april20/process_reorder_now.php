<?php
// process_reorder_now.php - Order Now from Purchase History (FIXED DUPLICATION)
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

// ===== CRITICAL: Use a lock to prevent race conditions =====
$conn->begin_transaction();

try {
    // ===== FIRST CHECK: Lock the purchase row to prevent concurrent updates =====
    $lock_query = "SELECT id, status, stock_updated FROM purchases WHERE id = ? FOR UPDATE";
    $lock_stmt = $conn->prepare($lock_query);
    $lock_stmt->bind_param("i", $purchase_id);
    $lock_stmt->execute();
    $lock_result = $lock_stmt->get_result();
    $current_status = $lock_result->fetch_assoc();
    $lock_stmt->close();
    
    if (!$current_status) {
        throw new Exception("Purchase not found");
    }
    
    // If already completed, return error immediately
    if ($current_status['status'] == 'completed') {
        throw new Exception("This order is already completed.");
    }
    
    // If stock already updated, prevent duplicate
    if ($current_status['stock_updated'] == 1) {
        throw new Exception("Stock for this order has already been updated.");
    }
    
    // ===== SECOND CHECK: Check if stock movement already exists for this purchase =====
    $purchase_number_check = "SELECT purchase_number FROM purchases WHERE id = ?";
    $pn_stmt = $conn->prepare($purchase_number_check);
    $pn_stmt->bind_param("i", $purchase_id);
    $pn_stmt->execute();
    $pn_result = $pn_stmt->get_result();
    $pn_data = $pn_result->fetch_assoc();
    $purchase_number = $pn_data['purchase_number'] ?? '';
    $pn_stmt->close();
    
    if (!empty($purchase_number)) {
        $check_movement = "SELECT id FROM stock_movements WHERE reference = ? AND type = 'in'";
        $movement_ref = "PURCHASE #" . $purchase_number;
        $check_movement_stmt = $conn->prepare($check_movement);
        $check_movement_stmt->bind_param("s", $movement_ref);
        $check_movement_stmt->execute();
        $movement_result = $check_movement_stmt->get_result();
        
        if ($movement_result->num_rows > 0) {
            // Movement already exists - update purchase status but don't create duplicate
            $existing_movement = $movement_result->fetch_assoc();
            $existing_movement_id = $existing_movement['id'];
            
            // Just update the purchase status to completed
            $update_original = "UPDATE purchases SET 
                                status = 'completed', 
                                stock_updated = 1,
                                stock_movement_id = ?,
                                updated_at = NOW() 
                                WHERE id = ?";
            $update_original_stmt = $conn->prepare($update_original);
            $update_original_stmt->bind_param("ii", $existing_movement_id, $purchase_id);
            $update_original_stmt->execute();
            $update_original_stmt->close();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order already processed!',
                'purchase_number' => $purchase_number,
                'movement_id' => $existing_movement_id,
                'already_processed' => true,
                'redirect_url' => "stock_tracker.php?date=" . date('Y-m-d') . "&purchased=success"
            ]);
            exit();
        }
        $check_movement_stmt->close();
    }
    
    // Get the original purchase with all details INCLUDING CATEGORY
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
    $stmt->close();
    
    if (!$original) {
        throw new Exception("Purchase not found");
    }
    
    $correct_item_no = $original['item_no'];
    $correct_description = $original['description'];
    $price_per_unit = floatval($original['price_per_unit']);
    $quantity_purchased = intval($original['quantity_purchased']);
    $price_id = $original['price_id'];
    $company_name = $original['company_name'];
    
    // Get category from canvas_items
    $category = $original['category'] ?? '';
    $unit = $original['unit'] ?? 'pcs';
    
    // If category is still empty, try to get it directly from canvas_items
    if (empty($category) && !empty($correct_item_no)) {
        $cat_query = "SELECT category FROM canvas_items WHERE item_no = ?";
        $cat_stmt = $conn->prepare($cat_query);
        $cat_stmt->bind_param("s", $correct_item_no);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_result->num_rows > 0) {
            $cat_row = $cat_result->fetch_assoc();
            $category = $cat_row['category'] ?? '';
        }
        $cat_stmt->close();
    }
    
    // Check current stock from company_prices
    $stock_query = "SELECT quantity FROM company_prices WHERE id = ? FOR UPDATE";
    $stock_stmt = $conn->prepare($stock_query);
    $stock_stmt->bind_param("i", $price_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $stock_stmt->close();
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
    $update_stmt->close();
    
    // Check if product already exists with this EXACT item_no
    $check_existing = "SELECT id, name, quantity, category FROM products WHERE item_no = ?";
    $check_stmt = $conn->prepare($check_existing);
    $check_stmt->bind_param("s", $correct_item_no);
    $check_stmt->execute();
    $existing_result = $check_stmt->get_result();
    $check_stmt->close();
    
    $product_id = null;
    
    if ($existing_result->num_rows > 0) {
        // Product already exists - UPDATE it
        $existing_product = $existing_result->fetch_assoc();
        $product_id = $existing_product['id'];
        
        $update_fields = ["quantity = quantity + ?"];
        $params = [$quantity_purchased];
        $types = "i";
        
        if (!empty($category) && ($existing_product['category'] != $category)) {
            $update_fields[] = "category = ?";
            $params[] = $category;
            $types .= "s";
        }
        
        $update_fields[] = "updated_at = NOW()";
        $update_sql = "UPDATE products SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $params[] = $product_id;
        $types .= "i";
        
        $update_product_stmt = $conn->prepare($update_sql);
        $update_product_stmt->bind_param($types, ...$params);
        $update_product_stmt->execute();
        $update_product_stmt->close();
        
    } else {
        // Create new product
        $product_name = $correct_item_no . ' - ' . $correct_description;
        
        $insert_product = "INSERT INTO products (name, item_no, description, quantity, unit, price, category, low_stock_threshold, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 10, NOW(), NOW())";
        $insert_product_stmt = $conn->prepare($insert_product);
        $insert_product_stmt->bind_param("sssidsd", $product_name, $correct_item_no, $correct_description, 
                                       $quantity_purchased, $unit, $price_per_unit, $category);
        $insert_product_stmt->execute();
        $product_id = $conn->insert_id;
        $insert_product_stmt->close();
    }
    
    // Insert stock movement
    $reference = "PURCHASE #" . $original['purchase_number'];
    $notes = "Ordered from " . $company_name . " - Qty: " . $quantity_purchased;
    
    // Get the delivery_date from the purchase record
    $delivery_date = $original['delivery_date'];
    $movement_date = null;
    
    if (!empty($delivery_date) && $delivery_date != '0000-00-00') {
        $timestamp = strtotime($delivery_date);
        if ($timestamp !== false && $timestamp > 0) {
            $movement_date = date('Y-m-d H:i:s', $timestamp);
        } else {
            $movement_date = date('Y-m-d H:i:s');
        }
    } else {
        $movement_date = date('Y-m-d H:i:s');
    }
    
    // Insert stock movement
    $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by, created_at) 
                     VALUES (?, 'in', ?, ?, ?, ?, ?)";
    $movement_stmt = $conn->prepare($movement_sql);
    $movement_stmt->bind_param("iissis", $product_id, $quantity_purchased, $reference, $notes, $user_id, $movement_date);
    
    if (!$movement_stmt->execute()) {
        throw new Exception("Failed to insert stock movement: " . $conn->error);
    }
    
    $movement_id = $conn->insert_id;
    $movement_stmt->close();
    
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
    $update_original_stmt->close();
    
    $conn->commit();
    
    $display_date = date('Y-m-d', strtotime($movement_date));
    
    echo json_encode([
        'success' => true,
        'message' => 'Order completed successfully!',
        'purchase_number' => $original['purchase_number'],
        'movement_id' => $movement_id,
        'product_id' => $product_id,
        'item_no' => $correct_item_no,
        'description' => $correct_description,
        'category' => $category,
        'quantity' => $quantity_purchased,
        'date' => $display_date,
        'redirect_url' => "stock_tracker.php?date=" . $display_date . "&purchased=success&movement=" . $movement_id
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