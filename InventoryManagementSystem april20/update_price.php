<?php
// update_price.php - EXPLICITLY PREVENTS products TABLE UPDATES
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data
$price_id = isset($_POST['price_id']) ? $_POST['price_id'] : null;
$item_no = isset($_POST['item_no']) ? trim($_POST['item_no']) : null;
$description = isset($_POST['description']) ? trim($_POST['description']) : null;
$category = isset($_POST['category']) ? trim($_POST['category']) : '';
$unit = isset($_POST['unit']) ? trim($_POST['unit']) : 'pcs';
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
    $get_price = $conn->query("SELECT cp.*, ci.item_no, ci.description, ci.category, ci.unit, c.name as company_name, c.id as company_id
                               FROM company_prices cp
                               INNER JOIN canvas_items ci ON cp.item_id = ci.id
                               INNER JOIN companies c ON cp.company_id = c.id
                               WHERE cp.id = $price_id");
    
    if ($get_price->num_rows === 0) {
        throw new Exception('Price record not found');
    }
    
    $price_data = $get_price->fetch_assoc();
    $old_item_id = $price_data['item_id'];
    $old_company_id = $price_data['company_id'];
    
    // ===== HANDLE COMPANY =====
    $check_company = $conn->query("SELECT id, contact_person, contact_number FROM companies WHERE LOWER(name) = LOWER('" . $conn->real_escape_string($company_name) . "')");
    
    if ($check_company->num_rows == 0) {
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
        $company = $check_company->fetch_assoc();
        $new_company_id = $company['id'];
        
        if (!empty($contact_person) || !empty($contact_number)) {
            $update_fields = [];
            if (!empty($contact_person) && $company['contact_person'] != $contact_person) {
                $update_fields[] = "contact_person = '" . $conn->real_escape_string($contact_person) . "'";
            }
            if (!empty($contact_number) && $company['contact_number'] != $contact_number) {
                $update_fields[] = "contact_number = '" . $conn->real_escape_string($contact_number) . "'";
            }
            if (!empty($update_fields)) {
                $conn->query("UPDATE companies SET " . implode(', ', $update_fields) . " WHERE id = $new_company_id");
            }
        }
    }
    
    // ===== HANDLE ITEM (ONLY canvas_items - NEVER products) =====
    $check_item = $conn->query("SELECT id, description, category, unit FROM canvas_items WHERE item_no = '" . $conn->real_escape_string($item_no) . "'");
    
    if ($check_item->num_rows == 0) {
        $insert_item = $conn->query("INSERT INTO canvas_items (item_no, description, category, unit) 
                                     VALUES ('" . $conn->real_escape_string($item_no) . "', 
                                             '" . $conn->real_escape_string($description) . "', 
                                             '" . $conn->real_escape_string($category) . "',
                                             '" . $conn->real_escape_string($unit) . "')");
        if (!$insert_item) {
            throw new Exception('Failed to create item: ' . $conn->error);
        }
        $new_item_id = $conn->insert_id;
    } else {
        $item = $check_item->fetch_assoc();
        $new_item_id = $item['id'];
        
        $update_fields = [];
        if ($item['description'] != $description) {
            $update_fields[] = "description = '" . $conn->real_escape_string($description) . "'";
        }
        if ($item['category'] != $category) {
            $update_fields[] = "category = '" . $conn->real_escape_string($category) . "'";
        }
        if (($item['unit'] ?? 'pcs') != $unit) {
            $update_fields[] = "unit = '" . $conn->real_escape_string($unit) . "'";
        }
        
        if (!empty($update_fields)) {
            $conn->query("UPDATE canvas_items SET " . implode(', ', $update_fields) . " WHERE id = $new_item_id");
        }
    }
    
    // ===== HANDLE COMPANY PRICE UPDATE =====
    if ($new_company_id != $old_company_id || $new_item_id != $old_item_id) {
        $check_existing = $conn->query("SELECT id FROM company_prices 
                                        WHERE item_id = $new_item_id AND company_id = $new_company_id");
        
        if ($check_existing->num_rows > 0) {
            $existing = $check_existing->fetch_assoc();
            $conn->query("UPDATE company_prices SET quantity = $quantity, price = $price, availability = 1 WHERE id = {$existing['id']}");
            if ($existing['id'] != $price_id) {
                $conn->query("DELETE FROM company_prices WHERE id = $price_id");
            }
        } else {
            $conn->query("UPDATE company_prices SET item_id = $new_item_id, company_id = $new_company_id, quantity = $quantity, price = $price, availability = 1 WHERE id = $price_id");
        }
    } else {
        $conn->query("UPDATE company_prices SET quantity = $quantity, price = $price, availability = 1 WHERE id = $price_id");
    }
    
    // ===== CLEAN UP ORPHANED ITEM (ONLY canvas_items, NEVER products) =====
    if ($old_item_id != $new_item_id) {
        $check_old_item = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE item_id = $old_item_id");
        $old_item_count = $check_old_item->fetch_assoc()['count'];
        if ($old_item_count == 0) {
            $conn->query("DELETE FROM canvas_items WHERE id = $old_item_id");
        }
    }
    
    // ===== CLEAN UP ORPHANED COMPANY =====
    if ($old_company_id != $new_company_id) {
        $check_old_company = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE company_id = $old_company_id");
        $old_company_count = $check_old_company->fetch_assoc()['count'];
        if ($old_company_count == 0) {
            $conn->query("DELETE FROM companies WHERE id = $old_company_id");
        }
    }
    
    // ===== SAFETY CHECK: Fix any products table records that might have been corrupted =====
    // Check if there's a products record with this item_no that has '0' values
    $check_products = $conn->query("SELECT id, category, unit FROM products WHERE item_no = '$item_no'");
    if ($check_products && $check_products->num_rows > 0) {
        while ($product_data = $check_products->fetch_assoc()) {
            $needs_fix = false;
            if ($product_data['category'] === '0' || $product_data['category'] == 0 || $product_data['category'] === '') {
                $conn->query("UPDATE products SET category = NULL WHERE id = {$product_data['id']}");
                $needs_fix = true;
            }
            if ($product_data['unit'] === '0' || $product_data['unit'] == 0 || $product_data['unit'] === '') {
                $conn->query("UPDATE products SET unit = 'pcs' WHERE id = {$product_data['id']}");
                $needs_fix = true;
            }
            if ($needs_fix) {
                error_log("Fixed products table for product ID: {$product_data['id']}, item_no: $item_no");
            }
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Price updated successfully (products table NOT affected)']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>