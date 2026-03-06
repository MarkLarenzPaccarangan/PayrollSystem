<?php
// purchase.php - Purchase Management
ob_start();
require_once 'config.php';
requireLogin();

// Get current user
$current_user = getCurrentUser();

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
            $update_stock = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
            $stock_stmt = $conn->prepare($update_stock);
            $stock_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            $stock_stmt->execute();
            $stock_stmt->close();
            
            // Record stock movement (stock return)
            $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) 
                             VALUES (?, 'in', ?, ?, ?, ?)";
            $movement_stmt = $conn->prepare($movement_sql);
            $reference = "PURCHASE-RETURN-" . $purchase_id;
            $notes = "Stock returned from cancelled purchase #" . $purchase_id;
            $movement_stmt->bind_param("iissi", $item['product_id'], $item['quantity'], $reference, $notes, $current_user['id']);
            $movement_stmt->execute();
            $movement_stmt->close();
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

// Get purchases with customer details - UPDATED to use 'purchases' table
$purchases = $conn->query("SELECT * FROM purchases ORDER BY purchase_date DESC LIMIT 50");

// Get purchase statistics - UPDATED to use 'purchases' table
$totalPurchases = $conn->query("SELECT COUNT(*) as count FROM purchases")->fetch_assoc()['count'] ?? 0;
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

/* Payment badge */
.payment-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    background: rgba(117, 230, 218, 0.1);
    color: #75e6da;
    border: 1px solid rgba(117, 230, 218, 0.3);
    margin-left: 5px;
}

/* Price cell */
.price-cell {
    font-weight: 600;
    color: #75e6da;
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
    max-width: 800px;
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

/* Purchase Details Styles */
.purchase-detail-row {
    display: flex;
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
}

.purchase-detail-label {
    width: 120px;
    font-weight: 600;
    color: var(--text-secondary);
}

.purchase-detail-value {
    flex: 1;
    color: var(--text-primary);
}

.purchase-items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.purchase-items-table th {
    background: var(--bg-secondary);
    padding: 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
}

.purchase-items-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.total-amount {
    font-size: 18px;
    font-weight: 700;
    color: #75e6da;
    text-align: right;
    padding: 15px;
    background: var(--bg-secondary);
    border-radius: 8px;
    margin-top: 15px;
}

/* Form Styles */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-weight: 500;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
    outline: none;
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
    from { opacity: 1; }
    to { opacity: 0; }
}
</style>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Purchase Management</h1>
        <p>Track and manage purchase orders</p>
    </div>
    <button class="btn btn-primary" onclick="openNewPurchaseModal()">
        <i class="fas fa-plus"></i>
        New Purchase
    </button>
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

<!-- Top Bar with Search -->
<div class="top-bar" style="justify-content: flex-start; margin-bottom: 20px;">
    <div class="search-wrapper" style="width: 300px;">
        <i class="fas fa-search"></i>
        <input type="text" id="purchaseSearch" placeholder="Search purchases..." onkeyup="searchPurchases()">
    </div>
</div>

