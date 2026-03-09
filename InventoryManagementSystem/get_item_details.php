<?php
// get_item_details.php - API for getting item details with companies
require_once 'config.php';

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get item
    $item_query = $conn->query("SELECT * FROM canvas_items WHERE id = $id");
    $item = $item_query->fetch_assoc();
    
    // Get all companies
    $companies_query = $conn->query("SELECT * FROM companies WHERE status = 'active' ORDER BY name");
    $companies = [];
    while($row = $companies_query->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get prices for this item
    $prices_query = $conn->query("SELECT * FROM company_prices WHERE item_id = $id");
    $prices = [];
    while($row = $prices_query->fetch_assoc()) {
        $prices[$row['company_id']] = $row;
    }
    
    echo json_encode([
        'item' => $item,
        'companies' => $companies,
        'prices' => $prices
    ]);
}

$conn->close();
?>