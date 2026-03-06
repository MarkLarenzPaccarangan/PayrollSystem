<?php
// stock_tracker.php
ob_start();
require_once 'config.php';
requireLogin();

// Get current user
$current_user = getCurrentUser();

// Handle form submissions for stock in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_stock_in'])) {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $reference = $conn->real_escape_string($_POST['reference']);
        $notes = $conn->real_escape_string($_POST['notes']);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update product stock
            $update_sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $quantity, $product_id);
            $stmt->execute();
            
            // Record stock movement
            $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) 
                             VALUES (?, 'in', ?, ?, ?, ?)";
            $stmt = $conn->prepare($movement_sql);
            $stmt->bind_param("iissi", $product_id, $quantity, $reference, $notes, $current_user['id']);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['success'] = "Stock added successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error adding stock: " . $e->getMessage();
        }
        
        header("Location: stock_tracker.php");
        exit();
    }
    
    if (isset($_POST['add_stock_out'])) {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $reference = $conn->real_escape_string($_POST['reference']);
        $notes = $conn->real_escape_string($_POST['notes']);
        
        // Check if enough stock
        $check_sql = "SELECT quantity as current_stock FROM products WHERE id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if ($product['current_stock'] < $quantity) {
            $_SESSION['error'] = "Insufficient stock! Available: " . $product['current_stock'];
            header("Location: stock_tracker.php");
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update product stock
            $update_sql = "UPDATE products SET quantity = quantity - ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $quantity, $product_id);
            $stmt->execute();
            
            // Record stock movement
            $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) 
                             VALUES (?, 'out', ?, ?, ?, ?)";
            $stmt = $conn->prepare($movement_sql);
            $stmt->bind_param("iissi", $product_id, $quantity, $reference, $notes, $current_user['id']);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['success'] = "Stock removed successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error removing stock: " . $e->getMessage();
        }
        
        header("Location: stock_tracker.php");
        exit();
    }
}

// Get date filter (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get stock movements for the selected date
$movements_sql = "SELECT 
                    DATE(sm.created_at) as movement_date,
                    p.name as product_name,
                    SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END) as stock_in,
                    SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END) as stock_out
                  FROM stock_movements sm
                  JOIN products p ON sm.product_id = p.id
                  WHERE DATE(sm.created_at) = ?
                  GROUP BY p.id, p.name, DATE(sm.created_at)
                  ORDER BY p.name";
$stmt = $conn->prepare($movements_sql);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$daily_movements = $stmt->get_result();

// Get current stock balances for all products
$balances_sql = "SELECT 
                    p.id,
                    p.name,
                    p.quantity as current_balance,
                    p.unit
                 FROM products p
                 ORDER BY p.name";
$balances = $conn->query($balances_sql);

// Create an array of balances for quick lookup
$balance_array = [];
while ($balance = $balances->fetch_assoc()) {
    $balance_array[$balance['name']] = $balance['current_balance'];
}

// Reset the balances result set for later use
$balances->data_seek(0);

// Get available dates for the filter
$dates_sql = "SELECT DISTINCT DATE(created_at) as movement_date 
              FROM stock_movements 
              ORDER BY movement_date DESC";
$available_dates = $conn->query($dates_sql);

// Get statistics
$total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];
$total_stock_in = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'in'")->fetch_assoc()['total'] ?? 0;
$total_stock_out = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'out'")->fetch_assoc()['total'] ?? 0;
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < low_stock_threshold")->fetch_assoc()['count'] ?? 0;

require_once 'include/header.php';
?>

<style>
/* Stock Tracker Specific Styles */
.stock-tracker-container {
    padding: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
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
    animation: fadeInUp 0.5s ease;
    animation-fill-mode: both;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.15s; }
.stat-card:nth-child(3) { animation-delay: 0.2s; }
.stat-card:nth-child(4) { animation-delay: 0.25s; }

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(117, 230, 218, 0.2);
    border-color: #75e6da;
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
    transition: transform 0.3s ease;
}

