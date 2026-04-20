<?php
// get_item_details.php - API LANG ITO, WAG MAG-INCLUDE NG HEADER
require_once 'config.php';

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// Get companies
$companies_result = $conn->query("SELECT id, name, contact_person, contact_number FROM companies WHERE status = 'active'");
$companies = [];
while ($row = $companies_result->fetch_assoc()) {
    $companies[] = $row;
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get item details
    $stmt = $conn->prepare("SELECT * FROM canvas_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $item_result = $stmt->get_result();
    
    if ($item = $item_result->fetch_assoc()) {
        // Get prices for this item
        $price_stmt = $conn->prepare("SELECT * FROM company_prices WHERE item_id = ?");
        $price_stmt->bind_param("i", $id);
        $price_stmt->execute();
        $price_result = $price_stmt->get_result();
        
        $prices = [];
        while ($price = $price_result->fetch_assoc()) {
            $prices[$price['company_id']] = $price;
        }
        
        echo json_encode([
            'item' => $item,
            'prices' => $prices,
            'companies' => $companies
        ]);
        
        $price_stmt->close();
    } else {
        echo json_encode(['error' => 'Item not found']);
    }
    
    $stmt->close();
}

$conn->close();
?>