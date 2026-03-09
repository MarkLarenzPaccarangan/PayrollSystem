<?php
// delete_price.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Log the received data for debugging
error_log("Delete request received: " . print_r($input, true));

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data - no JSON received']);
    exit();
}

if (!isset($input['price_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing price_id field']);
    exit();
}

$price_id = intval($input['price_id']);

if ($price_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid price ID']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // First, get the item_id and company_id before deleting
    $get_ids = $conn->query("SELECT item_id, company_id FROM company_prices WHERE id = $price_id");
    
    if (!$get_ids) {
        throw new Exception("Error getting price details: " . $conn->error);
    }
    
    if ($get_ids->num_rows === 0) {
        throw new Exception("Price record not found with ID: $price_id");
    }
    
    $ids = $get_ids->fetch_assoc();
    $item_id = $ids['item_id'];
    $company_id = $ids['company_id'];
    
    // Check if this price is used in any pending purchases
    $check_purchases = $conn->query("SELECT id FROM purchases WHERE price_id = $price_id AND status = 'pending'");
    
    if ($check_purchases && $check_purchases->num_rows > 0) {
        // Delete pending purchases first
        $delete_purchases = $conn->query("DELETE FROM purchases WHERE price_id = $price_id AND status = 'pending'");
        if (!$delete_purchases) {
            throw new Exception("Failed to delete pending purchases: " . $conn->error);
        }
    }
    
    // Delete the company price
    $delete_price = $conn->query("DELETE FROM company_prices WHERE id = $price_id");
    
    if (!$delete_price) {
        throw new Exception("Failed to delete price: " . $conn->error);
    }
    
    // Check if this was the last price using this item
    $check_other_prices_item = $conn->query("SELECT id FROM company_prices WHERE item_id = $item_id");
    if ($check_other_prices_item && $check_other_prices_item->num_rows === 0) {
        // No other prices use this item, delete the canvas item
        $delete_item = $conn->query("DELETE FROM canvas_items WHERE id = $item_id");
        if (!$delete_item) {
            throw new Exception("Failed to delete canvas item: " . $conn->error);
        }
    }
    
    // Check if this was the last price using this company
    $check_other_prices_company = $conn->query("SELECT id FROM company_prices WHERE company_id = $company_id");
    if ($check_other_prices_company && $check_other_prices_company->num_rows === 0) {
        // No other prices use this company, delete the company
        $delete_company = $conn->query("DELETE FROM companies WHERE id = $company_id");
        if (!$delete_company) {
            throw new Exception("Failed to delete company: " . $conn->error);
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Price deleted successfully'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Delete error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>