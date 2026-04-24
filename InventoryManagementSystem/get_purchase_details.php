<?php
// get_purchase_details.php
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

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Purchase ID required']);
    exit();
}

$purchase_id = intval($_GET['id']);

// Get purchase details WITH CATEGORY from canvas_items
$purchase_sql = "SELECT p.*, ci.category 
                 FROM purchases p 
                 LEFT JOIN canvas_items ci ON p.item_no = ci.item_no 
                 WHERE p.id = ?";
$stmt = $conn->prepare($purchase_sql);
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase_result = $stmt->get_result();

if ($purchase_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Purchase not found']);
    exit();
}

$purchase = $purchase_result->fetch_assoc();

// Return all needed columns INCLUDING CATEGORY
$response = [
    'success' => true,
    'purchase' => [
        'id' => $purchase['id'],
        'purchase_number' => $purchase['purchase_number'] ?? 'N/A',
        'item_no' => $purchase['item_no'] ?? 'N/A',
        'description' => $purchase['description'] ?? 'N/A',
        'category' => $purchase['category'] ?? '',  // ADDED CATEGORY
        'company_name' => $purchase['company_name'] ?? 'N/A',
        'contact_person' => $purchase['contact_person'] ?? 'N/A',
        'quantity_purchased' => intval($purchase['quantity_purchased'] ?? 0),
        'price_per_unit' => floatval($purchase['price_per_unit'] ?? 0),
        'total_amount' => floatval($purchase['total_amount'] ?? 0),
        'status' => $purchase['status'] ?? 'pending',
        'purchase_date' => $purchase['purchase_date'],
        'delivery_date' => $purchase['delivery_date'] ?? '',
        'company_color' => $purchase['company_color'] ?? '#6c5ce7',
    ]
];

// Add formatted values
$response['purchase']['formatted_date'] = date('M d, Y', strtotime($purchase['purchase_date']));
$response['purchase']['formatted_price'] = '₱' . number_format($purchase['price_per_unit'] ?? 0, 2);
$response['purchase']['formatted_total'] = '₱' . number_format($purchase['total_amount'] ?? 0, 2);
if (!empty($purchase['delivery_date'])) {
    $response['purchase']['formatted_delivery_date'] = date('M d, Y', strtotime($purchase['delivery_date']));
} else {
    $response['purchase']['formatted_delivery_date'] = '—';
}

echo json_encode($response);

$stmt->close();
$conn->close();
?>