<?php
// home.php - WITH UNIT PRICE AND TOTAL VALUE COLUMNS
ob_start();
require_once 'config.php';
requireLogin();

// Get sort parameter - default to name_asc
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';

// Get selected date from date picker (default to today)
$selected_date = isset($_GET['stock_date']) && !empty($_GET['stock_date']) ? $_GET['stock_date'] : date('Y-m-d');

// Build ORDER BY clause based on sort
switch($sort) {
    case 'name_asc':
        $order_by = "ORDER BY name ASC";
        $current_sort_label = 'Product Name (A-Z)';
        break;
    case 'name_desc':
        $order_by = "ORDER BY name DESC";
        $current_sort_label = 'Product Name (Z-A)';
        break;
    case 'category_asc':
        $order_by = "ORDER BY category ASC, name ASC";
        $current_sort_label = 'Category (A-Z)';
        break;
    case 'category_desc':
        $order_by = "ORDER BY category DESC, name ASC";
        $current_sort_label = 'Category (Z-A)';
        break;
    default:
        $order_by = "ORDER BY name ASC";
        $current_sort_label = 'Product Name (A-Z)';
}

// Get all products
$all_products = $conn->query("SELECT * FROM products $order_by");

// Calculate stock for each product as of selected date
$products_with_stock = [];
$totalProducts = 0;
$outOfStock = 0;
$totalInventoryValue = 0; // NEW: Track total inventory value

