<?php
// update_price.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data (support both FormData and JSON)
$price_id = isset($_POST['price_id']) ? $_POST['price_id'] : null;
$item_no = isset($_POST['item_no']) ? trim($_POST['item_no']) : null;
$description = isset($_POST['description']) ? trim($_POST['description']) : null;
$category = isset($_POST['category']) ? trim($_POST['category']) : '';
$company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : null;
$contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
$contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
$quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
$price = isset($_POST['price']) ? floatval($_POST['price']) : 0;

// Validate required fields
if (!$price_id || !$item_no || !$description || !$company_name || $quantity <= 0 || $price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data - missing required fields']);
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
    // Get current company price data
    $get_price = $conn->query("SELECT cp.*, ci.item_no, ci.description, ci.category, c.name as company_name 
                               FROM company_prices cp
                               INNER JOIN canvas_items ci ON cp.item_id = ci.id
                               INNER JOIN companies c ON cp.company_id = c.id
                               WHERE cp.id = $price_id");
    
    if ($get_price->num_rows === 0) {
        throw new Exception('Price record not found');
    }
    
    $price_data = $get_price->fetch_assoc();
    $item_id = $price_data['item_id'];
    $company_id = $price_data['company_id'];
    
    // ===== FIXED: Handle company properly to prevent duplicates =====
    // Check if company exists (case-insensitive)
    $check_company = $conn->query("SELECT id, contact_person, contact_number FROM companies WHERE LOWER(name) = LOWER('" . $conn->real_escape_string($company_name) . "')");
    
    if ($check_company->num_rows == 0) {
        // Company doesn't exist - create new one
        $insert_company = $conn->query("INSERT INTO companies (name, contact_person, contact_number, status) 
                                        VALUES ('" . $conn->real_escape_string($company_name) . "', 
                                                '" . $conn->real_escape_string($contact_person) . "', 
                                                '" . $conn->real_escape_string($contact_number) . "', 
                                                'active')");
        if (!$insert_company) {
            throw new Exception('Failed to create company: ' . $conn->error);
        }
        $new_company_id = $conn->insert_id;
    } else {
        // Company exists - use existing ID
        $company = $check_company->fetch_assoc();
        $new_company_id = $company['id'];
        
        // Update contact info only if provided and different
        if (!empty($contact_person) || !empty($contact_number)) {
            // Only update if values are different from existing
            $needs_update = false;
            $update_fields = [];
            
            if (!empty($contact_person) && $company['contact_person'] != $contact_person) {
                $update_fields[] = "contact_person = '" . $conn->real_escape_string($contact_person) . "'";
                $needs_update = true;
            }
            
            if (!empty($contact_number) && $company['contact_number'] != $contact_number) {
                $update_fields[] = "contact_number = '" . $conn->real_escape_string($contact_number) . "'";
                $needs_update = true;
            }
            
            if ($needs_update) {
                $update_sql = "UPDATE companies SET " . implode(', ', $update_fields) . " WHERE id = $new_company_id";
                $update_company = $conn->query($update_sql);
                if (!$update_company) {
                    throw new Exception('Failed to update company: ' . $conn->error);
                }
            }
        }
    }
    
    // ===== FIXED: Handle item properly =====
    // Check if item exists (case-insensitive for item_no)
    $check_item = $conn->query("SELECT id, description, category FROM canvas_items WHERE item_no = '" . $conn->real_escape_string($item_no) . "'");
    
    if ($check_item->num_rows == 0) {
        // Item doesn't exist - create new one
        $insert_item = $conn->query("INSERT INTO canvas_items (item_no, description, category) 
                                     VALUES ('" . $conn->real_escape_string($item_no) . "', 
                                             '" . $conn->real_escape_string($description) . "', 
                                             '" . $conn->real_escape_string($category) . "')");
        if (!$insert_item) {
            throw new Exception('Failed to create item: ' . $conn->error);
        }
        $new_item_id = $conn->insert_id;
    } else {
        // Item exists - use existing ID
        $item = $check_item->fetch_assoc();
        $new_item_id = $item['id'];
        
        // Update item details if changed
        $needs_update = false;
        $update_fields = [];
        
        if ($item['description'] != $description) {
            $update_fields[] = "description = '" . $conn->real_escape_string($description) . "'";
            $needs_update = true;
        }
        
        if ($item['category'] != $category) {
            $update_fields[] = "category = '" . $conn->real_escape_string($category) . "'";
            $needs_update = true;
        }
        
        if ($needs_update) {
            $update_sql = "UPDATE canvas_items SET " . implode(', ', $update_fields) . " WHERE id = $new_item_id";
            $update_item = $conn->query($update_sql);
            if (!$update_item) {
                throw new Exception('Failed to update item: ' . $conn->error);
            }
        }
    }
    
    // ===== FIXED: Handle company price update =====
    if ($new_company_id != $company_id || $new_item_id != $item_id) {
        // Company or item changed - check if new combination already exists
        $check_existing = $conn->query("SELECT id FROM company_prices 
                                        WHERE item_id = $new_item_id AND company_id = $new_company_id");
        
        if ($check_existing->num_rows > 0) {
            // Combination exists - update it and delete the old one
            $existing = $check_existing->fetch_assoc();
            
            // Update the existing price
            $update_existing = $conn->query("UPDATE company_prices SET 
                                            quantity = $quantity, 
                                            price = $price, 
                                            availability = 1 
                                            WHERE id = {$existing['id']}");
            if (!$update_existing) {
                throw new Exception('Failed to update existing price: ' . $conn->error);
            }
            
            // Delete the old price record
            if ($existing['id'] != $price_id) {
                $delete_old = $conn->query("DELETE FROM company_prices WHERE id = $price_id");
                if (!$delete_old) {
                    throw new Exception('Failed to delete old price: ' . $conn->error);
                }
            }
        } else {
            // New combination - update the current record with new IDs
            $update_current = $conn->query("UPDATE company_prices SET 
                                           item_id = $new_item_id,
                                           company_id = $new_company_id,
                                           quantity = $quantity, 
                                           price = $price, 
                                           availability = 1 
                                           WHERE id = $price_id");
            if (!$update_current) {
                throw new Exception('Failed to update price with new IDs: ' . $conn->error);
            }
        }
    } else {
        // Same company and item - just update quantity and price
        $update_price = $conn->query("UPDATE company_prices SET 
                                      quantity = $quantity, 
                                      price = $price, 
                                      availability = 1 
                                      WHERE id = $price_id");
        if (!$update_price) {
            throw new Exception('Failed to update price: ' . $conn->error);
        }
    }
    
    // ===== OPTIONAL: Clean up orphaned companies =====
    // If we changed company and the old company has no other prices, delete it
    if ($new_company_id != $company_id) {
        $check_old_company = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE company_id = $company_id");
        $old_company_count = $check_old_company->fetch_assoc()['count'];
        
        if ($old_company_count == 0) {
            $conn->query("DELETE FROM companies WHERE id = $company_id");
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Price updated successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>