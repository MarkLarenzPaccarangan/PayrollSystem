<?php
// get_purchase_history.php - Completed transactions only
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

// Get COMPLETED purchases only for transaction history
$history_sql = "SELECT * FROM purchases 
                WHERE status = 'completed'
                ORDER BY purchase_date DESC 
                LIMIT 100";
$history_result = $conn->query($history_sql);

$history = [];
while ($row = $history_result->fetch_assoc()) {
    // Status class
    $status_class = $row['status'] == 'completed' ? 'status-completed' : 
                   ($row['status'] == 'pending' ? 'status-pending' : 
                   ($row['status'] == 'processing' ? 'status-processing' : 'status-cancelled'));
    
    $history[] = [
        'id' => $row['id'],
        'purchase_number' => $row['purchase_number'],
        'item_no' => $row['item_no'] ?? 'N/A',
        'description' => $row['description'] ?? 'N/A',
        'company_name' => $row['company_name'] ?? 'N/A',
        'contact_person' => $row['contact_person'] ?? 'N/A',
        'quantity_purchased' => intval($row['quantity_purchased'] ?? 0),
        'price_per_unit' => floatval($row['price_per_unit'] ?? 0),
        'total_amount' => floatval($row['total_amount'] ?? 0),
        'status' => $row['status'] ?? 'completed',
        'purchase_date' => $row['purchase_date'],
        'company_color' => $row['company_color'] ?? '#6c5ce7',
        'status_class' => $status_class,
        'formatted_date' => date('M d, Y', strtotime($row['purchase_date'])),
        'formatted_price' => '₱' . number_format($row['price_per_unit'] ?? 0, 2),
        'formatted_total' => '₱' . number_format($row['total_amount'] ?? 0, 2)
    ];
}

// Get summary statistics (only completed)
$stats_sql = "SELECT 
                COUNT(*) as total_transactions,
                SUM(total_amount) as total_spent,
                SUM(quantity_purchased) as total_quantity,
                COUNT(DISTINCT item_no) as unique_items,
                COUNT(DISTINCT company_name) as unique_companies
              FROM purchases
              WHERE status = 'completed'";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$response = [
    'success' => true,
    'history' => $history,
    'stats' => [
        'total_transactions' => $stats['total_transactions'] ?? 0,
        'total_spent' => floatval($stats['total_spent'] ?? 0),
        'total_quantity' => intval($stats['total_quantity'] ?? 0),
        'unique_items' => intval($stats['unique_items'] ?? 0),
        'unique_companies' => intval($stats['unique_companies'] ?? 0)
    ],
    'total_records' => count($history)
];

echo json_encode($response);

$conn->close();
?>