.stat-card:hover .stat-icon {
    transform: scale(1.1) rotate(5deg);
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

.stat-value.warning {
    color: #f39c12;
}

.stat-trend {
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.stat-trend.positive {
    color: #75e6da;
}

.stat-trend.negative {
    color: #d63031;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
}

.btn-action {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-stock-in {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
    box-shadow: 0 4px 15px rgba(0, 184, 148, 0.3);
}

.btn-stock-out {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
    box-shadow: 0 4px 15px rgba(232, 67, 147, 0.3);
}

.btn-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(117, 230, 218, 0.4);
}

/* Date Navigation */
.date-navigation {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}

.date-selector {
    display: flex;
    align-items: center;
    gap: 15px;
}

.date-nav-btn {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.3s ease;
}

.date-nav-btn:hover {
    background: var(--hover-bg);
    border-color: #75e6da;
    transform: translateY(-2px);
}

.current-date {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    padding: 0 15px;
}

.date-picker {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 8px 12px;
    color: var(--text-primary);
    font-size: 14px;
}

.date-actions {
    display: flex;
    gap: 10px;
}

.btn-date {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.btn-today {
    background: #75e6da;
    color: #1a1c3c;
}

.btn-export {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-date:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
}

/* Tracker Table */
.tracker-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    overflow-x: auto;
}

.tracker-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.tracker-header h3 {
    color: var(--text-primary);
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.tracker-header h3 i {
    color: #75e6da;
}

.tracker-badge {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 5px 15px;
    font-size: 13px;
    color: var(--text-secondary);
}

.tracker-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.tracker-table th {
    background: var(--bg-secondary);
    padding: 15px;
    text-align: left;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
}

.tracker-table td {
    padding: 12px 15px;
    font-size: 14px;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
}

.tracker-table tbody tr:hover {
    background: var(--hover-bg);
}

.tracker-table .stock-in {
    color: #75e6da;
    font-weight: 600;
}

.tracker-table .stock-out {
    color: #d63031;
    font-weight: 600;
}

.tracker-table .stock-balance {
    color: #6c5ce7;
    font-weight: 700;
}

/* Tab Navigation */
.tab-navigation {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 10px;
}

.tab-btn {
    padding: 10px 20px;
    border-radius: 8px 8px 0 0;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-btn:hover {
    color: var(--text-primary);
    background: var(--hover-bg);
}

.tab-btn.active {
    background: var(--bg-secondary);
    color: #75e6da;
    border-bottom: 2px solid #75e6da;
}

.tab-btn i {
    font-size: 14px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Footer Stats */
.footer-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
    color: var(--text-secondary);
    font-size: 13px;
}

.footer-stats i {
    color: #75e6da;
    margin-right: 5px;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .date-navigation {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-selector {
        justify-content: space-between;
    }
}
</style>

<div class="stock-tracker-container">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-text">
            <h1>Stock In - Out - Balance Tracker</h1>
            <p>Monitor daily inventory movements</p>
        </div>
        <div class="action-buttons">
            <button class="btn-action btn-stock-in" onclick="openStockInModal()">
                <i class="fas fa-plus-circle"></i>
                Record Stock In
            </button>
            <button class="btn-action btn-stock-out" onclick="openStockOutModal()">
                <i class="fas fa-minus-circle"></i>
                Record Stock Out
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-details">
                <h3>TOTAL PRODUCTS</h3>
                <p class="stat-value"><?php echo number_format($total_products); ?></p>
                <span class="stat-trend positive">Active items</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="stat-details">
                <h3>TOTAL STOCK IN</h3>
                <p class="stat-value"><?php echo number_format($total_stock_in); ?></p>
                <span class="stat-trend positive">All time</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-arrow-up"></i>
            </div>
            <div class="stat-details">
                <h3>TOTAL STOCK OUT</h3>
                <p class="stat-value"><?php echo number_format($total_stock_out); ?></p>
                <span class="stat-trend negative">All time</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-details">
                <h3>LOW STOCK ITEMS</h3>
                <p class="stat-value <?php echo $low_stock_count > 0 ? 'warning' : ''; ?>"><?php echo number_format($low_stock_count); ?></p>
                <span class="stat-trend <?php echo $low_stock_count > 0 ? 'warning' : 'positive'; ?>">
                    Need attention
                </span>
            </div>
        </div>
    </div>

    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-selector">
            <button class="date-nav-btn" onclick="changeDate(-1)" title="Previous Day">
                <i class="fas fa-chevron-left"></i>
            </button>
            <span class="current-date" id="currentDateDisplay"><?php echo date('F d, Y', strtotime($selected_date)); ?></span>
            <button class="date-nav-btn" onclick="changeDate(1)" title="Next Day">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="date-actions">
            <input type="date" id="datePicker" class="date-picker" value="<?php echo $selected_date; ?>" onchange="goToDate()">
            <button class="btn-date btn-today" onclick="goToToday()">
                <i class="fas fa-calendar-day"></i> Today
            </button>
            <button class="btn-date btn-export" onclick="exportDailyData()">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <button class="tab-btn active" onclick="switchTab('daily')" id="tabDaily">
            <i class="fas fa-calendar-day"></i> DAILY TRACKER
        </button>
        <button class="tab-btn" onclick="switchTab('balances')" id="tabBalances">
            <i class="fas fa-balance-scale"></i> CURRENT BALANCES
        </button>
    </div>

    <!-- Daily Tracker Tab -->
    <div id="dailyTab" class="tab-content active">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3>
                    <i class="fas fa-clipboard-list"></i>
                    Stock Movements for <?php echo date('M d, Y', strtotime($selected_date)); ?>
                </h3>
                <span class="tracker-badge">
                    <i class="fas fa-box"></i> <?php echo $daily_movements->num_rows; ?> items
                </span>
            </div>
            
            <table class="tracker-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product Name</th>
                        <th>Stock In</th>
                        <th>Stock Out</th>
                        <th>Stock Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($daily_movements && $daily_movements->num_rows > 0): ?>
                        <?php 
                        $daily_movements->data_seek(0);
                        while($movement = $daily_movements->fetch_assoc()): 
                            $product_name = $movement['product_name'];
                            $stock_in = $movement['stock_in'] ?? 0;
                            $stock_out = $movement['stock_out'] ?? 0;
                            $current_balance = $balance_array[$product_name] ?? 0;
                        ?>
                        <tr>
                            <td><?php echo date('d-M', strtotime($movement['movement_date'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($product_name); ?></strong></td>
                            <td class="stock-in"><?php echo $stock_in > 0 ? '+' . $stock_in : '-'; ?></td>
                            <td class="stock-out"><?php echo $stock_out > 0 ? '-' . $stock_out : '-'; ?></td>
                            <td class="stock-balance"><?php echo number_format($current_balance); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                No stock movements found for this date.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer-stats">
                <div>
                    <i class="fas fa-arrow-down" style="color: #75e6da;"></i>
                    Total Stock In: <strong style="color: #75e6da;"><?php 
                        $total_in = 0;
                        $daily_movements->data_seek(0);
                        while($movement = $daily_movements->fetch_assoc()) {
                            $total_in += $movement['stock_in'] ?? 0;
                        }
                        echo number_format($total_in);
                    ?></strong>
                </div>
                <div>
                    <i class="fas fa-arrow-up" style="color: #d63031;"></i>
                    Total Stock Out: <strong style="color: #d63031;"><?php 
                        $total_out = 0;
                        $daily_movements->data_seek(0);
                        while($movement = $daily_movements->fetch_assoc()) {
                            $total_out += $movement['stock_out'] ?? 0;
                        }
                        echo number_format($total_out);
                    ?></strong>
                </div>
                <div>
                    <i class="fas fa-calculator" style="color: #6c5ce7;"></i>
                    Net Change: <strong style="color: #6c5ce7;"><?php echo number_format($total_in - $total_out); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Balances Tab -->
    <div id="balancesTab" class="tab-content">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3>
                    <i class="fas fa-boxes"></i>
                    Current Stock Balances
                </h3>
                <span class="tracker-badge">
                    <i class="fas fa-box"></i> <?php echo $balances->num_rows; ?> products
                </span>
            </div>
            
            <table class="tracker-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Unit</th>
                        <th>Current Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($balances && $balances->num_rows > 0): ?>
                        <?php while($product = $balances->fetch_assoc()): 
                            $balance = $product['current_balance'];
                            $threshold = $product['low_stock_threshold'] ?? 10;
                            
                            if ($balance <= 0) {
                                $status_class = 'stock-out';
                                $status_text = 'Out of Stock';
                            } elseif ($balance < $threshold) {
                                $status_class = 'warning';
                                $status_text = 'Low Stock';
                            } else {
                                $status_class = 'stock-in';
                                $status_text = 'In Stock';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($product['unit'] ?? 'pcs'); ?></td>
                            <td class="stock-balance"><?php echo number_format($balance); ?></td>
                            <td><span class="type-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                <i class="fas fa-box-open" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                No products found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Stock In Modal -->
<div id="stockInModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Record Stock In</h2>
            <button class="close-btn" onclick="closeStockInModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="add_stock_in" value="1">
                
                <div class="form-group">
                    <label>Select Product</label>
                    <select name="product_id" required>
                        <option value="">Choose product...</option>
                        <?php 
                        $products_list = $conn->query("SELECT id, name, unit FROM products ORDER BY name");
                        while($product = $products_list->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $product['id']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['unit'] ?? 'pcs'; ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" min="1" required placeholder="Enter quantity">
                </div>
                
                <div class="form-group">
                    <label>Reference (PO #, Delivery Receipt, etc.)</label>
                    <input type="text" name="reference" placeholder="e.g., PO-2024-001">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStockInModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Record Stock In
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Out Modal -->
<div id="stockOutModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-minus-circle"></i> Record Stock Out</h2>
            <button class="close-btn" onclick="closeStockOutModal()">&times;</button>
        </div>
        <form method="POST" action="" onsubmit="return validateStockOut()">
            <div class="modal-body">
                <input type="hidden" name="add_stock_out" value="1">
                
                <div class="form-group">
                    <label>Select Product</label>
                    <select name="product_id" id="stockOutProduct" required onchange="checkProductStock()">
                        <option value="">Choose product...</option>
                        <?php 
                        $products_list = $conn->query("SELECT id, name, quantity as stock, unit FROM products ORDER BY name");
                        while($product = $products_list->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $product['id']; ?>" data-stock="<?php echo $product['stock']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['unit'] ?? 'pcs'; ?>) - Available: <?php echo $product['stock']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" id="stockOutQuantity" min="1" required placeholder="Enter quantity">
                </div>
                
                <div class="form-group">
                    <label>Available Stock</label>
                    <input type="text" id="availableStock" readonly disabled class="bg-opacity-50" value="0">
                </div>
                
                <div class="form-group">
                    <label>Reference (SO #, Order #, etc.)</label>
                    <input type="text" name="reference" placeholder="e.g., SO-2024-001">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStockOutModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-save"></i> Record Stock Out
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Success/Error Message Display -->
<?php if(isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="position: fixed; top: 20px; right: 20px; z-index: 9999; padding: 15px 20px; background: #75e6da; color: #1a1c3c; border-radius: 8px; box-shadow: 0 4px 6px rgba(117, 230, 218, 0.3);">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="position: fixed; top: 20px; right: 20px; z-index: 9999; padding: 15px 20px; background: #d63031; color: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(214, 48, 49, 0.3);">
        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<script>
// Tab switching
function switchTab(tab) {
    const dailyTab = document.getElementById('dailyTab');
    const balancesTab = document.getElementById('balancesTab');
    const tabDaily = document.getElementById('tabDaily');
    const tabBalances = document.getElementById('tabBalances');
    
    if (tab === 'daily') {
        dailyTab.classList.add('active');
        balancesTab.classList.remove('active');
        tabDaily.classList.add('active');
        tabBalances.classList.remove('active');
    } else {
        dailyTab.classList.remove('active');
        balancesTab.classList.add('active');
        tabDaily.classList.remove('active');
        tabBalances.classList.add('active');
    }
}

// Date navigation
function changeDate(days) {
    const currentDate = new Date(document.getElementById('datePicker').value);
    currentDate.setDate(currentDate.getDate() + days);
    const newDate = currentDate.toISOString().split('T')[0];
    window.location.href = 'stock_tracker.php?date=' + newDate;
}

function goToDate() {
    const selectedDate = document.getElementById('datePicker').value;
    window.location.href = 'stock_tracker.php?date=' + selectedDate;
}

function goToToday() {
    const today = new Date().toISOString().split('T')[0];
    window.location.href = 'stock_tracker.php?date=' + today;
}

// Export daily data
function exportDailyData() {
    const date = document.getElementById('datePicker').value;
    const formattedDate = new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    
    const rows = document.querySelectorAll('#dailyTab .tracker-table tbody tr');
    let csv = 'Date,Product Name,Stock In,Stock Out,Stock Balance\n';
    
    rows.forEach(row => {
        if (row.style.display !== 'none' && row.cells.length > 1) {
            const cells = row.querySelectorAll('td');
            const rowDate = cells[0]?.textContent.trim() || '';
            const product = cells[1]?.textContent.trim() || '';
            const stockIn = cells[2]?.textContent.trim() || '';
            const stockOut = cells[3]?.textContent.trim() || '';
            const balance = cells[4]?.textContent.trim() || '';
            
            csv += `"${rowDate}","${product}","${stockIn}","${stockOut}","${balance}"\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'stock_tracker_' + date + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
    
    showToast('Daily data exported successfully!', 'success');
}

// Modal functions
function openStockInModal() {
    document.getElementById('stockInModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeStockInModal() {
    const modal = document.getElementById('stockInModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

function openStockOutModal() {
    document.getElementById('stockOutModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeStockOutModal() {
    const modal = document.getElementById('stockOutModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// Check product stock for stock out
function checkProductStock() {
    const select = document.getElementById('stockOutProduct');
    const selectedOption = select.options[select.selectedIndex];
    const stock = selectedOption.getAttribute('data-stock') || 0;
    document.getElementById('availableStock').value = stock;
}

// Validate stock out quantity
function validateStockOut() {
    const productSelect = document.getElementById('stockOutProduct');
    const quantity = document.getElementById('stockOutQuantity').value;
    const available = document.getElementById('availableStock').value;
    
    if (!productSelect.value) {
        alert('Please select a product');
        return false;
    }
    
    if (parseInt(quantity) > parseInt(available)) {
        alert('Error: Insufficient stock! Available: ' + available);
        return false;
    }
    
    if (parseInt(quantity) <= 0) {
        alert('Please enter a valid quantity');
        return false;
    }
    
    return true;
}

// Toast notification function
function showToast(message, type = 'success') {
    let toast = document.getElementById('dynamicToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'dynamicToast';
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.zIndex = '9999';
        toast.style.padding = '15px 20px';
        toast.style.borderRadius = '8px';
        toast.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
        toast.style.transition = 'all 0.3s ease';
        document.body.appendChild(toast);
    }
    
    if (type === 'success') {
        toast.style.background = '#75e6da';
        toast.style.color = '#1a1c3c';
    } else if (type === 'error') {
        toast.style.background = '#d63031';
        toast.style.color = 'white';
    } else if (type === 'info') {
        toast.style.background = '#6c5ce7';
        toast.style.color = 'white';
    }
    
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle')}"></i> ${message}`;
    toast.style.display = 'block';
    
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => {
            toast.style.display = 'none';
            toast.style.opacity = '1';
        }, 300);
    }, 3000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['stockInModal', 'stockOutModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target == modal) {
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
                document.body.style.overflow = 'auto';
            }, 200);
        }
    });
};

// Auto-hide alerts after 3 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => {
            alert.style.display = 'none';
        }, 300);
    });
}, 3000);
</script>

<?php require_once 'include/footer.php'; ?>