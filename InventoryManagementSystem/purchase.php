<?php
// purchase.php - Purchase Management (Shows only PENDING and PROCESSING)
ob_start();
require_once 'config.php';
requireLogin();

// Get current user
$current_user = getCurrentUser();

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle delete purchase
if (isset($_GET['delete'])) {
    $purchase_id = intval($_GET['delete']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First get purchase items to restore stock
        $items_query = "SELECT * FROM purchase_items WHERE purchase_id = ?";
        $stmt = $conn->prepare($items_query);
        $stmt->bind_param("i", $purchase_id);
        $stmt->execute();
        $items_result = $stmt->get_result();
        
        // Restore stock for each item
        while ($item = $items_result->fetch_assoc()) {
            // Update company_prices stock
            $update_stock = "UPDATE company_prices SET quantity = quantity + ? WHERE id = ?";
            $stock_stmt = $conn->prepare($update_stock);
            $stock_stmt->bind_param("ii", $item['quantity'], $item['price_id']);
            $stock_stmt->execute();
            $stock_stmt->close();
        }
        
        // Delete purchase items first (foreign key constraint)
        $delete_items = "DELETE FROM purchase_items WHERE purchase_id = ?";
        $stmt = $conn->prepare($delete_items);
        $stmt->bind_param("i", $purchase_id);
        $stmt->execute();
        $stmt->close();
        
        // Delete the purchase
        $delete_purchase = "DELETE FROM purchases WHERE id = ?";
        $stmt = $conn->prepare($delete_purchase);
        $stmt->bind_param("i", $purchase_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        $_SESSION['success'] = "Purchase deleted successfully!";
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error'] = "Error deleting purchase: " . $e->getMessage();
    }
    
    header("Location: purchase.php");
    exit();
}

// Handle update purchase status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_purchase'])) {
    $purchase_id = intval($_POST['purchase_id']);
    $status = $_POST['status'];
    
    $update_query = "UPDATE purchases SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $status, $purchase_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Purchase updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating purchase: " . $conn->error;
    }
    $stmt->close();
    
    header("Location: purchase.php");
    exit();
}

// IMPORTANT: Get purchases - ONLY pending and processing (hindi kasama ang completed)
$purchases = $conn->query("SELECT * FROM purchases WHERE status IN ('pending', 'processing') ORDER BY purchase_date DESC");

// Get purchase statistics
$totalPurchases = $conn->query("SELECT COUNT(*) as count FROM purchases WHERE status IN ('pending', 'processing')")->fetch_assoc()['count'] ?? 0;
$completedPurchases = $conn->query("SELECT COUNT(*) as count FROM purchases WHERE status='completed'")->fetch_assoc()['count'] ?? 0;
$pendingPurchases = $conn->query("SELECT COUNT(*) as count FROM purchases WHERE status='pending'")->fetch_assoc()['count'] ?? 0;
$cancelledPurchases = $conn->query("SELECT COUNT(*) as count FROM purchases WHERE status='cancelled'")->fetch_assoc()['count'] ?? 0;
$totalRevenue = $conn->query("SELECT SUM(total_amount) as total FROM purchases WHERE status='completed'")->fetch_assoc()['total'] ?? 0;

require_once 'include/header.php';
?>

<style>
/* Purchase Page Specific Styles */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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
    animation: fadeInUp 0.5s ease;
    animation-fill-mode: both;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.15s; }
.stat-card:nth-child(3) { animation-delay: 0.2s; }
.stat-card:nth-child(4) { animation-delay: 0.25s; }
.stat-card:nth-child(5) { animation-delay: 0.3s; }

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

/* Company badge */
.company-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

