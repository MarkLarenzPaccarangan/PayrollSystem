<?php
// export_stock.php - Export stock data with date columns
require_once 'config.php';
requireLogin();

// Get date range
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

// Validate dates
if (empty($date_from) || empty($date_to)) {
    die('Invalid date range');
}

// Generate array of dates between from and to
$dates = [];
$current = strtotime($date_from);
$end = strtotime($date_to);

while ($current <= $end) {
    $dates[] = date('Y-m-d', $current);
    $current = strtotime('+1 day', $current);
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="stock_report_' . $date_from . '_to_' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Get all products
$products_query = $conn->query("SELECT * FROM products ORDER BY category ASC, name ASC");

if (!$products_query || $products_query->num_rows == 0) {
    echo "No products found";
    exit;
}

// Create HTML table for Excel
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Stock Report</title>';
echo '<style>';
echo 'th { background-color: #4CAF50; color: white; padding: 8px; border: 1px solid #ddd; }';
echo 'td { padding: 6px; border: 1px solid #ddd; }';
echo '.category-header { background-color: #2196F3; color: white; }';
echo '.total-row { background-color: #f2f2f2; font-weight: bold; }';
echo '</style>';
echo '</head>';
echo '<body>';
echo '<h2>Stock Report - Daily Stock Movement</h2>';
echo '<p>Date Range: ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)) . '</p>';
echo '<p>Generated on: ' . date('F d, Y h:i A') . '</p>';
echo '<br>';

// Start table
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<thead>';
echo '<tr>';
echo '<th>Item No</th>';
echo '<th>Category</th>';
echo '<th>Description</th>';
echo '<th>Unit</th>';
echo '<th>Unit Price (₱)</th>';

// Add date columns
foreach ($dates as $date) {
    echo '<th>' . date('M d, Y', strtotime($date)) . '<br><small>Stock Qty</small></th>';
}

echo '<th>Ending Stock</th>';
echo '<th>Total Value (₱)</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

$total_inventory_value = 0;
$category_totals = [];

while ($product = $products_query->fetch_assoc()) {
    // Get proper display values
    $display_item_no = $product['item_no'];
    $display_description = $product['description'];
    $display_category = $product['category'];
    $display_unit = $product['unit'];
    
    // Get latest item_no from purchases
    $latest_purchase_query = $conn->prepare("
        SELECT item_no 
        FROM purchases 
        WHERE product_id = ? 
        AND status = 'completed'
        AND item_no IS NOT NULL
        AND item_no != ''
        ORDER BY purchase_date DESC 
        LIMIT 1
    ");
    $latest_purchase_query->bind_param("i", $product['id']);
    $latest_purchase_query->execute();
    $latest_purchase_result = $latest_purchase_query->get_result();
    
    if ($latest_purchase_result->num_rows > 0) {
        $latest_purchase = $latest_purchase_result->fetch_assoc();
        $latest_item_no = $latest_purchase['item_no'];
        
        if (!empty($latest_item_no)) {
            $display_item_no = $latest_item_no;
            
            // Get category and unit from canvas_items
            $canvas_query = $conn->prepare("SELECT category, unit FROM canvas_items WHERE item_no = ?");
            $canvas_query->bind_param("s", $latest_item_no);
            $canvas_query->execute();
            $canvas_result = $canvas_query->get_result();
            if ($canvas_result->num_rows > 0) {
                $canvas_data = $canvas_result->fetch_assoc();
                if (empty($display_category)) {
                    $display_category = $canvas_data['category'];
                }
                if (empty($display_unit)) {
                    $display_unit = $canvas_data['unit'];
                }
            }
            $canvas_query->close();
        }
    }
    $latest_purchase_query->close();
    
    // Get description from canvas_items if empty
    if (empty($display_description) && !empty($display_item_no)) {
        $desc_query = $conn->prepare("SELECT description FROM canvas_items WHERE item_no = ?");
        $desc_query->bind_param("s", $display_item_no);
        $desc_query->execute();
        $desc_result = $desc_query->get_result();
        if ($desc_result->num_rows > 0) {
            $desc_data = $desc_result->fetch_assoc();
            $display_description = $desc_data['description'];
        }
        $desc_query->close();
    }
    
    if (empty($display_description) && strpos($product['name'], ' - ') !== false) {
        $parts = explode(' - ', $product['name'], 2);
        $display_description = trim($parts[1]);
    }
    
    // Set default category if still empty
    if (empty($display_category)) {
        $display_category = 'Accessories';
    }
    
    // Get stock quantities for each date
    $stock_by_date = [];
    $previous_stock = 0;
    
    foreach ($dates as $date) {
        $stock_query = $conn->prepare("
            SELECT COALESCE(SUM(
                CASE 
                    WHEN type = 'in' THEN quantity 
                    WHEN type = 'out' THEN -quantity 
                    ELSE 0 
                END
            ), 0) as stock_on_date
            FROM stock_movements 
            WHERE product_id = ? 
            AND DATE(created_at) <= ?
        ");
        $stock_query->bind_param("is", $product['id'], $date);
        $stock_query->execute();
        $stock_result = $stock_query->get_result();
        $stock_data = $stock_result->fetch_assoc();
        $current_stock = $stock_data['stock_on_date'];
        $stock_by_date[$date] = $current_stock;
        $previous_stock = $current_stock;
        $stock_query->close();
    }
    
    $ending_stock = $previous_stock;
    $unit_price = $product['price'];
    $total_value = $ending_stock * $unit_price;
    $total_inventory_value += $total_value;
    
    // Track category totals
    if (!isset($category_totals[$display_category])) {
        $category_totals[$display_category] = 0;
    }
    $category_totals[$display_category] += $total_value;
    
    // Display row
    echo '<tr>';
    echo '<td>' . htmlspecialchars($display_item_no ?: 'N/A') . '</td>';
    echo '<td>' . htmlspecialchars($display_category) . '</td>';
    echo '<td>' . htmlspecialchars($display_description) . '</td>';
    echo '<td>' . htmlspecialchars($display_unit ?: 'pcs') . '</td>';
    echo '<td>' . number_format($unit_price, 2) . '</td>';
    
    // Display stock for each date
    foreach ($dates as $date) {
        $stock = $stock_by_date[$date];
        echo '<td>' . number_format($stock) . '</td>';
    }
    
    echo '<td><strong>' . number_format($ending_stock) . '</strong></td>';
    echo '<td><strong>₱' . number_format($total_value, 2) . '</strong></td>';
    echo '</tr>';
}

// Add category summary rows
echo '<tr class="total-row">';
echo '<td colspan="4" align="right"><strong>CATEGORY SUMMARY</strong></td>';
echo '<td colspan="' . (count($dates) + 2) . '"></td>';
echo '</tr>';

foreach ($category_totals as $category => $total) {
    echo '<tr>';
    echo '<td colspan="4" align="right">' . htmlspecialchars($category) . ':</td>';
    echo '<td colspan="' . (count($dates) + 2) . '"><strong>₱' . number_format($total, 2) . '</strong></td>';
    echo '</tr>';
}

// Add total row
echo '<tr class="total-row">';
echo '<td colspan="4" align="right"><strong>GRAND TOTAL</strong></td>';
echo '<td colspan="' . (count($dates) + 2) . '"><strong>₱' . number_format($total_inventory_value, 2) . '</strong></td>';
echo '</tr>';

echo '</tbody>';
echo '</table>';

// Add summary
echo '<br><br>';
echo '<h3>Report Summary</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Date From</th><td>' . date('F d, Y', strtotime($date_from)) . '</td></tr>';
echo '<tr><th>Date To</th><td>' . date('F d, Y', strtotime($date_to)) . '</td></tr>';
echo '<tr><th>Total Days</th><td>' . count($dates) . '</td></tr>';
echo '<tr><th>Total Products</th><td>' . $products_query->num_rows . '</td></tr>';
echo '<tr><th>Total Inventory Value</th><td>₱' . number_format($total_inventory_value, 2) . '</td></tr>';
echo '</table>';

echo '</body>';
echo '</html>';
?>