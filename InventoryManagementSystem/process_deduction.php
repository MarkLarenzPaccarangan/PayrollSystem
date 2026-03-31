<?php
// process_deduction.php - Process site item deductions (UPDATED)
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if user is logged in
$user_id = null;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
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

// Ensure stock_movements table has proper structure
$conn->query("ALTER TABLE stock_movements MODIFY created_by INT NULL");

// Create deduction_history table if not exists
$create_history_table = "CREATE TABLE IF NOT EXISTS deduction_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    site_name VARCHAR(255) NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255),
    item_no VARCHAR(100),
    quantity_deducted INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reference VARCHAR(100),
    notes TEXT,
    deducted_by INT,
    deducted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deducted_at (deducted_at),
    INDEX idx_site_id (site_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($create_history_table);

// Get POST data
$site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
$site_name = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
$items_json = isset($_POST['items_json']) ? $_POST['items_json'] : '';

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

// Filter out items with zero deduction
$valid_items = array_filter($items, function($item) {
    return isset($item['deduct_qty']) && intval($item['deduct_qty']) > 0;
});

if (empty($valid_items)) {
    echo json_encode(['success' => false, 'message' => 'No valid deduction quantities entered']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    $current_date = date('Y-m-d H:i:s');
    $main_reference = 'DEDUCTION-' . date('YmdHis') . '-' . rand(100, 999);
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $history_records = [];
    $total_deducted = 0;
    
    // Verify site exists
    $site_check_sql = "SELECT id, site_name FROM sites WHERE id = ?";
    $site_check_stmt = $conn->prepare($site_check_sql);
    $site_check_stmt->bind_param("i", $site_id);
    $site_check_stmt->execute();
    $site_result = $site_check_stmt->get_result();
    
    if ($site_result->num_rows == 0) {
        throw new Exception("Site not found in database");
    }
    $site_data = $site_result->fetch_assoc();
    $site_name = $site_data['site_name']; // Use the correct site name from database
    
    foreach ($valid_items as $item) {
        $movement_id = isset($item['movement_id']) ? intval($item['movement_id']) : 0;
        $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
        $deduct_qty = isset($item['deduct_qty']) ? intval($item['deduct_qty']) : 0;
        $current_qty = isset($item['current_qty']) ? intval($item['current_qty']) : 0;
        
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
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        
        if ($product_result->num_rows == 0) {
            $errors[] = "Product not found for ID: $product_id";
            $error_count++;
            continue;
        }
        
        $product = $product_result->fetch_assoc();
        $product_name = $product['name'];
        $item_no = $product['item_no'] ?: 'N/A';
        $current_inventory = intval($product['current_inventory']);
        $unit = $product['unit'] ?? 'pcs';
        
        // ========== STEP 1: Update stock_movements for this site ==========
        // Get all stock_out movements for this product at this site
        $movements_sql = "SELECT id, quantity, created_at 
                          FROM stock_movements 
                          WHERE product_id = ? 
                          AND type = 'out' 
                          AND site_location = ?
                          ORDER BY created_at ASC"; // Oldest first
        $movements_stmt = $conn->prepare($movements_sql);
        $movements_stmt->bind_param("is", $product_id, $site_name);
        $movements_stmt->execute();
        $movements_result = $movements_stmt->get_result();
        
        $remaining_to_deduct = $deduct_qty;
        $movements_updated = [];
        
        while ($movement = $movements_result->fetch_assoc()) {
            if ($remaining_to_deduct <= 0) break;
            
            $movement_qty = intval($movement['quantity']);
            $deduct_from_this = min($remaining_to_deduct, $movement_qty);
            
            if ($deduct_from_this == $movement_qty) {
                // Delete the entire movement record
                $delete_sql = "DELETE FROM stock_movements WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $movement['id']);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Failed to delete movement: " . $delete_stmt->error);
                }
                $movements_updated[] = "Deleted movement ID {$movement['id']} (qty: $movement_qty)";
            } else {
                // Update the movement quantity
                $new_qty = $movement_qty - $deduct_from_this;
                $update_sql = "UPDATE stock_movements SET quantity = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $new_qty, $movement['id']);
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update movement: " . $update_stmt->error);
                }
                $movements_updated[] = "Updated movement ID {$movement['id']}: $movement_qty → $new_qty";
            }
            
            $remaining_to_deduct -= $deduct_from_this;
        }
        
        if ($remaining_to_deduct > 0) {
            $errors[] = "Not enough stock to deduct for product: $item_no (Remaining: $remaining_to_deduct)";
            $error_count++;
            continue;
        }
        
        // ========== STEP 2: Return items to main inventory ==========
        // Create an 'in' movement record
        $return_sql = "INSERT INTO stock_movements 
                       (product_id, type, quantity, reference, notes, site_location, created_by, created_at) 
                       VALUES (?, 'in', ?, ?, ?, ?, ?, ?)";
        $notes = "Returned from site: $site_name. Deducted quantity: $deduct_qty $unit";
        $return_stmt = $conn->prepare($return_sql);
        
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
        
        $return_movement_id = $conn->insert_id;
        
        // ========== STEP 3: Update main product inventory ==========
        $new_inventory = $current_inventory + $deduct_qty;
        $update_product_sql = "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?";
        $update_product_stmt = $conn->prepare($update_product_sql);
        $update_product_stmt->bind_param("ii", $new_inventory, $product_id);
        
        if (!$update_product_stmt->execute()) {
            throw new Exception("Failed to update product inventory: " . $update_product_stmt->error);
        }
        
        // ========== STEP 4: Record in deduction_history ==========
        $new_site_quantity = $current_qty - $deduct_qty;
        
        $history_sql = "INSERT INTO deduction_history 
                        (site_id, site_name, product_id, product_name, item_no, 
                         quantity_deducted, previous_quantity, new_quantity, 
                         reference, notes, deducted_by, deducted_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $history_notes = "Deducted $deduct_qty $unit from site: $site_name. Returned to main inventory. Movement ID: $return_movement_id";
        $history_reference = $main_reference . '-' . $product_id;
        
        $history_stmt = $conn->prepare($history_sql);
        $history_stmt->bind_param("isissiisssis", 
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
            $user_id, 
            $current_date
        );
        
        if (!$history_stmt->execute()) {
            throw new Exception("Failed to save deduction history: " . $history_stmt->error);
        }
        
        $history_id = $conn->insert_id;
        
        // Store for response
        $history_records[] = [
            'history_id' => $history_id,
            'product_id' => $product_id,
            'item_no' => $item_no,
            'product_name' => $product_name,
            'deducted_qty' => $deduct_qty,
            'unit' => $unit,
            'previous_qty' => $current_qty,
            'new_qty' => $new_site_quantity,
            'inventory_before' => $current_inventory,
            'inventory_after' => $new_inventory,
            'movement_updates' => $movements_updated,
            'return_movement_id' => $return_movement_id
        ];
        
        $success_count++;
        $total_deducted += $deduct_qty;
        
        // Close statements
        $product_stmt->close();
        $movements_stmt->close();
        $return_stmt->close();
        $update_product_stmt->close();
        $history_stmt->close();
    }
    
    if ($error_count > 0 && $success_count == 0) {
        // No successful deductions, rollback
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'message' => 'No items were deducted.',
            'errors' => $errors
        ]);
        exit();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Prepare success message
    $message = "Successfully deducted $total_deducted item(s) from $site_name";
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
            'records' => $history_records,
            'errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Deduction Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>