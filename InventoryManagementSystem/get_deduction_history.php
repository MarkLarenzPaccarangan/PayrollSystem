<?php
// get_deduction_history.php - Get deduction history with filters
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Database connection
$host = DB_HOST;
$username = DB_USER;
$password = DB_PASS;
$database = DB_NAME;

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Get filter parameters
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build where conditions
$where_conditions = [
    "DATE(dh.deducted_at) BETWEEN ? AND ?"
];
$params = [$from_date, $to_date];
$types = "ss";

if (!empty($site_filter)) {
    $where_conditions[] = "dh.site_name = ?";
    $params[] = $site_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $search_like = "%$search_term%";
    $where_conditions[] = "(dh.item_no LIKE ? OR dh.product_name LIKE ?)";
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get deduction history - REMOVED item_no prefix from product_name
// Using TRIM to clean up any extra spaces
$sql = "SELECT 
            dh.id,
            dh.deducted_at,
            dh.site_name,
            dh.product_id,
            TRIM(REPLACE(dh.product_name, CONCAT(dh.item_no, ' - '), '')) as product_name,
            dh.item_no,
            dh.quantity_deducted,
            dh.previous_quantity,
            dh.new_quantity,
            dh.remarks
        FROM deduction_history dh
        WHERE $where_clause
        ORDER BY dh.deducted_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        // If product_name is still empty or same as original, use original but clean
        if (empty($row['product_name']) || $row['product_name'] == $row['item_no'] . ' - ') {
            $row['product_name'] = str_replace($row['item_no'] . ' - ', '', $row['product_name']);
        }
        // Remove any extra " - " at the beginning
        $row['product_name'] = ltrim($row['product_name'], ' -');
        // If still empty, show original
        if (empty($row['product_name'])) {
            $row['product_name'] = $row['item_no'];
        }
        $history[] = $row;
    }
    
    // Get statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_deductions,
                    COALESCE(SUM(quantity_deducted), 0) as total_quantity,
                    COUNT(DISTINCT product_id) as unique_items
                  FROM deduction_history dh
                  WHERE $where_clause";
    
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->bind_param($types, ...$params);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
    } else {
        $stats = ['total_deductions' => 0, 'total_quantity' => 0, 'unique_items' => 0];
    }
    
    echo json_encode([
        'success' => true,
        'history' => $history,
        'total_deductions' => intval($stats['total_deductions'] ?? 0),
        'total_quantity' => intval($stats['total_quantity'] ?? 0),
        'unique_items' => intval($stats['unique_items'] ?? 0)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

$conn->close();
?>