if ($all_products && $all_products->num_rows > 0) {
    while($row = $all_products->fetch_assoc()) {
        // Calculate stock as of selected date
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
        $stock_query->bind_param("is", $row['id'], $selected_date);
        $stock_query->execute();
        $stock_result = $stock_query->get_result();
        $stock_data = $stock_result->fetch_assoc();
        
        $row['historical_quantity'] = $stock_data['stock_on_date'];
        $quantity = $row['historical_quantity'];
        $unit_price = $row['price'];
        $total_value = $quantity * $unit_price; // NEW: Calculate total value
        
        // Add to total inventory value
        $totalInventoryValue += $total_value;
        
        // Get the LATEST item_no from purchases
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
        $latest_purchase_query->bind_param("i", $row['id']);
        $latest_purchase_query->execute();
        $latest_purchase_result = $latest_purchase_query->get_result();
        
        $latest_item_no = null;
        if ($latest_purchase_result->num_rows > 0) {
            $latest_purchase = $latest_purchase_result->fetch_assoc();
            $latest_item_no = $latest_purchase['item_no'];
        }
        $latest_purchase_query->close();
        
        // Fetch category and unit from canvas_items based on the LATEST item_no
        $display_item_no = $row['item_no'];
        $display_category = $row['category'];
        $display_unit = $row['unit'];
        
        // If we have a latest item_no from purchases, use that instead of product's item_no
        if (!empty($latest_item_no)) {
            $display_item_no = $latest_item_no;
            
            // Get category and unit from canvas_items using the latest item_no
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
        } else {
            // Fallback: try to extract from product name
            if (empty($display_item_no) && strpos($row['name'], ' - ') !== false) {
                $parts = explode(' - ', $row['name'], 2);
                $display_item_no = trim($parts[0]);
            }
            
            // Get category and unit from canvas_items
            if (!empty($display_item_no)) {
                $canvas_query = $conn->prepare("SELECT category, unit FROM canvas_items WHERE item_no = ?");
                $canvas_query->bind_param("s", $display_item_no);
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
        
        // Store the display values
        $row['display_item_no'] = $display_item_no;
        $row['display_category'] = $display_category;
        $row['display_unit'] = $display_unit;
        $row['unit_price'] = $unit_price; // Store unit price
        $row['total_value'] = $total_value; // Store total value
        
        // Get description
        $display_description = $row['description'];
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
        if (empty($display_description) && strpos($row['name'], ' - ') !== false) {
            $parts = explode(' - ', $row['name'], 2);
            $display_description = trim($parts[1]);
        }
        $row['display_description'] = $display_description;
        
        $products_with_stock[] = $row;
        
        $totalProducts++;
        if ($quantity == 0) {
            $outOfStock++;
        }
    }
}

// Get categories from canvas_items table
$categories = $conn->query("SELECT DISTINCT category FROM canvas_items WHERE category IS NOT NULL AND category != '' ORDER BY category");

// Get unique categories from products_with_stock for stats
$unique_categories = [];
foreach ($products_with_stock as $product) {
    if (!empty($product['display_category']) && !in_array($product['display_category'], $unique_categories)) {
        $unique_categories[] = $product['display_category'];
    }
}
$categoryCount = count($unique_categories);

$current_user = getCurrentUser();

// Include header
require_once 'include/header.php';
?>

<style>
/* Sort Dropdown Styles */
.sort-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sort-label {
    color: var(--text-secondary);
    font-size: 14px;
}

.sort-dropdown {
    position: relative;
    display: inline-block;
}

.sort-button {
    padding: 10px 20px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    min-width: 200px;
    justify-content: space-between;
}

.sort-button:hover {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

.sort-button i {
    color: #75e6da;
    font-size: 14px;
}

.sort-button .arrow {
    color: #75e6da;
    font-size: 12px;
}

.sort-dropdown-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    margin-top: 5px;
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    z-index: 1000;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    overflow: hidden;
}

.sort-dropdown-content.show {
    display: block;
}

.sort-option {
    padding: 12px 15px;
    cursor: pointer;
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sort-option:last-child {
    border-bottom: none;
}

.sort-option:hover {
    background: rgba(117, 230, 218, 0.1);
    color: #75e6da;
}

.sort-option.active {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.sort-option i {
    margin-right: 10px;
    width: 16px;
    color: #75e6da;
}

.sort-option.active i {
    color: white;
}

/* Date Picker Styles */
.date-picker-container {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-secondary);
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.date-picker-container label {
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
}

.date-picker-container input {
    padding: 8px 12px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
}

.date-picker-container input:focus {
    border-color: #75e6da;
    outline: none;
}

.date-picker-container .btn-date {
    padding: 8px 16px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    border: none;
    border-radius: 6px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
}

.date-picker-container .btn-date:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 10px rgba(117, 230, 218, 0.3);
}

.date-info {
    display: inline-block;
    margin-left: 10px;
    font-size: 12px;
    padding: 4px 8px;
    background: rgba(117, 230, 218, 0.2);
    border-radius: 6px;
    color: #75e6da;
}

/* Export Button Styles */
.btn-export {
    padding: 10px 20px;
    background: linear-gradient(135deg, #27ae60, #219653);
    border: none;
    border-radius: 8px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
}

/* Export Modal Styles */
.export-modal {
    max-width: 500px !important;
}

.export-modal .modal-header {
    background: linear-gradient(135deg, #27ae60, #219653);
}

.date-range-group {
    margin-bottom: 20px;
}

.date-range-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 14px;
}

.date-range-group input {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
}

.date-range-group input:focus {
    border-color: #75e6da;
    outline: none;
}

.stock-preview {
    background: var(--bg-secondary);
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
    max-height: 300px;
    overflow-y: auto;
}

.stock-preview h4 {
    margin: 0 0 10px 0;
    color: var(--text-primary);
    font-size: 14px;
}

.stock-preview-table {
    width: 100%;
    font-size: 12px;
    border-collapse: collapse;
}

.stock-preview-table th,
.stock-preview-table td {
    padding: 6px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.stock-preview-table th {
    color: var(--text-secondary);
    font-weight: 600;
}

.preview-loading {
    text-align: center;
    padding: 20px;
    color: var(--text-secondary);
}

/* Category badge */
.category-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

/* Action buttons */
.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    margin: 0 3px;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.action-btn.edit {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.action-btn.delete {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
}

.action-btn.view {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    border-color: #75e6da;
    box-shadow: 0 10px 25px rgba(117, 230, 218, 0.2);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.stat-details h3 {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 4px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.stat-value.small {
    font-size: 18px;
}

.stat-trend.positive {
    color: #75e6da;
}

.stat-trend.negative {
    color: #d63031;
}

/* Welcome section */
.welcome-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.welcome-text h1 {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.welcome-text p {
    color: var(--text-secondary);
    font-size: 14px;
}

/* Search wrapper */
.search-wrapper {
    width: 250px;
    position: relative;
}

.search-wrapper i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.search-wrapper input {
    width: 100%;
    padding: 10px 12px 10px 35px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.search-wrapper input:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

/* Top bar */
.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.top-bar-left {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

/* ===== CONTAINER DESIGN ===== */
.page-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 0;
    overflow-x: auto;
    overflow-y: visible;
    min-height: 300px;
    display: flex;
    flex-direction: column;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1300px;
}

.products-table th {
    padding: 15px 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
    background: var(--bg-secondary);
}

.products-table td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    font-size: 13px;
}

.products-table tbody tr:hover {
    background: var(--bg-secondary);
}

.price-cell {
    font-weight: 600;
    color: #75e6da;
}

.total-value-cell {
    font-weight: 700;
    color: #6c5ce7;
    font-size: 14px;
}

.unit-price-cell {
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 12px;
}

/* No results message */
.no-results {
    text-align: center;
    padding: 40px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-top: 20px;
}

.no-results i {
    font-size: 48px;
    color: var(--text-secondary);
    margin-bottom: 15px;
}

.no-results h3 {
    color: var(--text-primary);
    margin-bottom: 10px;
}

.no-results p {
    color: var(--text-secondary);
}

/* Product icon */
.product-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-details h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-primary);
    border-radius: 12px;
    border: 2px dashed var(--border-color);
}

.empty-state i {
    font-size: 64px;
    color: var(--text-secondary);
    opacity: 0.5;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 24px;
    color: var(--text-primary);
    margin-bottom: 10px;
    font-weight: 600;
}

.empty-state p {
    color: var(--text-primary);
    font-size: 16px;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 20px;
    font-weight: 500;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    overflow-y: auto;
}

.modal-content {
    background: var(--bg-primary);
    margin: 50px auto;
    width: 90%;
    max-width: 600px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.modal-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #75e6da, #5fd9d0, #4ab9b0);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    color: white;
    font-size: 18px;
    font-weight: 600;
}

.close-modal {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
}

.close-modal:hover {
    color: #ddd;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    border-radius: 0 0 12px 12px;
}

/* Form styles */
.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 14px;
}

.form-group .required {
    color: #75e6da;
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
}

/* Button styles */
.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-primary {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.btn-secondary {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

.btn-danger {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #27ae60, #219653);
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
}

/* Alert styles */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    padding: 15px 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: #75e6da;
    color: #1a1c3c;
}

.alert-danger {
    background: #d63031;
    color: white;
}

/* Updated Item Badge */
.updated-item-badge {
    display: inline-block;
    background: #75e6da;
    color: #1a1c3c;
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 6px;
    font-weight: 600;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
</style>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Products Management</h1>
        <p>Manage your product inventory</p>
    </div>
</div>

<!-- Statistics Cards - ADDED TOTAL INVENTORY VALUE CARD -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-cubes"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL PRODUCTS</h3>
            <p class="stat-value"><?php echo $totalProducts; ?></p>
            <span class="stat-trend positive">Active items</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-tags"></i>
        </div>
        <div class="stat-details">
            <h3>CATEGORIES</h3>
            <p class="stat-value"><?php echo $categoryCount; ?></p>
            <span class="stat-trend positive">Categories</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-details">
            <h3>OUT OF STOCK</h3>
            <p class="stat-value"><?php echo $outOfStock; ?></p>
            <span class="stat-trend negative">Need restock</span>
        </div>
    </div>
    
    <!-- NEW: Total Inventory Value Card -->
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL INVENTORY VALUE</h3>
            <p class="stat-value small">₱<?php echo number_format($totalInventoryValue, 2); ?></p>
            <span class="stat-trend positive">Total stock value</span>
        </div>
    </div>
</div>

<!-- Top Bar with Search, Date Picker, Export Button, and Sort Dropdown -->
<div class="top-bar">
    <div class="top-bar-left">
        <div class="search-wrapper">
            <i class="fas fa-search"></i>
            <input type="text" id="productSearch" placeholder="Search by Item No, Category, or Description..." onkeyup="searchProducts()">
        </div>
        
        <!-- Date Picker -->
        <div class="date-picker-container">
            <label><i class="fas fa-calendar-alt"></i> Stock as of:</label>
            <input type="date" id="stockDatePicker" value="<?php echo $selected_date; ?>">
            <button class="btn-date" onclick="applyDateFilter()">
                <i class="fas fa-check"></i> Apply
            </button>
            <?php if($selected_date != date('Y-m-d')): ?>
                <span class="date-info">
                    <i class="fas fa-history"></i> Historical View
                </span>
            <?php endif; ?>
        </div>
        
        <!-- Export Button -->
        <button class="btn-export" onclick="openExportModal()">
            <i class="fas fa-file-excel"></i> Export
        </button>
    </div>
    
    <!-- Sort Dropdown -->
    <div class="sort-container">
        <span class="sort-label">Sort by:</span>
        <div class="sort-dropdown" id="sortDropdown">
            <button class="sort-button" onclick="toggleDropdown()">
                <span><i class="fas fa-sort"></i> <?php echo $current_sort_label; ?></span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="sort-dropdown-content" id="sortDropdownContent">
                <div class="sort-option <?php echo strpos($sort, 'name') === 0 ? 'active' : ''; ?>" onclick="applySort('name')">
                    <span><i class="fas fa-font"></i> Product Name</span>
                    <?php if(strpos($sort, 'name') === 0): ?>
                        <i class="fas fa-arrow-<?php echo $sort == 'name_asc' ? 'up' : 'down'; ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="sort-option <?php echo strpos($sort, 'category') === 0 ? 'active' : ''; ?>" onclick="applySort('category')">
                    <span><i class="fas fa-tags"></i> Category</span>
                    <?php if(strpos($sort, 'category') === 0): ?>
                        <i class="fas fa-arrow-<?php echo $sort == 'category_asc' ? 'up' : 'down'; ?>"></i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== PRODUCTS CONTAINER ===== -->
<div class="page-container" id="tableContainer">
    <?php if (!empty($products_with_stock)): ?>
        <!-- Products Table - UPDATED WITH UNIT PRICE AND TOTAL VALUE -->
        <table class="products-table" id="productsTable">
            <thead>
                <tr>
                    <th>Item No</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Unit Price (₱)</th>
                    <th>Quantity Stock</th>
                    <th>Total Value (₱)</th>
                    <th>Unit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="productsPageBody">
                <?php foreach($products_with_stock as $row): 
                    $quantity = $row['historical_quantity'];
                    $display_item_no = $row['display_item_no'];
                    $display_category = $row['display_category'];
                    $display_unit = $row['display_unit'];
                    $display_description = $row['display_description'];
                    $unit_price = $row['unit_price'];
                    $total_value = $row['total_value'];
                    
                    // Check if this product has been updated
                    $is_updated = ($display_item_no != $row['item_no'] && !empty($row['item_no']));
                    
                    // Calculate stock percentage
                    $stockPercentage = min(($quantity / 50) * 100, 100);
                    
                    // Determine status and colors
                    if($quantity == 0) {
                        $status = 'Out of Stock';
                        $statusColor = '#a00c0c';
                        $bgColor = '#d6303120';
                    } else if($quantity <= 5) {
                        $status = 'Critical';
                        $statusColor = '#ff0000';
                        $bgColor = '#d6303120';
                    } else if($quantity <= 20) {
                        $status = 'Low Stock';
                        $statusColor = '#e2e60c';
                        $bgColor = '#f39c1220';
                    } else {
                        $status = 'In Stock';
                        $statusColor = '#00ff4c';
                        $bgColor = '#00b89420';
                    }
                ?>
                    <tr data-product-id="<?php echo $row['id']; ?>" 
                        data-item-no="<?php echo htmlspecialchars($display_item_no); ?>"
                        data-description="<?php echo htmlspecialchars($display_description); ?>"
                        data-category="<?php echo htmlspecialchars($display_category ?? ''); ?>"
                        class="product-row">
                        <td>
                            <div class="product-info">
                                <div class="product-icon">
                                    <i class="fas fa-box"></i>
                                </div>
                                <div class="product-details">
                                    <h4>
                                        <?php echo htmlspecialchars($display_item_no); ?>
                                        <?php if($is_updated): ?>
                                            <span class="updated-item-badge" title="Item No was updated from <?php echo htmlspecialchars($row['item_no']); ?> to <?php echo htmlspecialchars($display_item_no); ?>">
                                                <i class="fas fa-sync-alt"></i> Updated
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                </div>
                            </div>
                         </td>
                         <td>
                            <span class="category-badge">
                                <?php echo htmlspecialchars($display_category ?? 'Accessories'); ?>
                            </span>
                         </td>
                          <td class="unit-price-cell"><?php echo htmlspecialchars($display_description); ?> </td>
                        <td class="unit-price-cell">₱<?php echo number_format($unit_price, 2); ?> </td>
                          <td>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 12px; padding: 4px 12px; border-radius: 20px; background-color: <?php echo $bgColor; ?>; color: <?php echo $statusColor; ?>; font-weight: 600;">
                                        <?php echo $status; ?>
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 8px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                        <div style="width: <?php echo $stockPercentage; ?>%; height: 100%; background-color: <?php echo $statusColor; ?>; border-radius: 4px;"></div>
                                    </div>
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $statusColor; ?>;">
                                        <?php echo $quantity; ?>
                                    </span>
                                </div>
                            </div>
                          </td>
                          <td class="total-value-cell">₱<?php echo number_format($total_value, 2); ?> </td>
                          <td>
                            <span class="category-badge">
                                <?php echo htmlspecialchars($display_unit); ?>
                            </span>
                          </td>
                          <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="action-btn edit" onclick='openEditModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['name']); ?>, <?php echo json_encode($display_description); ?>, <?php echo $unit_price; ?>, <?php echo $quantity; ?>, <?php echo json_encode($display_category ?? ''); ?>, <?php echo json_encode($display_unit); ?>)' title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn delete" onclick='openDeleteModal(<?php echo $row['id']; ?>, <?php echo json_encode($display_item_no . ' - ' . $display_description); ?>)' title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <button class="action-btn view" onclick='openViewModal(<?php echo json_encode($row); ?>, <?php echo json_encode($display_item_no); ?>, <?php echo json_encode($display_description); ?>, <?php echo json_encode($display_category); ?>, <?php echo json_encode($display_unit); ?>, <?php echo $unit_price; ?>, <?php echo $total_value; ?>)' title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                          </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
          </table>
    <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="fas fa-store"></i>
            <h3>No Items and products Found</h3>
            <p>You need to purchased</p>
        </div>
    <?php endif; ?>
</div>

<!-- No Results Message (for search) -->
<div id="noResultsMessage" class="no-results" style="display: none;">
    <i class="fas fa-search"></i>
    <h3>No Products Found</h3>
    <p>No products match your search criteria. Try different keywords.</p>
</div>

<!-- Export Modal -->
<div id="exportModal" class="modal">
    <div class="modal-content export-modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-excel"></i> Export Stock Report</h2>
            <span class="close-modal" onclick="closeExportModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="date-range-group">
                <label><i class="fas fa-calendar-alt"></i> Date From</label>
                <input type="date" id="exportDateFrom" value="<?php echo $selected_date; ?>">
            </div>
            <div class="date-range-group">
                <label><i class="fas fa-calendar-alt"></i> Date To</label>
                <input type="date" id="exportDateTo" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <div class="stock-preview" id="stockPreview">
                <h4><i class="fas fa-chart-line"></i> Stock Preview</h4>
                <div id="previewContent" class="preview-loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading preview...
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeExportModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-download"></i> Download Excel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Product</h2>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form id="productForm" method="POST" action="products_process.php">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="product_id" id="productId" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="productName">Product Name <span class="required">*</span></label>
                    <input type="text" id="productName" name="name" class="form-control" required>
                    <small style="color: var(--text-secondary);">Format: Item No - Description (e.g., 0123 - graba)</small>
                </div>
                
                <div class="form-group">
                    <label for="productCategory">Category <span class="required">*</span></label>
                    <select id="productCategory" name="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Hardware">Hardware</option>
                        <option value="Consumables">Consumables</option>
                        <option value="Transportation">Transportation</option>
                        <option value="Tools and Equipment">Tools and Equipment</option>
                        <option value="Miscellaneous">Miscellaneous</option>
                        <option value="Office Supplies">Office Supplies</option>
                        <option value="Rent & Utilities Expenses">Rent & Utilities Expenses</option>
                        <option value="Safe Expenses">Safe Expenses</option>
                        <option value="Admin Payroll">Admin Payroll</option>
                        <option value="InHouse Payroll Office">InHouse Payroll Office</option>
                        <option value="Subcon Payroll - Electrical">Subcon Payroll - Electrical</option>
                        <option value="Subcon Payroll - Auxiliary">Subcon Payroll - Auxiliary</option>
                        <?php 
                        if ($categories && $categories->num_rows > 0) {
                            $categories->data_seek(0);
                            $existing_categories = [];
                            while($cat = $categories->fetch_assoc()): 
                                $cat_name = $cat['category'];
                                $hardcoded = ['Accessories', 'Electronics', 'Hardware', 'Consumables', 'Transportation', 'Tools and Equipment', 'Miscellaneous', 'Office Supplies', 'Rent & Utilities Expenses', 'Safe Expenses', 'Admin Payroll', 'InHouse Payroll Office', 'Subcon Payroll - Electrical', 'Subcon Payroll - Auxiliary'];
                                if (!in_array($cat_name, $hardcoded) && !in_array($cat_name, $existing_categories)) {
                                    $existing_categories[] = $cat_name;
                                    echo '<option value="' . htmlspecialchars($cat_name) . '">' . htmlspecialchars($cat_name) . '</option>';
                                }
                            endwhile; 
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="productDescription">Product Description</label>
                    <textarea id="productDescription" name="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="productPrice">Unit Price (₱) <span class="required">*</span></label>
                        <input type="number" id="productPrice" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group" style="flex: 1;">
                        <label for="productQuantity">Quantity <span class="required">*</span></label>
                        <input type="number" id="productQuantity" name="quantity" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group" style="flex: 1;">
                        <label for="productUnit">Unit <span class="required">*</span></label>
                        <select id="productUnit" name="unit" class="form-control" required>
                            <option value="">Select Unit</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="pair">Pair (pair)</option>
                            <option value="set">Set (set)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="box">Box (box)</option>
                            <option value="dozen">Dozen (dozen)</option>
                            <option value="roll">Roll (roll)</option>
                            <option value="bundle">Bundle (bundle)</option>
                            <option value="meter">Meter (m)</option>
                            <option value="feet">Feet (ft)</option>
                            <option value="kilogram">Kilogram (kg)</option>
                            <option value="liter">Liter (l)</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">
                    <i class="fas fa-save"></i> <span id="submitBtnText">Save Product</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2>Confirm Delete</h2>
            <span class="close-modal" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteProductName"></strong>?</p>
            <p style="color: #d63031; font-size: 14px; margin-top: 10px;">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" action="products_process.php" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="product_id" id="deleteProductId" value="">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Delete Product
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Success/Error Message Display -->
<?php if(isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<script>
// Toggle sort dropdown
function toggleDropdown() {
    var content = document.getElementById('sortDropdownContent');
    content.classList.toggle('show');
}

// Close dropdown when clicking outside
window.onclick = function(event) {
    if (!event.target.matches('.sort-button') && !event.target.closest('.sort-button')) {
        var dropdowns = document.getElementsByClassName('sort-dropdown-content');
        for (var i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
    }
}

// Apply sort
function applySort(sortType) {
    var currentSort = '<?php echo $sort; ?>';
    var newSort = '';
    
    if (sortType === 'name') {
        if (currentSort === 'name_asc') {
            newSort = 'name_desc';
        } else {
            newSort = 'name_asc';
        }
    } else if (sortType === 'category') {
        if (currentSort === 'category_asc') {
            newSort = 'category_desc';
        } else {
            newSort = 'category_asc';
        }
    }
    
    // Get current date
    var currentDate = document.getElementById('stockDatePicker').value;
    
    // Redirect with sort parameter and date
    window.location.href = 'home.php?sort=' + newSort + '&stock_date=' + currentDate;
}

// Apply date filter
function applyDateFilter() {
    var selectedDate = document.getElementById('stockDatePicker').value;
    var currentSort = '<?php echo $sort; ?>';
    window.location.href = 'home.php?sort=' + currentSort + '&stock_date=' + selectedDate;
}

// Search products function
function searchProducts() {
    var input = document.getElementById('productSearch');
    var filter = input.value.toLowerCase();
    var table = document.getElementById('productsTable');
    
    if (!table) {
        return;
    }
    
    var rows = table.getElementsByTagName('tr');
    var hasResults = false;
    
    // Skip the header row (index 0)
    for (var i = 1; i < rows.length; i++) {
        var row = rows[i];
        var cells = row.getElementsByTagName('td');
        var found = false;
        
        // Search in Item No (cell 0), Category (cell 1), Description (cell 2)
        if (cells.length >= 3) {
            var itemNo = cells[0].innerText.toLowerCase();
            var category = cells[1].innerText.toLowerCase();
            var description = cells[2].innerText.toLowerCase();
            
            if (itemNo.indexOf(filter) > -1 || 
                category.indexOf(filter) > -1 || 
                description.indexOf(filter) > -1) {
                found = true;
                hasResults = true;
            }
        }
        
        row.style.display = found ? '' : 'none';
    }
    
    // Show/hide no results message
    var noResultsMsg = document.getElementById('noResultsMessage');
    if (noResultsMsg) {
        noResultsMsg.style.display = hasResults ? 'none' : 'flex';
    }
}

// Modal functions
function openExportModal() {
    document.getElementById('exportModal').style.display = 'block';
    loadStockPreview();
}

function closeExportModal() {
    document.getElementById('exportModal').style.display = 'none';
}

function openEditModal(id, name, description, price, quantity, category, unit) {
    document.getElementById('modalTitle').innerHTML = 'Edit Product';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('productId').value = id;
    document.getElementById('productName').value = name;
    document.getElementById('productDescription').value = description;
    document.getElementById('productPrice').value = price;
    document.getElementById('productQuantity').value = quantity;
    
    // Set category
    var categorySelect = document.getElementById('productCategory');
    for (var i = 0; i < categorySelect.options.length; i++) {
        if (categorySelect.options[i].value === category) {
            categorySelect.selectedIndex = i;
            break;
        }
    }
    
    // Set unit
    var unitSelect = document.getElementById('productUnit');
    for (var i = 0; i < unitSelect.options.length; i++) {
        if (unitSelect.options[i].value === unit) {
            unitSelect.selectedIndex = i;
            break;
        }
    }
    
    document.getElementById('submitBtnText').innerHTML = 'Update Product';
    document.getElementById('productModal').style.display = 'block';
}

function openDeleteModal(id, name) {
    document.getElementById('deleteProductId').value = id;
    document.getElementById('deleteProductName').innerHTML = name;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('productModal').style.display = 'none';
    // Reset form
    document.getElementById('productForm').reset();
    document.getElementById('modalTitle').innerHTML = 'Add New Product';
    document.getElementById('formAction').value = 'add';
    document.getElementById('productId').value = '';
    document.getElementById('submitBtnText').innerHTML = 'Save Product';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    var productModal = document.getElementById('productModal');
    var deleteModal = document.getElementById('deleteModal');
    var exportModal = document.getElementById('exportModal');
    
    if (event.target == productModal) {
        closeModal();
    }
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
    if (event.target == exportModal) {
        closeExportModal();
    }
}

// View modal function
function openViewModal(product, displayItemNo, displayDescription, displayCategory, displayUnit, unitPrice, totalValue) {
    if (typeof product === 'string') {
        product = JSON.parse(product);
    }
    
    var itemNo = displayItemNo || (product.name ? product.name.split(' - ')[0] : 'N/A');
    var description = displayDescription || (product.name ? product.name.split(' - ')[1] : 'N/A');
    var category = displayCategory || product.category || 'N/A';
    var unit = displayUnit || product.unit || 'pcs';
    var quantity = product.historical_quantity || product.quantity || 0;
    var price = unitPrice || product.price || 0;
    var total = totalValue || (quantity * price);
    
    alert(`Product Details:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Item No: ${itemNo}
Category: ${category}
Description: ${description}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Unit Price: ₱${parseFloat(price).toFixed(2)}
Quantity: ${quantity} ${unit}
Total Value: ₱${parseFloat(total).toFixed(2)}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
}

// Load stock preview for export - automatic kapag nagbago ang dates
function loadStockPreview() {
    var dateFrom = document.getElementById('exportDateFrom').value;
    var dateTo = document.getElementById('exportDateTo').value;
    var previewContent = document.getElementById('previewContent');
    
    if (!dateFrom || !dateTo) {
        previewContent.innerHTML = '<div class="preview-loading">Please select both dates</div>';
        return;
    }
    
    previewContent.innerHTML = '<div class="preview-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview...</div>';
    
    // Make AJAX request to get stock data
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_stock_preview.php?from=' + encodeURIComponent(dateFrom) + '&to=' + encodeURIComponent(dateTo), true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            previewContent.innerHTML = xhr.responseText;
        } else {
            previewContent.innerHTML = '<div class="preview-loading">Error loading preview: ' + xhr.status + '</div>';
        }
    };
    xhr.onerror = function() {
        previewContent.innerHTML = '<div class="preview-loading">Error loading preview. Please check your connection.</div>';
    };
    xhr.send();
}

// Export to Excel
function exportToExcel() {
    var dateFrom = document.getElementById('exportDateFrom').value;
    var dateTo = document.getElementById('exportDateTo').value;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both date range');
        return;
    }
    
    window.location.href = 'export_stock.php?from=' + encodeURIComponent(dateFrom) + '&to=' + encodeURIComponent(dateTo);
}

// Initialize date pickers and add event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add event listener for search input
    var searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', searchProducts);
    }
    
    // Add event listeners for export date pickers - auto load preview when dates change
    var dateFrom = document.getElementById('exportDateFrom');
    var dateTo = document.getElementById('exportDateTo');
    
    if (dateFrom) {
        dateFrom.addEventListener('change', function() {
            loadStockPreview();
        });
    }
    
    if (dateTo) {
        dateTo.addEventListener('change', function() {
            loadStockPreview();
        });
    }
});

// Modified openExportModal function to load preview when modal opens
function openExportModal() {
    document.getElementById('exportModal').style.display = 'block';
    // Set default dates if not set
    var dateFrom = document.getElementById('exportDateFrom');
    var dateTo = document.getElementById('exportDateTo');
    var selectedDate = document.getElementById('stockDatePicker').value;
    
    if (!dateFrom.value) {
        dateFrom.value = selectedDate;
    }
    if (!dateTo.value) {
        dateTo.value = '<?php echo date('Y-m-d'); ?>';
    }
    
    loadStockPreview();
}

// Initialize date picker and search on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add event listener for search input
    var searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', searchProducts);
    }
});

// Close alerts after 3 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.remove();
        }, 300);
    });
}, 3000);
</script>

<?php
// Include footer
require_once 'include/footer.php';
?>