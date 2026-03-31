<?php
// export_site_data.php - Export site data based on date and site filter
require_once 'config.php';
requireLogin();

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
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to = isset($_GET['to']) ? $_GET['to'] : '';
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';

if (empty($date_from) || empty($date_to)) {
    die('Please select both from and to dates');
}

// Build query
$query = "SELECT 
            sm.id,
            sm.created_at,
            sm.reference,
            sm.quantity,
            sm.notes,
            sm.site_location,
            p.id as product_id,
            p.name as product_name,
            p.item_no,
            p.description,
            p.category,
            p.unit
          FROM stock_movements sm
          LEFT JOIN products p ON sm.product_id = p.id
          WHERE sm.type = 'out' 
          AND DATE(sm.created_at) BETWEEN ? AND ?";

$params = [$date_from, $date_to];
$types = "ss";

if (!empty($site_filter)) {
    $query .= " AND sm.site_location = ?";
    $params[] = $site_filter;
    $types .= "s";
}

$query .= " ORDER BY sm.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="site_data_' . $date_from . '_to_' . $date_to . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for special characters
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, [
    'Date & Time',
    'Site Location',
    'Item No',
    'Description',
    'Category',
    'Unit',
    'Quantity',
    'Reference',
    'Notes'
]);

// Add data rows
$total_quantity = 0;
while ($row = $result->fetch_assoc()) {
    $total_quantity += $row['quantity'];
    fputcsv($output, [
        date('Y-m-d H:i:s', strtotime($row['created_at'])),
        $row['site_location'],
        $row['item_no'] ?? 'N/A',
        $row['description'] ?? $row['product_name'] ?? 'N/A',
        $row['category'] ?? 'N/A',
        $row['unit'] ?? 'pcs',
        -$row['quantity'],
        $row['reference'] ?? '—',
        $row['notes'] ?? '—'
    ]);
}

// Add summary row
fputcsv($output, []);
fputcsv($output, ['SUMMARY', '', '', '', '', '', '', '', '']);
fputcsv($output, ['Total Records', $result->num_rows, '', '', '', '', 'Total Quantity', $total_quantity, '']);
fputcsv($output, ['Date Range', $date_from . ' to ' . $date_to, '', '', '', '', 'Site Filter', $site_filter ?: 'All Sites', '']);
fputcsv($output, ['Export Date', date('Y-m-d H:i:s'), '', '', '', '', 'Exported By', getCurrentUser()['username'] ?? 'System', '']);

fclose($output);
$conn->close();
?>