<?php
// process_deduction.php - Process site item deductions with REMARKS
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Disable error display in output (log errors instead)
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Enable error logging
error_log("=== process_deduction.php called ===");

// Check if user is logged in
$user_id = null;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    error_log("User ID: " . $user_id);
} else {
    error_log("No user logged in");
}

// Database connection
$host = DB_HOST;
$username = DB_USER;
$password = DB_PASS;
$database = DB_NAME;

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}
error_log("Database connected successfully");

// Check if remarks column exists, if not add it
$check_column_sql = "SHOW COLUMNS FROM deduction_history LIKE 'remarks'";
$check_result = $conn->query($check_column_sql);
if ($check_result && $check_result->num_rows == 0) {
    $add_remarks_column = "ALTER TABLE deduction_history ADD COLUMN remarks TEXT AFTER notes";
    $conn->query($add_remarks_column);
    error_log("Added remarks column to deduction_history");
}

// Get POST data
$site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
$site_name = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
$items_json = isset($_POST['items_json']) ? $_POST['items_json'] : '';
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

error_log("POST data - site_id: $site_id, site_name: $site_name, remarks: $remarks");
error_log("items_json: " . $items_json);

// Validate input
if ($site_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit();
}

if (empty($site_name)) {
    echo json_encode(['success' => false, 'message' => 'Site name is required']);
    exit();
}

if (empty($items_json)) {
    echo json_encode(['success' => false, 'message' => 'No items to deduct']);
    exit();
}

// Decode items
$items = json_decode($items_json, true);
if (!$items || !is_array($items) || count($items) == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid items data']);
    exit();
}

error_log("Items decoded: " . print_r($items, true));

// Filter out items with zero deduction
$valid_items = array_filter($items, function($item) {
    return isset($item['deduct_qty']) && intval($item['deduct_qty']) > 0;
});

if (empty($valid_items)) {
    echo json_encode(['success' => false, 'message' => 'No valid deduction quantities entered']);
    exit();
}

error_log("Valid items: " . print_r($valid_items, true));

// Start transaction
$conn->begin_transaction();
error_log("Transaction started");

