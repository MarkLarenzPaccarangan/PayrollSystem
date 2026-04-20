<?php
// export_stock_range.php - Export stock movements with date range
require_once 'config.php';
requireLogin();

// Get parameters
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$export_type = isset($_GET['type']) ? $_GET['type'] : 'detailed';

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="stock_movements_' . $date_from . '_to_' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Generate date array for daily summary
$dates = [];
$current = strtotime($date_from);
$end = strtotime($date_to);
while ($current <= $end) {
    $dates[] = date('Y-m-d', $current);
    $current = strtotime('+1 day', $current);
}

echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Stock Movements Report</title>';
echo '<style>';
echo 'body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; }';
echo 'table { font-family: "Calibri", "Segoe UI", Arial, sans-serif; font-size: 11pt; }';
echo 'th { background-color: #4CAF50; color: white; padding: 8px; border: 1px solid #ddd; font-family: "Calibri", "Segoe UI", Arial, sans-serif; }';
echo 'td { padding: 6px; border: 1px solid #ddd; font-family: "Calibri", "Segoe UI", Arial, sans-serif; }';
echo '.stock-in { color: #00b894; font-weight: bold; }';
echo '.stock-out { color: #d63031; font-weight: bold; }';
echo '.category-header { background-color: #2196F3; color: white; }';
echo '.total-row { background-color: #f2f2f2; font-weight: bold; }';
echo 'h2, h3, p { font-family: "Calibri", "Segoe UI", Arial, sans-serif; }';
echo '</style>';
echo '</head>';
echo '<body>';
echo '<h2>Stock Movements Report</h2>';
echo '<p>Date Range: ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)) . '</p>';
echo '<p>Generated on: ' . date('F d, Y h:i A') . '</p>';
echo '<br>';

if ($export_type == 'detailed') {
    // DETAILED EXPORT - All movements (6 COLUMNS ONLY)
    $sql = "SELECT 
                sm.created_at,
                sm.type,
                sm.quantity,
                COALESCE(p.item_no, SUBSTRING_INDEX(p.name, ' - ', 1), 'N/A') as item_no,
                COALESCE(p.description, 
                    CASE WHEN p.name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(p.name, ' - ', -1)) ELSE p.name END,
                    'N/A') as description,
                COALESCE(p.category, '') as category,
                COALESCE(p.unit, 'pcs') as unit
            FROM stock_movements sm
            LEFT JOIN products p ON sm.product_id = p.id
            WHERE DATE(sm.created_at) BETWEEN ? AND ?
            ORDER BY sm.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // TABLE WITH 6 COLUMNS ONLY (No Site/Location, No Reference, No Type column)
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Item No</th>';
    echo '<th>Description</th>';
    echo '<th>Category</th>';
    echo '<th>Unit</th>';
    echo '<th>Date</th>';
    echo '<th>Quantity</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $total_in = 0;
    $total_out = 0;
    
    while ($row = $result->fetch_assoc()) {
        $total_in += ($row['type'] == 'in') ? $row['quantity'] : 0;
        $total_out += ($row['type'] == 'out') ? $row['quantity'] : 0;
        
        // Format quantity with + for IN, - for OUT
        $quantity_display = ($row['type'] == 'in' ? '+' : '-') . number_format($row['quantity']);
        $quantity_class = ($row['type'] == 'in') ? 'stock-in' : 'stock-out';
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['item_no']) . '</td>';
        echo '<td>' . htmlspecialchars($row['description']) . '</td>';
        echo '<td>' . htmlspecialchars($row['category'] ?: '—') . '</td>';
        echo '<td>' . htmlspecialchars($row['unit']) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($row['created_at'])) . '</td>';  // DATE ONLY
        echo '<td class="' . $quantity_class . '">' . $quantity_display . '</td>';
        echo '</tr>';
    }
    
    // Add summary row
    echo '<tr class="total-row">';
    echo '<td colspan="5" align="right"><strong>TOTALS:</strong></td>';
    echo '<td><strong>IN: +' . number_format($total_in) . ' | OUT: -' . number_format($total_out) . '</strong></td>';
    echo '</tr>';
    
    echo '</tbody>';
    echo '</table>';
    
} else {
    // DAILY SUMMARY EXPORT - Stock per day (unchanged)
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Date</th>';
    echo '<th>Stock In</th>';
    echo '<th>Stock Out</th>';
    echo '<th>Net Change</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($dates as $date) {
        // Get stock in for date
        $in_sql = "SELECT COALESCE(SUM(quantity), 0) as total FROM stock_movements WHERE type = 'in' AND DATE(created_at) = ?";
        $in_stmt = $conn->prepare($in_sql);
        $in_stmt->bind_param("s", $date);
        $in_stmt->execute();
        $in_result = $in_stmt->get_result();
        $stock_in = $in_result->fetch_assoc()['total'];
        $in_stmt->close();
        
        // Get stock out for date
        $out_sql = "SELECT COALESCE(SUM(quantity), 0) as total FROM stock_movements WHERE type = 'out' AND DATE(created_at) = ?";
        $out_stmt = $conn->prepare($out_sql);
        $out_stmt->bind_param("s", $date);
        $out_stmt->execute();
        $out_result = $out_stmt->get_result();
        $stock_out = $out_result->fetch_assoc()['total'];
        $out_stmt->close();
        
        $net_change = $stock_in - $stock_out;
        
        echo '<tr>';
        echo '<td>' . date('F d, Y', strtotime($date)) . '</td>';
        echo '<td class="stock-in">+' . number_format($stock_in) . '</td>';
        echo '<td class="stock-out">-' . number_format($stock_out) . '</td>';
        echo '<td>' . ($net_change >= 0 ? '+' : '') . number_format($net_change) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
}

// Add summary footer
echo '<br><br>';
echo '<h3>Report Summary</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Date From</th><td>' . date('F d, Y', strtotime($date_from)) . '</td></tr>';
echo '<tr><th>Date To</th><td>' . date('F d, Y', strtotime($date_to)) . '</td></tr>';
echo '<tr><th>Total Days</th><td>' . count($dates) . '</td></tr>';
echo '</table>';

echo '</body>';
echo '</html>';

$conn->close();
?>