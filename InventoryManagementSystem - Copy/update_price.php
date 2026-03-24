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
    
    // Update or create company
    $check_company = $conn->query("SELECT id FROM companies WHERE name = '$company_name'");
    if ($check_company->num_rows == 0) {
        // Insert new company
        $insert_company = $conn->query("INSERT INTO companies (name, contact_person, contact_number, status) 
                                        VALUES ('$company_name', '$contact_person', '$contact_number', 'active')");
        if (!$insert_company) {
            throw new Exception('Failed to create company: ' . $conn->error);
        }
        $new_company_id = $conn->insert_id;
    } else {
        $company = $check_company->fetch_assoc();
        $new_company_id = $company['id'];
        
        // Update contact info if provided
        if (!empty($contact_person) || !empty($contact_number)) {
            $update_company = $conn->query("UPDATE companies SET 
                                            contact_person = '$contact_person', 
                                            contact_number = '$contact_number' 
                                            WHERE id = $new_company_id");
            if (!$update_company) {
                throw new Exception('Failed to update company: ' . $conn->error);
            }
        }
    }
    
    // Update or create canvas item
    $check_item = $conn->query("SELECT id FROM canvas_items WHERE item_no = '$item_no'");
    if ($check_item->num_rows == 0) {
        // Insert new canvas item
        $insert_item = $conn->query("INSERT INTO canvas_items (item_no, description, category) 
                                     VALUES ('$item_no', '$description', '$category')");
        if (!$insert_item) {
            throw new Exception('Failed to create item: ' . $conn->error);
        }
        $new_item_id = $conn->insert_id;
    } else {
        $item = $check_item->fetch_assoc();
        $new_item_id = $item['id'];
        
        // Update item details
        $update_item = $conn->query("UPDATE canvas_items SET 
                                     description = '$description', 
                                     category = '$category' 
                                     WHERE id = $new_item_id");
        if (!$update_item) {
            throw new Exception('Failed to update item: ' . $conn->error);
        }
    }
    
    // Update the company price
    if ($new_company_id != $company_id || $new_item_id != $item_id) {
        // If company or item changed, delete old and insert new
        $delete_old = $conn->query("DELETE FROM company_prices WHERE id = $price_id");
        if (!$delete_old) {
            throw new Exception('Failed to delete old price: ' . $conn->error);
        }
        
        // Check if new combination already exists
        $check_existing = $conn->query("SELECT id FROM company_prices 
                                        WHERE item_id = $new_item_id AND company_id = $new_company_id");
        if ($check_existing->num_rows > 0) {
            // Update existing
            $existing = $check_existing->fetch_assoc();
            $update_existing = $conn->query("UPDATE company_prices SET 
                                            quantity = $quantity, 
                                            price = $price, 
                                            availability = 1 
                                            WHERE id = {$existing['id']}");
            if (!$update_existing) {
                throw new Exception('Failed to update existing price: ' . $conn->error);
            }
        } else {
            // Insert new
            $insert_new = $conn->query("INSERT INTO company_prices (item_id, company_id, quantity, price, availability) 
                                        VALUES ($new_item_id, $new_company_id, $quantity, $price, 1)");
            if (!$insert_new) {
                throw new Exception('Failed to insert new price: ' . $conn->error);
            }
        }
    } else {
        // Update existing price record
        $update_price = $conn->query("UPDATE company_prices SET 
                                      quantity = $quantity, 
                                      price = $price, 
                                      availability = 1 
                                      WHERE id = $price_id");
        if (!$update_price) {
            throw new Exception('Failed to update price: ' . $conn->error);
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