/* Action buttons */
.action-btn {
    width: 35px;
    height: 35px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    margin: 0 3px;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.action-btn.view {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
    box-shadow: 0 4px 10px rgba(0, 184, 148, 0.3);
}

.action-btn.edit {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
    box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
}

.action-btn.delete {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
    box-shadow: 0 4px 10px rgba(232, 67, 147, 0.3);
}

.action-btn.order-now {
    background: linear-gradient(135deg, #75e6da, #00b894);
    color: white;
    box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
    padding: 0 12px;
    width: auto;
    font-size: 12px;
}

.action-btn.order-now:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(117, 230, 218, 0.4);
}

.action-btn.order-now i {
    margin-right: 5px;
}

.actions-cell {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

/* Status badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.status-completed {
    background: rgba(117, 230, 218, 0.15);
    color: #75e6da;
}

.status-pending {
    background: rgba(243, 156, 18, 0.15);
    color: #f39c12;
}

.status-processing {
    background: rgba(108, 92, 231, 0.15);
    color: #6c5ce7;
}

.status-cancelled {
    background: rgba(214, 48, 49, 0.15);
    color: #d63031;
}

/* Price cell */
.price-cell {
    font-weight: 600;
    color: #75e6da;
}

/* Table styles */
.table-wrapper {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    overflow-x: auto;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
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

/* Top bar with search and history button */
.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.search-wrapper {
    position: relative;
    width: 300px;
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
    padding: 12px 10px 12px 35px;
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

/* History button sa itaas */
.btn-history {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 10px rgba(52, 152, 219, 0.3);
}

.btn-history:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(52, 152, 219, 0.4);
}

.btn-history i {
    font-size: 16px;
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
    animation: fadeIn 0.3s ease;
    overflow-y: auto;
}

.modal-content {
    position: relative;
    background: var(--bg-primary);
    margin: 50px auto;
    width: 90%;
    max-width: 600px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    animation: slideInDown 0.3s ease;
    border: 1px solid var(--border-color);
}

.modal-lg {
    max-width: 700px;
}

.modal-xl {
    max-width: 1400px;
    width: 95%;
}

.modal-sm {
    max-width: 400px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #75e6da, #5fd9d0, #4ab9b0);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px 12px 0 0;
}

.modal-header h2 {
    margin: 0;
    color: white;
    font-size: 18px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 80%;
}

.close-btn {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
}

.close-btn:hover {
    background: #e84393;
    transform: rotate(90deg);
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
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
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-weight: 500;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
    outline: none;
}

.bg-opacity-50 {
    opacity: 0.7;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}

/* Empty state */
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
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-state h3 {
    color: var(--text-primary);
    margin-bottom: 10px;
}

.empty-state p {
    color: var(--text-secondary);
    margin-bottom: 20px;
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

/* Detail table styles */
.detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.detail-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
}

.detail-table td:first-child {
    background: var(--bg-secondary);
    font-weight: 600;
    width: 35%;
}

/* History Stats Styles */
.history-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.history-stat-card {
    background: linear-gradient(135deg, #3498db20, #2980b920);
    border: 1px solid #3498db40;
    border-radius: 10px;
    padding: 15px;
    text-align: center;
}

.history-stat-card .stat-label {
    font-size: 11px;
    color: var(--text-secondary);
    text-transform: uppercase;
    margin-bottom: 5px;
}

.history-stat-card .stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #3498db;
}

.history-stat-card .stat-sub {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 5px;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.history-table th {
    background: var(--bg-secondary);
    padding: 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
}

.history-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    font-size: 13px;
}

.history-table tr:hover {
    background: var(--bg-secondary);
}

.history-table .amount {
    font-weight: 600;
    color: #75e6da;
}

.history-highlight {
    background: rgba(52, 152, 219, 0.1) !important;
    border-left: 4px solid #3498db;
}

.history-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    background: #3498db;
    color: white;
}

/* Summary bar */
.summary-bar {
    background: rgba(52, 152, 219, 0.1);
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-left: 4px solid #3498db;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideInDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes fadeOut {
    to {
        opacity: 0;
        transform: translateY(-50px);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInRight {
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
        <h1>Purchase Management</h1>
        <p>Track and manage purchase orders from canvas</p>
    </div>
    <a href="canvas.php" class="btn btn-primary">
        <i class="fas fa-shopping-cart"></i>
        Back to Canvas
    </a>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-shopping-cart"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL PURCHASES</h3>
            <p class="stat-value"><?php echo number_format($totalPurchases); ?></p>
            <span class="stat-trend positive">All time</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
            <h3>COMPLETED</h3>
            <p class="stat-value"><?php echo number_format($completedPurchases); ?></p>
            <span class="stat-trend positive"><?php echo $totalPurchases ? round(($completedPurchases/$totalPurchases)*100, 1) : 0; ?>%</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-details">
            <h3>PENDING</h3>
            <p class="stat-value"><?php echo number_format($pendingPurchases); ?></p>
            <span class="stat-trend negative"><?php echo $totalPurchases ? round(($pendingPurchases/$totalPurchases)*100, 1) : 0; ?>%</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-details">
            <h3>CANCELLED</h3>
            <p class="stat-value"><?php echo number_format($cancelledPurchases); ?></p>
            <span class="stat-trend negative"><?php echo $totalPurchases ? round(($cancelledPurchases/$totalPurchases)*100, 1) : 0; ?>%</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-peso-sign"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL REVENUE</h3>
            <p class="stat-value">₱<?php echo number_format($totalRevenue, 2); ?></p>
            <span class="stat-trend positive">Completed purchases</span>
        </div>
    </div>
</div>

<!-- Top Bar with Search and History Button -->
<div class="top-bar">
    <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" id="purchaseSearch" placeholder="Search purchases..." onkeyup="searchPurchases()">
    </div>
    
    <!-- Transaction History Button -->
    <button class="btn-history" onclick="viewAllTransactions()">
        <i class="fas fa-history"></i>
        Transaction History
    </button>
</div>

<!-- Purchases Table - ONLY PENDING AND PROCESSING -->
<div class="table-wrapper">
    <table class="products-table" id="purchasesTable">
        <thead>
            <tr>
                <th>Item No</th>
                <th>Description</th>
                <th>Company</th>
                <th>Contact Person</th>
                <th>Qty</th>
                <th>Price/Unit</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="purchasesPageBody">
            <?php if ($purchases && $purchases->num_rows > 0): ?>
                <?php while($purchase = $purchases->fetch_assoc()): 
                    $status_class = $purchase['status'] == 'completed' ? 'status-completed' : 
                                   ($purchase['status'] == 'pending' ? 'status-pending' : 
                                   ($purchase['status'] == 'processing' ? 'status-processing' : 'status-cancelled'));
                ?>
                    <tr data-purchase-id="<?php echo $purchase['id']; ?>" class="purchase-row">
                        <td><?php echo htmlspecialchars($purchase['item_no'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($purchase['description'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="company-badge" style="background: <?php echo $purchase['company_color'] ?? '#6c5ce7'; ?>">
                                <?php echo htmlspecialchars($purchase['company_name'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($purchase['contact_person'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($purchase['quantity_purchased'] ?? 0); ?></td>
                        <td class="price-cell">₱<?php echo number_format($purchase['price_per_unit'] ?? 0, 2); ?></td>
                        <td class="price-cell">₱<?php echo number_format($purchase['total_amount'], 2); ?></td>
                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($purchase['status']); ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($purchase['purchase_date'])); ?></td>
                        <td class="actions-cell">
                            <button class="action-btn view" onclick="viewPurchase(<?php echo $purchase['id']; ?>)" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="action-btn edit" onclick="editPurchase(<?php echo $purchase['id']; ?>)" title="Edit Status">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn order-now" onclick="orderNowFromHistory(<?php echo $purchase['id']; ?>)" title="Order Now (Go to Stock Tracker)">
                                <i class="fas fa-shopping-cart"></i> Order Now
                            </button>
                            <button class="action-btn delete" onclick="deletePurchase(<?php echo $purchase['id']; ?>, '<?php echo htmlspecialchars($purchase['purchase_number']); ?>')" title="Delete Purchase">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 60px;">
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>No Pending Purchases Found</h3>
                            <p>No pending or processing purchases at the moment.</p>
                            <a href="canvas.php" class="btn btn-primary">
                                <i class="fas fa-shopping-cart"></i> Go to Canvas
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- No Results Message (for search) -->
<div id="noResultsMessage" class="no-results" style="display: none;">
    <i class="fas fa-search"></i>
    <h3>No Purchases Found</h3>
    <p>No purchases match your search criteria. Try different keywords.</p>
</div>

<!-- View Purchase Modal -->
<div id="viewPurchaseModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-eye"></i> Purchase Details</h2>
            <button class="close-btn" onclick="closeViewPurchaseModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewPurchaseContent">
            <!-- Content will be loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeViewPurchaseModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Purchase Modal -->
<div id="editPurchaseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Purchase Status</h2>
            <button class="close-btn" onclick="closeEditPurchaseModal()">&times;</button>
        </div>
        <div class="modal-body" id="editPurchaseContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Delete Purchase Modal -->
<div id="deletePurchaseModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-trash"></i> Delete Purchase</h2>
            <button class="close-btn" onclick="closeDeletePurchaseModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color: var(--text-primary); margin-bottom: 20px;">Are you sure you want to delete this purchase?</p>
            <p style="color: var(--text-secondary); margin-bottom: 10px;">Purchase: <strong id="delete_purchase_number"></strong></p>
            <p style="color: #d63031; font-size: 14px;"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone and will restore product stock.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeletePurchaseModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                <i class="fas fa-trash"></i> Delete Purchase
            </a>
        </div>
    </div>
</div>

<!-- Transaction History Modal -->
<div id="historyPurchaseModal" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header" style="background: linear-gradient(135deg, #3498db, #2980b9);">
            <h2><i class="fas fa-history"></i> All Transactions History</h2>
            <button class="close-btn" onclick="closeHistoryPurchaseModal()">&times;</button>
        </div>
        <div class="modal-body" id="historyPurchaseContent">
            <!-- Content will be loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeHistoryPurchaseModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
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
// ============ ORDER NOW FROM HISTORY FUNCTION - REDIRECTS TO STOCK TRACKER ============
function orderNowFromHistory(purchaseId) {
    if (!confirm('Place this order now? It will be COMPLETED and added to Stock Tracker.')) {
        return;
    }
    
    // Show loading on button
    const btn = event.currentTarget;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    fetch('process_reorder_now.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            purchase_id: purchaseId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the row from table (since it's now completed)
            const row = btn.closest('tr');
            if (row) {
                row.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    row.remove();
                    
                    // Check if table is empty
                    const tbody = document.getElementById('purchasesPageBody');
                    if (tbody.children.length === 0) {
                        location.reload(); // Reload to show empty state
                    }
                }, 300);
            }
            
            // Show success notification
            showNotification(`
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-check-circle" style="font-size: 24px;"></i>
                    <div>
                        <strong style="font-size: 16px;">✅ Order Completed!</strong><br>
                        <span style="font-size: 13px;">Purchase #: ${data.purchase_number}</span><br>
                        <span style="font-size: 13px;">Item: ${data.item_no} - ${data.description}</span><br>
                        <span style="font-size: 13px;">Quantity: ${data.quantity}</span><br>
                        <small style="color: #75e6da;">✓ Added to Stock Movements</small>
                    </div>
                </div>
            `, 'success');
            
            // REDIRECT TO STOCK TRACKER (ito ang gusto mong logic)
            setTimeout(() => {
                window.location.href = 'stock_tracker.php?date=' + data.date + '&purchased=success&movement=' + data.movement_id;
            }, 2000);
        } else {
            alert('Error: ' + data.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error processing order. Check console for details.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

// Search function
function searchPurchases() {
    const searchTerm = document.getElementById('purchaseSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#purchasesPageBody .purchase-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const cells = row.cells;
        let rowText = '';
        for(let i = 0; i < cells.length - 1; i++) {
            rowText += cells[i]?.textContent.toLowerCase() + ' ';
        }
        
        const matches = rowText.includes(searchTerm);
        
        if (searchTerm === '') {
            row.style.display = '';
            visibleCount++;
        } else {
            if (matches) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    const noResultsMsg = document.getElementById('noResultsMessage');
    if (visibleCount === 0 && searchTerm !== '') {
        noResultsMsg.style.display = 'block';
    } else {
        noResultsMsg.style.display = 'none';
    }
}

// Debounced search
let searchTimeout;
document.getElementById('purchaseSearch')?.addEventListener('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchPurchases, 300);
});

// View Purchase
function viewPurchase(purchaseId) {
    fetch('get_purchase_details.php?id=' + purchaseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const p = data.purchase;
                
                const statusClass = p.status === 'completed' ? 'status-completed' : 
                                   (p.status === 'pending' ? 'status-pending' : 
                                   (p.status === 'processing' ? 'status-processing' : 'status-cancelled'));
                
                const content = `
                    <div style="padding: 10px;">
                        <table class="detail-table">
                            <tr>
                                <td>Item No:</td>
                                <td><strong>${p.item_no}</strong></td>
                            </tr>
                            <tr>
                                <td>Description:</td>
                                <td>${p.description}</td>
                            </tr>
                            <tr>
                                <td>Company:</td>
                                <td>
                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 20px; background: ${p.company_color}; color: white;">
                                        ${p.company_name}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Contact Person:</td>
                                <td>${p.contact_person || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td>Quantity:</td>
                                <td>${p.quantity_purchased} pcs</td>
                            </tr>
                            <tr>
                                <td>Price/Unit:</td>
                                <td class="price-cell">${p.formatted_price}</td>
                            </tr>
                            <tr>
                                <td>Total:</td>
                                <td style="font-size: 18px; font-weight: 700; color: #75e6da;">${p.formatted_total}</td>
                            </tr>
                            <tr>
                                <td>Status:</td>
                                <td>
                                    <span class="status-badge ${statusClass}">
                                        ${p.status.charAt(0).toUpperCase() + p.status.slice(1)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Date:</td>
                                <td>${p.formatted_date}</td>
                            </tr>
                        </table>
                    </div>
                `;
                
                document.getElementById('viewPurchaseContent').innerHTML = content;
                document.getElementById('viewPurchaseModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading purchase details');
        });
}

function closeViewPurchaseModal() {
    const modal = document.getElementById('viewPurchaseModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// Edit Purchase
function editPurchase(purchaseId) {
    fetch('get_purchase_details.php?id=' + purchaseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const p = data.purchase;
                
                const statusClass = p.status === 'completed' ? 'status-completed' : 
                                   (p.status === 'pending' ? 'status-pending' : 
                                   (p.status === 'processing' ? 'status-processing' : 'status-cancelled'));
                
                const content = `
                    <div style="padding: 10px;">
                        <table class="detail-table">
                            <tr>
                                <td>Item No:</td>
                                <td><strong>${p.item_no}</strong></td>
                            </tr>
                            <tr>
                                <td>Description:</td>
                                <td>${p.description}</td>
                            </tr>
                            <tr>
                                <td>Company:</td>
                                <td>
                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 20px; background: ${p.company_color}; color: white;">
                                        ${p.company_name}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Contact Person:</td>
                                <td>${p.contact_person || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td>Quantity:</td>
                                <td>${p.quantity_purchased} pcs</td>
                            </tr>
                            <tr>
                                <td>Price/Unit:</td>
                                <td class="price-cell">${p.formatted_price}</td>
                            </tr>
                            <tr>
                                <td>Total:</td>
                                <td style="font-weight: 700; color: #75e6da;">${p.formatted_total}</td>
                            </tr>
                            <tr>
                                <td>Current Status:</td>
                                <td>
                                    <span class="status-badge ${statusClass}">
                                        ${p.status.charAt(0).toUpperCase() + p.status.slice(1)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Date:</td>
                                <td>${p.formatted_date}</td>
                            </tr>
                        </table>
                        
                        <form method="POST" action="" style="margin-top: 25px; padding-top: 20px; border-top: 2px dashed var(--border-color);">
                            <input type="hidden" name="purchase_id" value="${p.id}">
                            <input type="hidden" name="update_purchase" value="1">
                            
                            <div class="form-group">
                                <label><i class="fas fa-edit"></i> Update Status:</label>
                                <select name="status" class="form-control" required style="padding: 12px;">
                                    <option value="pending" ${p.status === 'pending' ? 'selected' : ''}>Pending</option>
                                    <option value="processing" ${p.status === 'processing' ? 'selected' : ''}>Processing</option>
                                    <option value="completed" ${p.status === 'completed' ? 'selected' : ''}>Completed</option>
                                    <option value="cancelled" ${p.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                                </select>
                            </div>
                            
                            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="closeEditPurchaseModal()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                            </div>
                        </form>
                    </div>
                `;
                
                document.getElementById('editPurchaseContent').innerHTML = content;
                document.getElementById('editPurchaseModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading purchase details');
        });
}

function closeEditPurchaseModal() {
    const modal = document.getElementById('editPurchaseModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// Delete Purchase
function deletePurchase(purchaseId, purchaseNumber) {
    document.getElementById('delete_purchase_number').textContent = purchaseNumber;
    document.getElementById('confirmDeleteBtn').href = 'purchase.php?delete=' + purchaseId;
    document.getElementById('deletePurchaseModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeDeletePurchaseModal() {
    const modal = document.getElementById('deletePurchaseModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// View All Transactions (Completed Only)
function viewAllTransactions() {
    fetch('get_purchase_history.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const history = data.history;
                const stats = data.stats;
                
                let historyRows = '';
                history.forEach((item, index) => {
                    historyRows += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.item_no}</td>
                            <td>${item.description}</td>
                            <td>
                                <span class="company-badge" style="background: ${item.company_color};">
                                    ${item.company_name}
                                </span>
                            </td>
                            <td>${item.contact_person}</td>
                            <td>${item.quantity_purchased}</td>
                            <td class="price-cell">${item.formatted_price}</td>
                            <td class="price-cell">${item.formatted_total}</td>
                            <td><span class="status-badge ${item.status_class}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                            <td>${item.formatted_date}</td>
                        </tr>
                    `;
                });
                
                const content = `
                    <div style="padding: 10px;">
                        <div class="history-stats">
                            <div class="history-stat-card">
                                <div class="stat-label">Total Transactions</div>
                                <div class="stat-value">${stats.total_transactions}</div>
                                <div class="stat-sub">purchases</div>
                            </div>
                            <div class="history-stat-card">
                                <div class="stat-label">Total Spent</div>
                                <div class="stat-value">₱${parseFloat(stats.total_spent).toFixed(2)}</div>
                                <div class="stat-sub">all time</div>
                            </div>
                            <div class="history-stat-card">
                                <div class="stat-label">Total Quantity</div>
                                <div class="stat-value">${stats.total_quantity}</div>
                                <div class="stat-sub">units</div>
                            </div>
                            <div class="history-stat-card">
                                <div class="stat-label">Unique Items</div>
                                <div class="stat-value">${stats.unique_items}</div>
                                <div class="stat-sub">different items</div>
                            </div>
                            <div class="history-stat-card">
                                <div class="stat-label">Companies</div>
                                <div class="stat-value">${stats.unique_companies}</div>
                                <div class="stat-sub">suppliers</div>
                            </div>
                        </div>
                        
                        <div class="summary-bar">
                            <div>
                                <span style="font-size: 14px; color: var(--text-secondary);">Showing:</span>
                                <span style="font-size: 18px; font-weight: 600; color: #3498db; margin-left: 10px;">${data.total_records} Completed Transactions</span>
                            </div>
                            <div>
                                <span class="history-badge">All Companies & Items</span>
                            </div>
                        </div>
                        
                        <div style="overflow-x: auto;">
                            <table class="products-table" style="min-width: 1300px;">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th>Item No</th>
                                        <th>Description</th>
                                        <th>Company</th>
                                        <th>Contact Person</th>
                                        <th>Qty</th>
                                        <th>Price/Unit</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${historyRows}
                                </tbody>
                            </table>
                        </div>
                        
                        ${history.length === 0 ? `
                            <div class="empty-state" style="padding: 40px;">
                                <i class="fas fa-history"></i>
                                <h3>No Transactions Found</h3>
                                <p>No completed transactions yet.</p>
                            </div>
                        ` : ''}
                    </div>
                `;
                
                document.getElementById('historyPurchaseContent').innerHTML = content;
                document.getElementById('historyPurchaseModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading transaction history');
        });
}

function closeHistoryPurchaseModal() {
    const modal = document.getElementById('historyPurchaseModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// Show notification function
function showNotification(message, type = 'success') {
    // Remove existing notification
    const existing = document.querySelector('.custom-notification');
    if (existing) existing.remove();
    
    const notification = document.createElement('div');
    notification.className = 'custom-notification';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        padding: 15px 25px;
        background: ${type === 'success' ? '#75e6da' : '#d63031'};
        color: ${type === 'success' ? '#1a1c3c' : 'white'};
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3);
        animation: slideInRight 0.3s ease;
        max-width: 400px;
    `;
    notification.innerHTML = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['viewPurchaseModal', 'editPurchaseModal', 'deletePurchaseModal', 'historyPurchaseModal'];
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

<?php 
$conn->close();
require_once 'include/footer.php'; 
?>