try {
    $current_date = date('Y-m-d H:i:s');
    $main_reference = 'DEDUCTION-' . date('YmdHis') . '-' . rand(100, 999);
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $total_deducted = 0;
    
    // Verify site exists
    $site_check_sql = "SELECT id, site_name FROM sites WHERE id = ?";
    $site_check_stmt = $conn->prepare($site_check_sql);
    if (!$site_check_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $site_check_stmt->bind_param("i", $site_id);
    $site_check_stmt->execute();
    $site_result = $site_check_stmt->get_result();
    
    if ($site_result->num_rows == 0) {
        throw new Exception("Site not found in database");
    }
    $site_data = $site_result->fetch_assoc();
    $site_name = $site_data['site_name'];
    error_log("Site verified: " . $site_name);
    $site_check_stmt->close();
    
    foreach ($valid_items as $index => $item) {
        $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
        $deduct_qty = isset($item['deduct_qty']) ? intval($item['deduct_qty']) : 0;
        $current_qty = isset($item['current_qty']) ? intval($item['current_qty']) : 0;
        
        error_log("Processing item $index - product_id: $product_id, deduct_qty: $deduct_qty, current_qty: $current_qty");
        
        if ($deduct_qty <= 0) {
            continue;
        }
        
        if ($product_id <= 0) {
            $errors[] = "Invalid product ID";
            $error_count++;
            continue;
        }
        
        if ($deduct_qty > $current_qty) {
            $errors[] = "Cannot deduct more than available quantity for product ID: $product_id (Available: $current_qty, Requested: $deduct_qty)";
            $error_count++;
            continue;
        }
        
        // Get product details
        $product_sql = "SELECT id, name, item_no, description, quantity as current_inventory, unit, price FROM products WHERE id = ?";
        $product_stmt = $conn->prepare($product_sql);
        if (!$product_stmt) {
            throw new Exception("Prepare product failed: " . $conn->error);
        }
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        
        if ($product_result->num_rows == 0) {
            $errors[] = "Product not found for ID: $product_id";
            $error_count++;
            $product_stmt->close();
            continue;
        }
        
      $product = $product_result->fetch_assoc();
// Remove item number prefix from product name (e.g., "92 - CAT" -> "CAT")
$raw_product_name = $product['name'];
// Remove pattern like "XX - " or "XX - " from the beginning
$clean_product_name = preg_replace('/^\d+\s*-\s*/', '', $raw_product_name);
// If after cleaning it's empty, use the original name
$product_name = !empty(trim($clean_product_name)) ? $clean_product_name : $raw_product_name;
$item_no = $product['item_no'] ?: 'N/A';
$current_inventory = intval($product['current_inventory']);
$unit = $product['unit'] ?? 'pcs';

error_log("Product found - Original: $raw_product_name, Cleaned: $product_name, current inventory: $current_inventory");
$product_stmt->close();
        
        // ========== STEP 1: Update stock_movements for this site ==========
        $remaining_to_deduct = $deduct_qty;
        
        // Get all stock_out movements for this product at this site, oldest first
        $movements_sql = "SELECT id, quantity FROM stock_movements 
                          WHERE product_id = ? AND type = 'out' AND site_location = ?
                          ORDER BY created_at ASC";
        $movements_stmt = $conn->prepare($movements_sql);
        if (!$movements_stmt) {
            throw new Exception("Prepare movements failed: " . $conn->error);
        }
        $movements_stmt->bind_param("is", $product_id, $site_name);
        $movements_stmt->execute();
        $movements_result = $movements_stmt->get_result();
        
        while ($movement = $movements_result->fetch_assoc()) {
            if ($remaining_to_deduct <= 0) break;
            
            $movement_qty = intval($movement['quantity']);
            $deduct_from_this = min($remaining_to_deduct, $movement_qty);
            
            error_log("Movement ID {$movement['id']} - qty: $movement_qty, deducting: $deduct_from_this");
            
            if ($deduct_from_this == $movement_qty) {
                // Delete the entire movement record
                $delete_sql = "DELETE FROM stock_movements WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                if (!$delete_stmt) {
                    throw new Exception("Prepare delete failed: " . $conn->error);
                }
                $delete_stmt->bind_param("i", $movement['id']);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Failed to delete movement: " . $delete_stmt->error);
                }
                $delete_stmt->close();
                error_log("Deleted movement ID {$movement['id']}");
            } else {
                // Update the movement quantity
                $new_qty = $movement_qty - $deduct_from_this;
                $update_sql = "UPDATE stock_movements SET quantity = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare update failed: " . $conn->error);
                }
                $update_stmt->bind_param("ii", $new_qty, $movement['id']);
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update movement: " . $update_stmt->error);
                }
                $update_stmt->close();
                error_log("Updated movement ID {$movement['id']}: $movement_qty -> $new_qty");
            }
            
            $remaining_to_deduct -= $deduct_from_this;
        }
        $movements_stmt->close();
        
        if ($remaining_to_deduct > 0) {
            $errors[] = "Not enough stock to deduct for product: $item_no (Remaining to deduct: $remaining_to_deduct)";
            $error_count++;
            continue;
        }
        
        // ========== STEP 2: Return items to main inventory ==========
        $return_sql = "INSERT INTO stock_movements 
                       (product_id, type, quantity, reference, notes, site_location, created_by, created_at) 
                       VALUES (?, 'in', ?, ?, ?, ?, ?, ?)";
        $return_stmt = $conn->prepare($return_sql);
        if (!$return_stmt) {
            throw new Exception("Prepare return failed: " . $conn->error);
        }
        
        $notes = "Returned from site: $site_name. Deducted quantity: $deduct_qty $unit";
        $return_stmt->bind_param("iisssis", 
            $product_id, 
            $deduct_qty, 
            $main_reference, 
            $notes, 
            $site_name, 
            $user_id, 
            $current_date
        );
        
        if (!$return_stmt->execute()) {
            throw new Exception("Failed to create return movement: " . $return_stmt->error);
        }
        error_log("Return movement created, ID: " . $conn->insert_id);
        $return_stmt->close();
        
        // ========== STEP 3: Update main product inventory ==========
        $new_inventory = $current_inventory + $deduct_qty;
        $update_product_sql = "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?";
        $update_product_stmt = $conn->prepare($update_product_sql);
        if (!$update_product_stmt) {
            throw new Exception("Prepare product update failed: " . $conn->error);
        }
        $update_product_stmt->bind_param("ii", $new_inventory, $product_id);
        
        if (!$update_product_stmt->execute()) {
            throw new Exception("Failed to update product inventory: " . $update_product_stmt->error);
        }
        error_log("Product inventory updated: $current_inventory -> $new_inventory");
        $update_product_stmt->close();
        
        // ========== STEP 4: Record in deduction_history with REMARKS ==========
        $new_site_quantity = max(0, $current_qty - $deduct_qty);
        
        $history_sql = "INSERT INTO deduction_history 
                        (site_id, site_name, product_id, product_name, item_no, 
                         quantity_deducted, previous_quantity, new_quantity, 
                         reference, notes, remarks, deducted_by, deducted_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $history_stmt = $conn->prepare($history_sql);
        if (!$history_stmt) {
            throw new Exception("Prepare history failed: " . $conn->error);
        }
        
        $history_notes = "Deducted $deduct_qty $unit from site: $site_name. Returned to main inventory.";
        $history_reference = $main_reference . '-' . $product_id;
        
        $history_stmt->bind_param("isissiissssi", 
            $site_id, 
            $site_name, 
            $product_id, 
            $product_name, 
            $item_no,
            $deduct_qty, 
            $current_qty, 
            $new_site_quantity,
            $history_reference, 
            $history_notes, 
            $remarks,
            $user_id
        );
        
        if (!$history_stmt->execute()) {
            throw new Exception("Failed to save deduction history: " . $history_stmt->error);
        }
        error_log("Deduction history recorded, ID: " . $conn->insert_id);
        $history_stmt->close();
        
        $success_count++;
        $total_deducted += $deduct_qty;
    }
    
    if ($error_count > 0 && $success_count == 0) {
        $conn->rollback();
        error_log("Transaction rolled back - no successful deductions");
        echo json_encode([
            'success' => false, 
            'message' => 'No items were deducted.',
            'errors' => $errors
        ]);
        exit();
    }
    
    $conn->commit();
    error_log("Transaction committed successfully");
    
    $message = "Successfully deducted " . $total_deducted . " item(s) from " . $site_name;
    if ($success_count > 0) {
        $message .= " (" . $success_count . " product(s) updated)";
    }
    if ($error_count > 0) {
        $message .= ". " . $error_count . " product(s) failed: " . implode("; ", $errors);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'details' => [
            'site_id' => $site_id,
            'site_name' => $site_name,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'total_deducted' => $total_deducted,
            'reference' => $main_reference,
            'remarks' => $remarks
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Exception caught: " . $e->getMessage());
    error_log("Exception trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
error_log("=== process_deduction.php finished ===");
?>