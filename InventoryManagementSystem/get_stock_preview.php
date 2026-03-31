<?php
// get_stock_preview.php - Get stock preview for export modal with date columns
require_once 'config.php';
requireLogin();

// Get date range
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

// Validate dates
if (empty($date_from) || empty($date_to)) {
    echo '<div class="preview-loading">Invalid date range</div>';
    exit;
}

// Generate array of dates between from and to (limit to 7 days for preview)
$dates = [];
$current = strtotime($date_from);
$end = strtotime($date_to);
$day_count = 0;

while ($current <= $end && $day_count < 10) { // Limit to 10 days for preview
    $dates[] = date('Y-m-d', $current);
    $current = strtotime('+1 day', $current);
    $day_count++;
}

if (count($dates) == 0) {
    echo '<div class="preview-loading">No dates in range</div>';
    exit;
}

// Get limited products for preview (first 10)
$products_query = $conn->query("SELECT * FROM products ORDER BY category ASC, name ASC LIMIT 10");

if (!$products_query || $products_query->num_rows == 0) {
    echo '<div class="preview-loading">No products found</div>';
    exit;
}

$products_data = [];
$total_stock_value = 0;

while ($product = $products_query->fetch_assoc()) {
    // Get proper category
    $display_category = $product['category'];
    $display_item_no = $product['item_no'];
    $display_description = $product['description'];
    
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
            
            // Get category from canvas_items
            $canvas_query = $conn->prepare("SELECT category FROM canvas_items WHERE item_no = ?");
            $canvas_query->bind_param("s", $latest_item_no);
            $canvas_query->execute();
            $canvas_result = $canvas_query->get_result();
            if ($canvas_result->num_rows > 0) {
                $canvas_data = $canvas_result->fetch_assoc();
                if (empty($display_category)) {
                    $display_category = $canvas_data['category'];
                }
            }
            $canvas_query->close();
        }
    }
    $latest_purchase_query->close();
    
    // Set default category if still empty
    if (empty($display_category)) {
        $display_category = 'Accessories';
    }
    
    // Get stock for each date
    $stock_by_date = [];
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
        $stock_by_date[$date] = $stock_data['stock_on_date'];
        $stock_query->close();
    }
    
    $ending_stock = end($stock_by_date);
    $unit_price = $product['price'];
    $total_value = $ending_stock * $unit_price;
    $total_stock_value += $total_value;
    
    $products_data[] = [
        'item_no' => $display_item_no,
        'category' => $display_category,
        'description' => $display_description,
        'stock_by_date' => $stock_by_date,
        'ending_stock' => $ending_stock,
        'total_value' => $total_value
    ];
}

// Display preview table
if (empty($products_data)) {
    echo '<div class="preview-loading">No data available for selected date range</div>';
    exit;
}

$has_more = $products_query->num_rows == 10;
?>

<div style="max-height: 300px; overflow-y: auto;">
    <table class="stock-preview-table" style="width: 100%; font-size: 11px;">
        <thead>
            <tr>
                <th>Item No</th>
                <th>Category</th>
                <th>Description</th>
                <?php foreach($dates as $date): ?>
                    <th><?php echo date('m/d', strtotime($date)); ?></th>
                <?php endforeach; ?>
                <th>Ending</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($products_data as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_no'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                    <td><?php echo htmlspecialchars(substr($item['description'], 0, 25)) . (strlen($item['description']) > 25 ? '...' : ''); ?></td>
                    <?php foreach($dates as $date): ?>
                        <td><?php echo number_format($item['stock_by_date'][$date]); ?></td>
                    <?php endforeach; ?>
                    <td><strong><?php echo number_format($item['ending_stock']); ?></strong></td>
                    <td><strong>₱<?php echo number_format($item['total_value'], 2); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: var(--bg-secondary); font-weight: bold;">
                <td colspan="<?php echo count($dates) + 3; ?>" style="text-align: right;">TOTAL STOCK VALUE (Preview):</td>
                <td>₱<?php echo number_format($total_stock_value, 2); ?></td>
            </tr>
            <?php if($has_more): ?>
                <tr>
                    <td colspan="<?php echo count($dates) + 4; ?>" style="text-align: center; color: #666;">
                        <i class="fas fa-info-circle"></i> Showing first 10 products. Full report will include all products.
                    </td>
                </tr>
            <?php endif; ?>
        </tfoot>
    </table>
</div>