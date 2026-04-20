<?php
// export_deduction_history.php - Export deduction history with filters
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
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

// Get filter parameters (same as get_deduction_history.php)
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

// Get deduction history based on filters
$sql = "SELECT 
            dh.deducted_at,
            dh.site_name,
            dh.item_no,
            TRIM(REPLACE(dh.product_name, CONCAT(dh.item_no, ' - '), '')) as product_name,
            dh.quantity_deducted,
            dh.previous_quantity,
            dh.new_quantity,
            dh.remarks
        FROM deduction_history dh
        WHERE $where_clause
        ORDER BY dh.deducted_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="deduction_history_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 (fixes Excel encoding issues)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// ========== ADD TITLE AND FILTER INFO ==========
// Title: Deduction History
fputcsv($output, ['DEDUCTION HISTORY']);
fputcsv($output, []); // Empty row for spacing

// Date Range Information
$formatted_from = date('M d, Y', strtotime($from_date));
$formatted_to = date('M d, Y', strtotime($to_date));
fputcsv($output, ['From Date:', $formatted_from]);
fputcsv($output, ['To Date:', $formatted_to]);

// Site Filter (if applied)
if (!empty($site_filter)) {
    fputcsv($output, ['Site Filter:', $site_filter]);
} else {
    fputcsv($output, ['Site Filter:', 'All Sites']);
}

// Search Term (if applied)
if (!empty($search_term)) {
    fputcsv($output, ['Search Term:', $search_term]);
}

// Export Date
fputcsv($output, ['Export Date:', date('M d, Y')]);
fputcsv($output, []); // Empty row for spacing

// ========== ADD CSV HEADERS ==========
fputcsv($output, [
    'Date',
    'Site',
    'Item No',
    'Product Name',
    'Quantity Deducted',
    'Previous Quantity',
    'New Quantity',
    'Remarks'
]);

// Add data rows
while ($row = $result->fetch_assoc()) {
    // Format date - date only (no time)
    $formatted_date = date('M d, Y', strtotime($row['deducted_at']));
    
    // Clean up product name
    $product_name = $row['product_name'];
    if (empty($product_name)) {
        $product_name = $row['item_no'];
    }
    
    // Clean up remarks
    $remarks = $row['remarks'] ?? '';
    if (empty($remarks)) {
        $remarks = '—';
    }
    
    fputcsv($output, [
        $formatted_date,
        $row['site_name'],
        $row['item_no'],
        $product_name,
        $row['quantity_deducted'],
        $row['previous_quantity'],
        $row['new_quantity'],
        $remarks
    ]);
}

fclose($output);
$stmt->close();
$conn->close();
?>