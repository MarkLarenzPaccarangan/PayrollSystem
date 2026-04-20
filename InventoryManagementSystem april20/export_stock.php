<?php
// export_stock.php - Export stock data with date columns (Professional Format)
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

// Create HTML table for Excel (Professional Format)
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Stock Report</title>';
echo '<style>';
echo 'body { font-family: Arial, Helvetica, sans-serif; margin: 20px; }';
echo 'h2 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }';
echo 'h3 { color: #34495e; margin-top: 30px; }';
echo '.report-header { background-color: #2c3e50; color: white; padding: 15px; margin-bottom: 20px; }';
echo '.report-info { margin-bottom: 20px; padding: 10px; background-color: #ecf0f1; border-left: 4px solid #3498db; }';
echo 'table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }';
echo 'th { background-color: #34495e; color: white; padding: 10px 8px; border: 1px solid #2c3e50; font-size: 11px; }';
echo 'td { padding: 8px; border: 1px solid #bdc3c7; font-size: 11px; }';
echo 'tr:nth-child(even) { background-color: #f9f9f9; }';
echo 'tr:hover { background-color: #f5f5f5; }';
echo '.category-header { background-color: #3498db; color: white; }';
echo '.total-row { background-color: #2c3e50; color: white; font-weight: bold; }';
echo '.category-total { background-color: #ecf0f1; font-weight: bold; }';
echo '.grand-total { background-color: #2c3e50; color: white; font-weight: bold; font-size: 12px; }';
echo '.footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #bdc3c7; font-size: 10px; color: #7f8c8d; text-align: center; }';
echo '.summary-table { width: auto; margin-top: 20px; }';
echo '.summary-table td { padding: 8px 15px; }';
echo '.summary-table td:first-child { font-weight: bold; background-color: #ecf0f1; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Company Header
echo '<div class="report-header">';
echo '<h2 style="margin: 0; color: white;">INVENTORY STOCK REPORT</h2>';
echo '<p style="margin: 5px 0 0 0; opacity: 0.8;">Daily Stock Movement Report</p>';
echo '</div>';

// Report Information
echo '<div class="report-info">';
echo '<strong>Date Range:</strong> ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)) . '<br>';
echo '<strong>Generated on:</strong> ' . date('F d, Y h:i A') . '<br>';
echo '<strong>Total Days:</strong> ' . count($dates) . ' days<br>';
echo '<strong>Prepared by:</strong> System Administrator';
echo '</div>';

// Start main table
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<thead>';
echo '<tr>';
echo '<th>Item No</th>';
echo '<th>Category</th>';
echo '<th>Description</th>';
echo '<th>Unit</th>';
echo '<th>Unit Price (₱)</th>';

// Add date columns (show only month/day for better readability)
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
$row_count = 0;

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
    
    // Set default values if still empty
    if (empty($display_category)) {
        $display_category = 'Accessories';
    }
    if (empty($display_unit)) {
        $display_unit = 'pcs';
    }
    if (empty($display_description)) {
        $display_description = $product['name'];
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
    
    // Alternate row background
    $row_bg = ($row_count % 2 == 0) ? '#ffffff' : '#f9f9f9';
    
    // Display row
    echo '<tr style="background-color: ' . $row_bg . ';">';
    echo '<td>' . htmlspecialchars($display_item_no ?: 'N/A') . '</td>';
    echo '<td>' . htmlspecialchars($display_category) . '</td>';
    echo '<td>' . htmlspecialchars($display_description) . '</td>';
    echo '<td>' . htmlspecialchars($display_unit) . '</td>';
    echo '<td>' . number_format($unit_price, 2) . '</td>';
    
    // Display stock for each date
    foreach ($dates as $date) {
        $stock = $stock_by_date[$date];
        $stock_style = ($stock <= 5) ? 'color: #e74c3c; font-weight: bold;' : '';
        echo '<td style="' . $stock_style . '">' . number_format($stock) . '</td>';
    }
    
    echo '<td><strong>' . number_format($ending_stock) . '</strong></td>';
    echo '<td><strong>₱' . number_format($total_value, 2) . '</strong></td>';
    echo '</tr>';
    $row_count++;
}

// Category Summary Section
echo '<tr style="background-color: #34495e;">';
echo '<td colspan="5" style="color: white; font-weight: bold;">CATEGORY SUMMARY</td>';
echo '<td colspan="' . (count($dates) + 1) . '" style="color: white;">&nbsp;</td>';
echo '<td style="color: white; font-weight: bold;">Total Value</td>';
echo '</tr>';

foreach ($category_totals as $category => $total) {
    echo '<tr class="category-total">';
    echo '<td colspan="5" style="text-align: right; font-weight: bold;">' . htmlspecialchars($category) . ':</td>';
    echo '<td colspan="' . (count($dates) + 1) . '">&nbsp;</td>';
    echo '<td><strong>₱' . number_format($total, 2) . '</strong></td>';
    echo '</tr>';
}

// Grand Total Row
echo '<tr class="grand-total">';
echo '<td colspan="5" style="font-weight: bold;">GRAND TOTAL</td>';
echo '<td colspan="' . (count($dates) + 1) . '">&nbsp;</td>';
echo '<td><strong>₱' . number_format($total_inventory_value, 2) . '</strong></td>';
echo '</tr>';

echo '</tbody>';
echo '</table>';

// Summary Section
echo '<h3>Report Summary</h3>';
echo '<table class="summary-table" border="1" cellpadding="8" cellspacing="0">';
echo '<tr><td><strong>Date From</strong></td><td>' . date('F d, Y', strtotime($date_from)) . '</td></tr>';
echo '<tr><td><strong>Date To</strong></td><td>' . date('F d, Y', strtotime($date_to)) . '</td></tr>';
echo '<tr><td><strong>Total Days</strong></td><td>' . count($dates) . ' days</td></tr>';
echo '<tr><td><strong>Total Products</strong></td><td>' . $products_query->num_rows . ' products</td></tr>';
echo '<tr><td><strong>Categories</strong></td><td>' . count($category_totals) . ' categories</td></tr>';
echo '<tr style="background-color: #2c3e50; color: white;"><td><strong>Total Inventory Value</strong></td><td><strong>₱' . number_format($total_inventory_value, 2) . '</strong></td></tr>';
echo '</table>';

// Footer
echo '<div class="footer">';
echo 'This report is system-generated and shows the daily stock movement for the specified period.<br>';
echo 'Generated by Inventory Management System | ' . date('F d, Y h:i A');
echo '</div>';

echo '</body>';
echo '</html>';
?>