<!-- Scrollable Table Wrapper -->
<div class="table-wrapper" style="max-height: 400px; overflow-y: auto; overflow-x: auto;">
    <table class="products-table" id="purchasesTable">
        <thead style="position: sticky; top: 0; background: var(--bg-secondary); z-index: 10;">
            <tr>
                <th>Purchase #</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Total</th>
                <th>Payment</th>
                <th>Status</th>
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
                        <td><strong style="color: var(--text-primary);"><?php echo htmlspecialchars($purchase['purchase_number']); ?></strong></td>
                        <td style="color: var(--text-primary);"><?php echo htmlspecialchars($purchase['customer_name']); ?></td>
                        <td style="color: var(--text-primary);"><?php echo date('M d, Y', strtotime($purchase['purchase_date'])); ?></td>
                        <td class="price-cell">₱<?php echo number_format($purchase['total_amount'], 2); ?></td>
                        <td>
                            <span class="payment-badge">
                                <i class="fas fa-money-bill-wave"></i> 
                                <?php echo ucfirst($purchase['payment_method'] ?? 'cash'); ?>
                                (<?php echo ucfirst($purchase['payment_status'] ?? 'unpaid'); ?>)
                            </span>
                        </td>
                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($purchase['status']); ?></span></td>
                        <td class="actions-cell">
                            <button class="action-btn view" onclick="viewPurchase(<?php echo $purchase['id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                            <button class="action-btn edit" onclick="editPurchase(<?php echo $purchase['id']; ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="action-btn delete" onclick="deletePurchase(<?php echo $purchase['id']; ?>, '<?php echo htmlspecialchars($purchase['purchase_number']); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary);">No purchases found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- No Results Message -->
<div id="noResultsMessage" style="display: none; text-align: center; padding: 40px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; margin-top: 20px;">
    <i class="fas fa-search" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 15px;"></i>
    <h3 style="color: var(--text-primary); margin-bottom: 10px;">No Purchases Found</h3>
    <p style="color: var(--text-secondary);">No purchases match your search criteria. Try different keywords.</p>
</div>

<!-- New Purchase Modal -->
<div id="newPurchaseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Create New Purchase</h2>
            <button class="close-btn" onclick="closeNewPurchaseModal()">&times;</button>
        </div>
        <form method="POST" action="new_purchase.php">
            <div class="modal-body">
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" required placeholder="Enter customer name">
                </div>
                <div class="form-group">
                    <label>Customer Email</label>
                    <input type="email" name="customer_email" placeholder="customer@example.com">
                </div>
                <div class="form-group">
                    <label>Customer Phone</label>
                    <input type="text" name="customer_phone" placeholder="Enter phone number">
                </div>
                <div class="form-group">
                    <label>Total Amount (₱)</label>
                    <input type="number" name="total_amount" step="0.01" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" required>
                        <option value="cash">Cash</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="gcash">GCash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Status</label>
                    <select name="payment_status" required>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewPurchaseModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Purchase
                </button>
            </div>
        </form>
    </div>
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
    </div>
</div>

<!-- Edit Purchase Modal -->
<div id="editPurchaseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Purchase Status</h2>
            <button class="close-btn" onclick="closeEditPurchaseModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="purchase_id" id="edit_purchase_id">
                <input type="hidden" name="update_purchase" value="1">
                
                <div class="form-group">
                    <label>Purchase Number</label>
                    <input type="text" id="edit_purchase_number" readonly disabled class="bg-opacity-50">
                </div>
                
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" id="edit_customer_name" readonly disabled class="bg-opacity-50">
                </div>
                
                <div class="form-group">
                    <label>Purchase Status</label>
                    <select name="status" id="edit_status" required>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditPurchaseModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Purchase
                </button>
            </div>
        </form>
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
// Search function
function searchPurchases() {
    const searchTerm = document.getElementById('purchaseSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#purchasesPageBody .purchase-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const purchaseNumber = row.cells[0]?.textContent.toLowerCase() || '';
        const customer = row.cells[1]?.textContent.toLowerCase() || '';
        const date = row.cells[2]?.textContent.toLowerCase() || '';
        const total = row.cells[3]?.textContent.toLowerCase() || '';
        const payment = row.cells[4]?.textContent.toLowerCase() || '';
        const status = row.cells[5]?.textContent.toLowerCase() || '';
        
        const matches = purchaseNumber.includes(searchTerm) || 
                       customer.includes(searchTerm) || 
                       date.includes(searchTerm) || 
                       total.includes(searchTerm) || 
                       payment.includes(searchTerm) || 
                       status.includes(searchTerm);
        
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
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchPurchases, 100);
}

// Modal functions
function openNewPurchaseModal() {
    document.getElementById('newPurchaseModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeNewPurchaseModal() {
    const modal = document.getElementById('newPurchaseModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        document.body.style.overflow = 'auto';
    }, 200);
}

// View Purchase
function viewPurchase(purchaseId) {
    fetch('get_purchase_details.php?id=' + purchaseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const purchase = data.purchase;
                const items = data.items;
                
                let itemsHtml = '';
                items.forEach(item => {
                    itemsHtml += `
                        <tr>
                            <td>${item.product_name}</td>
                            <td>₱${parseFloat(item.price).toFixed(2)}</td>
                            <td>${item.quantity}</td>
                            <td>₱${(item.price * item.quantity).toFixed(2)}</td>
                        </tr>
                    `;
                });
                
                const statusClass = purchase.status === 'completed' ? 'status-completed' : 
                                   (purchase.status === 'pending' ? 'status-pending' : 
                                   (purchase.status === 'processing' ? 'status-processing' : 'status-cancelled'));
                
                const content = `
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Purchase #:</div>
                        <div class="purchase-detail-value">${purchase.purchase_number}</div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Customer:</div>
                        <div class="purchase-detail-value">${purchase.customer_name}</div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Email:</div>
                        <div class="purchase-detail-value">${purchase.customer_email || 'N/A'}</div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Phone:</div>
                        <div class="purchase-detail-value">${purchase.customer_phone || 'N/A'}</div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Payment Method:</div>
                        <div class="purchase-detail-value">
                            <span class="payment-badge">
                                <i class="fas fa-money-bill-wave"></i> 
                                ${purchase.payment_method || 'cash'} (${purchase.payment_status || 'unpaid'})
                            </span>
                        </div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Purchase Date:</div>
                        <div class="purchase-detail-value">${new Date(purchase.purchase_date).toLocaleString()}</div>
                    </div>
                    <div class="purchase-detail-row">
                        <div class="purchase-detail-label">Status:</div>
                        <div class="purchase-detail-value"><span class="status-badge ${statusClass}">${purchase.status.charAt(0).toUpperCase() + purchase.status.slice(1)}</span></div>
                    </div>
                    
                    <h3 style="color: var(--text-primary); margin: 20px 0 10px;">Purchase Items</h3>
                    <table class="purchase-items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                    
                    <div class="total-amount">
                        Total Amount: ₱${parseFloat(purchase.total_amount).toFixed(2)}
                    </div>
                `;
                
                document.getElementById('viewPurchaseContent').innerHTML = content;
                document.getElementById('viewPurchaseModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error loading purchase details');
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
                const purchase = data.purchase;
                document.getElementById('edit_purchase_id').value = purchase.id;
                document.getElementById('edit_purchase_number').value = purchase.purchase_number;
                document.getElementById('edit_customer_name').value = purchase.customer_name;
                document.getElementById('edit_status').value = purchase.status;
                document.getElementById('editPurchaseModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error loading purchase details');
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

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['newPurchaseModal', 'viewPurchaseModal', 'editPurchaseModal', 'deletePurchaseModal'];
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
}

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

// Initialize search listener
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('purchaseSearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', debouncedSearch);
    }
});
</script>

<?php require_once 'include/footer.php'; ?>