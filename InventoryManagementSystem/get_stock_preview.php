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
            $canvas_query = $conn->prepare("SELECT category, description, unit FROM canvas_items WHERE item_no = ?");
            $canvas_query->bind_param("s", $latest_item_no);
            $canvas_query->execute();
            $canvas_result = $canvas_query->get_result();
            if ($canvas_result->num_rows > 0) {
                $canvas_data = $canvas_result->fetch_assoc();
                if (empty($display_category)) {
                    $display_category = $canvas_data['category'];
                }
                if (empty($display_description)) {
                    $display_description = $canvas_data['description'];
                }
                if (empty($display_unit)) {
                    $display_unit = $canvas_data['unit'];
                }
            }
            $canvas_query->close();
        }
    }
    $latest_purchase_query->close();
    
    // Get description from canvas_items if still empty
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
    
    // Fallback: extract description from product name
    if (empty($display_description) && strpos($product['name'], ' - ') !== false) {
        $parts = explode(' - ', $product['name'], 2);
        $display_description = trim($parts[1]);
    }
    
    // Set default category if still empty
    if (empty($display_category)) {
        $display_category = 'Accessories';
    }
    
    // Set default description if still empty
    if (empty($display_description)) {
        $display_description = $product['name'];
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
    <table class="stock-preview-table" style="width: 100%; font-size: 11px; border-collapse: collapse;">
        <thead>
            <tr style="background: linear-gradient(135deg, #34495e, #2c3e50); color: white; position: sticky; top: 0;">
                <th style="padding: 10px 8px; text-align: left; border: 1px solid #2c3e50;">Item No</th>
                <th style="padding: 10px 8px; text-align: left; border: 1px solid #2c3e50;">Category</th>
                <th style="padding: 10px 8px; text-align: left; border: 1px solid #2c3e50;">Description</th>
                <?php foreach($dates as $date): ?>
                    <th style="padding: 10px 8px; text-align: center; border: 1px solid #2c3e50;"><?php echo date('M d', strtotime($date)); ?></th>
                <?php endforeach; ?>
                <th style="padding: 10px 8px; text-align: center; border: 1px solid #2c3e50;">Ending</th>
                <th style="padding: 10px 8px; text-align: right; border: 1px solid #2c3e50;">Value (₱)</th>
            </tr>
        </thead>
        <tbody>
            <?php $row_num = 0; foreach($products_data as $item): 
                $row_bg = ($row_num % 2 == 0) ? '#ffffff' : '#f9f9f9';
            ?>
                <tr style="background-color: <?php echo $row_bg; ?>;">
                    <td style="padding: 8px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['item_no'] ?: 'N/A'); ?></td>
                    <td style="padding: 8px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['category']); ?></td>
                    <td style="padding: 8px; border: 1px solid #ddd;"><?php echo htmlspecialchars(substr($item['description'], 0, 35)) . (strlen($item['description']) > 35 ? '...' : ''); ?></td>
                    <?php foreach($dates as $date): 
                        $stock_qty = $item['stock_by_date'][$date];
                        $stock_style = ($stock_qty <= 5) ? 'color: #e74c3c; font-weight: bold;' : '';
                    ?>
                        <td style="padding: 8px; text-align: center; border: 1px solid #ddd; <?php echo $stock_style; ?>"><?php echo number_format($stock_qty); ?></td>
                    <?php endforeach; ?>
                    <td style="padding: 8px; text-align: center; border: 1px solid #ddd; font-weight: bold;"><?php echo number_format($item['ending_stock']); ?></td>
                    <td style="padding: 8px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #27ae60;">₱<?php echo number_format($item['total_value'], 2); ?></td>
                </tr>
            <?php $row_num++; endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: linear-gradient(135deg, #2c3e50, #34495e); color: white; font-weight: bold;">
                <td colspan="<?php echo count($dates) + 3; ?>" style="padding: 10px; text-align: right; border: 1px solid #2c3e50;">TOTAL STOCK VALUE (Preview):</td>
                <td style="padding: 10px; text-align: right; border: 1px solid #2c3e50; font-size: 13px;">₱<?php echo number_format($total_stock_value, 2); ?></td>
            </tr>
            <?php if($has_more): ?>
                <tr>
                    <td colspan="<?php echo count($dates) + 4; ?>" style="padding: 8px; text-align: center; background-color: #ecf0f1; color: #7f8c8d; border: 1px solid #ddd;">
                        <i class="fas fa-info-circle"></i> Showing first 10 products. Full report will include all products.
                    </td>
                </tr>
            <?php endif; ?>
        </tfoot>
    </table>
</div>