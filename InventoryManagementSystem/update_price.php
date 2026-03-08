<?php
// update_price.php
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit();
}

// Validate required fields
$required = ['price_id', 'item_no', 'description', 'company_name', 'quantity', 'price'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$price_id = intval($input['price_id']);
$item_no = $conn->real_escape_string($input['item_no']);
$description = $conn->real_escape_string($input['description']);
$company_name = $conn->real_escape_string($input['company_name']);
$contact_person = $conn->real_escape_string($input['contact_person'] ?? '');
$contact_number = $conn->real_escape_string($input['contact_number'] ?? '');
$quantity = intval($input['quantity']);
$price = floatval($input['price']);

// Start transaction
$conn->begin_transaction();

try {
    // Get current price record to get item_id and company_id
    $get_current = $conn->query("SELECT item_id, company_id FROM company_prices WHERE id = $price_id");
    if ($get_current->num_rows === 0) {
        throw new Exception("Price record not found");
    }
    
    $current = $get_current->fetch_assoc();
    $item_id = $current['item_id'];
    $company_id = $current['company_id'];
    
    // Update or create canvas item
    // Check if item_no exists (excluding current item)
    $check_item = $conn->query("SELECT id FROM canvas_items WHERE item_no = '$item_no' AND id != $item_id");
    if ($check_item->num_rows > 0) {
        // Item exists elsewhere, use that item_id instead
        $existing_item = $check_item->fetch_assoc();
        $new_item_id = $existing_item['id'];
        
        // Update company_prices to use new item_id
        $update_price_item = $conn->query("UPDATE company_prices SET item_id = $new_item_id WHERE id = $price_id");
        if (!$update_price_item) {
            throw new Exception("Failed to update item reference");
        }
        
        // Delete old canvas item if not used by other prices
        $check_other_prices = $conn->query("SELECT id FROM company_prices WHERE item_id = $item_id AND id != $price_id");
        if ($check_other_prices->num_rows === 0) {
            $conn->query("DELETE FROM canvas_items WHERE id = $item_id");
        }
        
        $item_id = $new_item_id;
    } else {
        // Update existing canvas item
        $update_item = $conn->query("UPDATE canvas_items SET item_no = '$item_no', description = '$description' WHERE id = $item_id");
        if (!$update_item) {
            throw new Exception("Failed to update item: " . $conn->error);
        }
    }
    
    // Update or create company
    // Check if company exists (excluding current company)
    $check_company = $conn->query("SELECT id FROM companies WHERE name = '$company_name' AND id != $company_id");
    if ($check_company->num_rows > 0) {
        // Company exists elsewhere, use that company_id instead
        $existing_company = $check_company->fetch_assoc();
        $new_company_id = $existing_company['id'];
        
        // Update company_prices to use new company_id
        $update_price_company = $conn->query("UPDATE company_prices SET company_id = $new_company_id WHERE id = $price_id");
        if (!$update_price_company) {
            throw new Exception("Failed to update company reference");
        }
        
        // Update contact info of existing company if provided
        if (!empty($contact_person) || !empty($contact_number)) {
            $conn->query("UPDATE companies SET contact_person = '$contact_person', contact_number = '$contact_number' WHERE id = $new_company_id");
        }
        
        // Delete old company if not used by other prices
        $check_other_prices = $conn->query("SELECT id FROM company_prices WHERE company_id = $company_id AND id != $price_id");
        if ($check_other_prices->num_rows === 0) {
            $conn->query("DELETE FROM companies WHERE id = $company_id");
        }
        
        $company_id = $new_company_id;
    } else {
        // Update existing company
        $update_company = $conn->query("UPDATE companies SET name = '$company_name', contact_person = '$contact_person', contact_number = '$contact_number' WHERE id = $company_id");
        if (!$update_company) {
            throw new Exception("Failed to update company: " . $conn->error);
        }
    }
    
    // Update company price
    $update_price = $conn->query("UPDATE company_prices SET quantity = $quantity, price = $price, availability = 1 WHERE id = $price_id");
    if (!$update_price) {
        throw new Exception("Failed to update price: " . $conn->error);
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Price updated successfully'
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