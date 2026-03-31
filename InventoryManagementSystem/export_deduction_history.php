<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$host = DB_HOST;
$username = DB_USER;
$password = DB_PASS;
$database = DB_NAME;

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filter parameters
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build WHERE clause
$where_conditions = ["DATE(dh.deducted_at) BETWEEN ? AND ?"];
$params = [$from_date, $to_date];
$types = "ss";

if (!empty($site_filter)) {
    $where_conditions[] = "dh.site_name = ?";
    $params[] = $site_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $search_like = "%$search_term%";
    $where_conditions[] = "(p.item_no LIKE ? OR p.name LIKE ? OR p.description LIKE ? OR dh.reference LIKE ?)";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ssss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get deduction history
$sql = "SELECT 
            dh.deducted_at,
            dh.site_name,
            p.item_no,
            p.name as product_name,
            p.description,
            dh.quantity_deducted,
            dh.previous_quantity,
            dh.new_quantity,
            dh.reference,
            dh.notes
        FROM deduction_history dh
        LEFT JOIN products p ON dh.product_id = p.id
        WHERE $where_clause
        ORDER BY dh.deducted_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deduction_history_' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, [
        'Date & Time',
        'Site',
        'Item No',
        'Product Name',
        'Description',
        'Quantity Deducted',
        'Previous Quantity',
        'New Quantity',
        'Reference',
        'Notes'
    ]);
    
    // Add data rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['deducted_at'],
            $row['site_name'],
            $row['item_no'] ?? 'N/A',
            $row['product_name'] ?? 'N/A',
            $row['description'] ?? '',
            $row['quantity_deducted'],
            $row['previous_quantity'],
            $row['new_quantity'],
            $row['reference'] ?? '',
            $row['notes'] ?? ''
        ]);
    }
    
    fclose($output);
}

$conn->close();
?>