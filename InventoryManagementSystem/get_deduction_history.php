<?php
// get_deduction_history.php - FIXED: Get data from stock_movements table
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

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
    echo json_encode(['success' => false, 'message' => 'Connection failed']);
    exit();
}

// Get filter parameters
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// ========== FIXED: Query from stock_movements table ==========
// Build WHERE clause for stock_movements
$where_conditions = [
    "sm.type = 'out'",
    "DATE(sm.created_at) BETWEEN ? AND ?"
];
$params = [$from_date, $to_date];
$types = "ss";

// Add site filter if provided
if (!empty($site_filter)) {
    $where_conditions[] = "sm.site_location = ?";
    $params[] = $site_filter;
    $types .= "s";
}

// Add search term if provided
if (!empty($search_term)) {
    $search_like = "%$search_term%";
    $where_conditions[] = "(p.item_no LIKE ? OR p.name LIKE ? OR p.description LIKE ? OR sm.reference LIKE ?)";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ssss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get pull-out history from stock_movements
$sql = "SELECT 
            sm.id as movement_id,
            sm.created_at as deducted_at,
            sm.quantity as quantity_deducted,
            sm.reference,
            sm.notes,
            sm.site_location as site_name,
            p.id as product_id,
            p.name as product_name,
            p.item_no,
            p.description,
            p.unit,
            p.quantity as current_quantity
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.id
        WHERE $where_clause
        ORDER BY sm.created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate previous quantity (current + deducted)
        $row['previous_quantity'] = $row['current_quantity'] + $row['quantity_deducted'];
        $row['new_quantity'] = $row['current_quantity'];
        
        $history[] = $row;
    }
    
    // Get statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_deductions,
                    SUM(quantity) as total_quantity,
                    COUNT(DISTINCT product_id) as unique_items
                  FROM stock_movements sm
                  WHERE $where_clause";
    
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->bind_param($types, ...$params);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
    } else {
        $stats = ['total_deductions' => 0, 'total_quantity' => 0, 'unique_items' => 0];
    }
    
    echo json_encode([
        'success' => true,
        'history' => $history,
        'stats' => [
            'total_deductions' => intval($stats['total_deductions'] ?? 0),
            'total_quantity' => intval($stats['total_quantity'] ?? 0),
            'unique_items' => intval($stats['unique_items'] ?? 0)
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

$conn->close();
?>