<?php
// delete_price.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get price_id from POST
$price_id = isset($_POST['price_id']) ? $_POST['price_id'] : null;

if (!$price_id) {
    echo json_encode(['success' => false, 'message' => 'Missing price_id field']);
    exit;
}

// Convert to integer
$price_id = intval($price_id);

if ($price_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid price_id value']);
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
    // First, get the company_id before deleting
    $get_company = $conn->query("SELECT company_id FROM company_prices WHERE id = $price_id");
    if ($get_company->num_rows === 0) {
        throw new Exception('Price record not found');
    }
    
    $company_data = $get_company->fetch_assoc();
    $company_id = $company_data['company_id'];
    
    // Delete the price record
    $sql = "DELETE FROM company_prices WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $price_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to delete: ' . $conn->error);
    }
    
    // Check if this company has any other prices
    $check_other_prices = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE company_id = $company_id");
    $other_prices_count = $check_other_prices->fetch_assoc()['count'];
    
    // If no other prices, delete the company (optional - you can remove this if you want to keep companies)
    if ($other_prices_count == 0) {
        $delete_company = $conn->query("DELETE FROM companies WHERE id = $company_id");
        if (!$delete_company) {
            // Log error but don't fail the transaction
            error_log("Failed to delete orphaned company ID: $company_id");
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Price deleted successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$stmt->close();
$conn->close();
?>