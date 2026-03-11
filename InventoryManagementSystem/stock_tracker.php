<?php
// stock_tracker.php - COMPLETE WITH PURCHASE INTEGRATION
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
        // Update product stock - BAWASAN AGAD
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
        $_SESSION['success'] = "Stock removed successfully! " . $quantity . " units deducted from inventory.";
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

// DEBUG: Check products with category
$debug_sql = "SELECT id, name, category FROM products WHERE category IS NOT NULL AND category != '' LIMIT 10";
$debug_result = $conn->query($debug_sql);
// Optional: Uncomment to see debug info in HTML source
// echo "<!-- DEBUG: Products with category: " . $debug_result->num_rows . " -->";

// Get stock movements for the selected date - WITH CATEGORY
$movements_sql = "SELECT 
                    sm.id,
                    sm.created_at,
                    sm.reference,
                    sm.quantity,
                    sm.type,
                    p.id as product_id,
                    p.name as product_name,
                    p.category,
                    COALESCE(p.item_no, 
                        SUBSTRING_INDEX(p.name, ' - ', 1),
                        'N/A'
                    ) as item_no,
                    COALESCE(p.description,
                        CASE 
                            WHEN p.name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(p.name, ' - ', -1))
                            ELSE p.name 
                        END,
                        'N/A'
                    ) as description,
                    COALESCE(p.unit, 'pcs') as unit
                  FROM stock_movements sm
                  LEFT JOIN products p ON sm.product_id = p.id
                  WHERE DATE(sm.created_at) = ?
                  ORDER BY sm.created_at DESC";
$stmt = $conn->prepare($movements_sql);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$daily_movements = $stmt->get_result();

// Get all stock out movements for history with optional date range - WITH CATEGORY
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

$all_stock_out_sql = "SELECT 
                        sm.id,
                        sm.created_at,
                        sm.reference,
                        sm.quantity,
                        sm.type,
                        p.id as product_id,
                        p.name as product_name,
                        p.category,
                        COALESCE(p.item_no, 
                            SUBSTRING_INDEX(p.name, ' - ', 1),
                            'N/A'
                        ) as item_no,
                        COALESCE(p.description,
                            CASE 
                                WHEN p.name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(p.name, ' - ', -1))
                                ELSE p.name 
                            END,
                            'N/A'
                        ) as description,
                        COALESCE(p.unit, 'pcs') as unit
                      FROM stock_movements sm
                      LEFT JOIN products p ON sm.product_id = p.id
                      WHERE sm.type = 'out'
                      AND DATE(sm.created_at) BETWEEN ? AND ?
                      ORDER BY sm.created_at DESC";

$stmt = $conn->prepare($all_stock_out_sql);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$all_stock_out = $stmt->get_result();

// Get current stock balances for all products - WITH CATEGORY
$balances_sql = "SELECT 
                    p.id,
                    p.name,
                    p.item_no,
                    p.description,
                    p.category,
                    p.quantity as current_balance,
                    p.unit,
                    p.low_stock_threshold
                 FROM products p
                 ORDER BY p.name";
$balances = $conn->query($balances_sql);

// Get statistics
$total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] ?? 0;
$total_stock_in = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'in'")->fetch_assoc()['total'] ?? 0;
$total_stock_out = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'out'")->fetch_assoc()['total'] ?? 0;
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < low_stock_threshold")->fetch_assoc()['count'] ?? 0;

require_once 'include/header.php';
?>

<style>
/* ========== STOCK TRACKER COMPLETE STYLES ========== */

/* Stock Tracker Container */
.stock-tracker-container {
    padding: 20px;
    max-width: 1600px;
    margin: 0 auto;
}

/* ========== STATS CARDS ========== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    transition: all 0.3s ease;
    animation: fadeInUp 0.5s ease;
    animation-fill-mode: both;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.15s; }
.stat-card:nth-child(3) { animation-delay: 0.2s; }
.stat-card:nth-child(4) { animation-delay: 0.25s; }

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(232, 67, 147, 0.2);
    border-color: #e84393;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    background: linear-gradient(135deg, #e40571, #d63031);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    transition: transform 0.3s ease;
}

.stat-card:hover .stat-icon {
    transform: scale(1.1) rotate(5deg);
}

.stat-details h3 {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 5px;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 28px;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 5px;
    line-height: 1.2;
}

.stat-value.warning {
    color: #f39c12;
}

.stat-trend {
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.stat-trend.positive {
    color: #00b894;
}

.stat-trend.negative {
    color: #d63031;
}

.stat-trend.warning {
    color: #f39c12;
}

/* ========== WELCOME SECTION ========== */
.welcome-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 20px;
}

.welcome-text h1 {
    font-size: 28px;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 5px;
    background: linear-gradient(135deg, #e84393, #d63031);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.welcome-text p {
    color: var(--text-secondary);
    font-size: 15px;
}

/* ========== ACTION BUTTONS ========== */
.action-buttons {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.btn-action {
    padding: 14px 28px;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    color: white;
}

.btn-stock-in {
    background: linear-gradient(135deg, #00b894, #75e6da);
    box-shadow: 0 4px 15px rgba(0, 184, 148, 0.3);
}

.btn-stock-out {
    background: linear-gradient(135deg, #e84393, #d63031);
    box-shadow: 0 4px 15px rgba(232, 67, 147, 0.3);
}

.btn-view-stock {
    background: linear-gradient(135deg, #3498db, #2980b9);
    box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
}

.btn-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(232, 67, 147, 0.4);
}

/* ========== DATE NAVIGATION ========== */
.date-navigation {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px 25px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.date-selector {
    display: flex;
    align-items: center;
    gap: 20px;
}

.date-nav-btn {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    width: 45px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 16px;
}

.date-nav-btn:hover {
    background: var(--hover-bg);
    border-color: #d14287;
    transform: translateY(-2px);
    color: #e84393;
}

.current-date {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    padding: 0 15px;
    background: linear-gradient(135deg, #ffffff, #ffffff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.date-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.date-picker {
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    padding: 10px 15px;
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.date-picker:focus {
    border-color: #00fc86;
    outline: none;
    box-shadow: 0 0 0 3px rgba(232, 67, 147, 0.15);
}

.btn-date {
    padding: 10px 20px;
    border-radius: 10px;
    border: none; 
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-today {
    background: linear-gradient(135deg, #07f7f7, #07f7f7);
    color: white;
    box-shadow: 0 4px 10px rgba(232, 67, 147, 0.3);
}

.btn-export {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 2px solid var(--border-color);
}

.btn-date:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(232, 67, 147, 0.3);
}

/* ========== TAB NAVIGATION ========== */
.tab-navigation {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 10px;
}

.tab-btn {
    padding: 12px 25px;
    border-radius: 10px 10px 0 0;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    position: relative;
}

.tab-btn:hover {
    color: var(--text-primary);
    background: var(--hover-bg);
}

.tab-btn.active {
    color: #e84393;
    background: var(--bg-secondary);
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -12px;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(135deg, #e84393, #d63031);
    border-radius: 3px 3px 0 0;
}

.tab-btn i {
    font-size: 16px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.5s ease;
}

/* ========== TRACKER CONTAINER ========== */
.tracker-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 30px;
    overflow-x: auto;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.tracker-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.tracker-header h3 {
    color: var(--text-primary);
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.tracker-header h3 i {
    color: #02e8f8;
    font-size: 22px;
}

.tracker-badge {
    background: linear-gradient(135deg, #0a0a0a, #000000);
    border-radius: 30px;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 10px rgba(232, 67, 147, 0.3);
}

/* ========== TABLES ========== */
.tracker-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.tracker-table th {
    background: linear-gradient(135deg, #2b3030, #2b3030);
    padding: 16px 15px;
    text-align: left;
    font-size: 14px;
    font-weight: 600;
    color: white;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}

.tracker-table th:first-child {
    border-radius: 10px 0 0 10px;
}

.tracker-table th:last-child {
    border-radius: 0 10px 10px 0;
}

.tracker-table td {
    padding: 15px;
    font-size: 14px;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.tracker-table tbody tr {
    transition: all 0.2s ease;
}

.tracker-table tbody tr:hover {
    background: var(--hover-bg);
    transform: translateX(5px);
    box-shadow: 0 2px 10px rgba(232, 67, 147, 0.1);
}

.tracker-table .stock-in {
    color: #00b894;
    font-weight: 700;
    font-size: 16px;
}

.tracker-table .stock-out {
    color: #d63031;
    font-weight: 700;
    font-size: 16px;
}

.tracker-table .stock-balance {
    color: #e84393;
    font-weight: 800;
    font-size: 16px;
}

/* Category Badge */
.category-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    white-space: nowrap;
    box-shadow: 0 2px 5px rgba(102, 126, 234, 0.3);
}

.category-badge i {
    font-size: 11px;
}

/* Reference Tags */
.reference-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

.reference-purchase {
    background: linear-gradient(135deg, #6c5ce7, #a463f5);
    color: white;
    border: none;
}

.reference-other {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
    border: none;
}

/* Type Badge */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.type-badge.stock-in {
    background: rgba(0, 184, 148, 0.15);
    color: #00b894;
    border: 1px solid #00b894;
}

.type-badge.stock-out {
    background: rgba(214, 48, 49, 0.15);
    color: #d63031;
    border: 1px solid #d63031;
}

.type-badge.warning {
    background: rgba(243, 156, 18, 0.15);
    color: #f39c12;
    border: 1px solid #f39c12;
}

/* ========== FOOTER STATS ========== */
.footer-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid var(--border-color);
    color: var(--text-secondary);
    font-size: 14px;
    flex-wrap: wrap;
    gap: 15px;
}

.footer-stats i {
    color: #e84393;
    margin-right: 8px;
}

/* ========== MODAL STYLES ========== */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(8px);
    animation: fadeIn 0.3s ease;
    overflow-y: auto;
    padding: 20px;
    box-sizing: border-box;
}

.modal-content {
    position: relative;
    background: var(--bg-primary);
    margin: 30px auto;
    border: none;
    border-radius: 24px;
    width: 100%;
    max-width: 700px;
    box-shadow: 0 30px 60px rgba(232, 67, 147, 0.3);
    animation: slideInUp 0.4s ease;
    overflow: hidden;
}

.modal-content.large-modal {
    max-width: 1400px;
    width: 95%;
}

/* Modal Header */
.modal-header {
    background: linear-gradient(135deg,  #75e6da, #75e6da);
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 24px 24px 0 0;
}

.modal-header.view-header {
    background: linear-gradient(135deg, #2b3030, #2b3030);
}

.modal-header h2 {
    color: white;
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modal-header h2 i {
    font-size: 24px;
}

.close-btn {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    transition: all 0.3s ease;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

/* Modal Body */
.modal-body {
    padding: 30px;
    max-height: calc(90vh - 180px);
    overflow-y: auto;
    background: var(--bg-primary);
    scrollbar-width: thin;
    scrollbar-color: #08e2ff var(--bg-secondary);
}

.modal-body::-webkit-scrollbar {
    width: 8px;
}

.modal-body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 10px;
}

.modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #e84393, #d63031);
    border-radius: 10px;
}

/* Form Sections */
.form-section {
    background: var(--bg-secondary);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.form-section:last-child {
    margin-bottom: 0;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--border-color);
}

.section-title i {
    color: #39ceb5;
    font-size: 18px;
    width: 30px;
    height: 30px;
    background: rgba(232, 67, 147, 0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.section-title h3 {
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}

/* Form Groups */
.form-group {
    margin-bottom: 20px;
    position: relative;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 14px;
}

.form-group label i {
    color: #f8017d;
    margin-right: 8px;
    width: 18px;
}

/* Form Controls */
.form-control {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 15px;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.form-control:focus {
    border-color: #e84393;
    outline: none;
    box-shadow: 0 0 0 4px rgba(232, 67, 147, 0.15);
}

.form-control:disabled {
    background: var(--bg-secondary);
    opacity: 0.7;
    cursor: not-allowed;
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Form Hint */
.form-hint {
    display: block;
    margin-top: 5px;
    font-size: 12px;
    color: var(--text-secondary);
}

/* Search Container */
.search-container {
    position: relative;
}

.search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 16px;
    z-index: 1;
}

.search-input {
    padding-left: 45px !important;
    height: 50px;
}

/* Search Results */
.search-results {
    position: absolute;
    background: var(--bg-primary);
    border: 2px solid #e84393;
    border-radius: 16px;
    max-height: 350px;
    overflow-y: auto;
    width: calc(100% - 40px);
    z-index: 10000;
    margin-top: 5px;
    box-shadow: 0 15px 40px rgba(232, 67, 147, 0.25);
    left: 20px;
    right: 20px;
    display: none;
}

.search-result-item {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: all 0.2s ease;
    background: var(--bg-primary);
}

.search-result-item:hover {
    background: linear-gradient(135deg, rgba(232, 67, 147, 0.15), rgba(214, 48, 49, 0.15));
    transform: translateX(5px);
}

.search-result-item:last-child {
    border-bottom: none;
}

/* Selected Product Section */
.selected-product-section {
    margin: 20px 0 25px;
    animation: slideIn 0.3s ease;
}

.selected-product-card {
    background: linear-gradient(135deg, #2a1b2e, #1e1a2e);
    border-radius: 20px;
    padding: 25px;
    border-left: 6px solid #e84393;
    box-shadow: 0 15px 30px rgba(232, 67, 147, 0.25);
    border: 1px solid rgba(232, 67, 147, 0.3);
}

.selected-product-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.selected-badge {
    background: #00ffc8;
    color: white;
    padding: 6px 15px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.category-badge {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 6px 15px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.selected-product-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin: 20px 0;
}

.detail-item {
    background: rgba(255, 255, 255, 0.05);
    padding: 15px;
    border-radius: 12px;
}

.detail-label {
    color: var(--text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.detail-value {
    color: white;
    font-size: 18px;
    font-weight: 700;
    word-break: break-word;
}

.detail-value.small {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

/* Stock Status */
.stock-status-large {
    text-align: center;
    padding: 15px;
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.2);
    min-width: 140px;
}

.stock-status-large.available {
    border: 2px solid #00b894;
}

.stock-status-large.unavailable {
    border: 2px solid #351f1f;
}

.status-text {
    font-size: 16px;
    font-weight: 700;
    margin-top: 5px;
}

.status-text.available {
    color: #00b894;
}

.status-text.unavailable {
    color: #d63031;
}

/* Stock Display */
.stock-display {
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.stock-value {
    font-size: 28px;
    font-weight: 800;
    color: #e84393;
    line-height: 1;
}

.stock-unit {
    font-size: 14px;
    color: var(--text-secondary);
    font-weight: 500;
}

/* Two Column Layout */
.two-column {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Warning Container */
.warning-container {
    margin: 20px 0;
    min-height: 50px;
}

.warning-message {
    background: rgba(214, 48, 49, 0.15);
    border: 2px solid #d63031;
    border-radius: 12px;
    padding: 15px 20px;
    color: #d63031;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: shake 0.5s ease;
}

.warning-message i {
    font-size: 24px;
}

.success-message {
    background: rgba(0, 184, 148, 0.15);
    border: 2px solid #00b894;
    border-radius: 12px;
    padding: 15px 20px;
    color: #00b894;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.success-message i {
    font-size: 24px;
}

/* Modal Footer */
.modal-footer {
    padding: 20px 30px;
    background: var(--bg-secondary);
    border-top: 2px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    border-radius: 0 0 24px 24px;
}

/* Buttons */
.btn {
    padding: 14px 28px;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-secondary {
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    color: var(--text-primary);
}

.btn-secondary:hover {
    background: var(--border-color);
    transform: translateY(-2px);
}

.btn-danger {
    background: linear-gradient(135deg, #d81b77, #d63031);
    color: white;
    box-shadow: 0 4px 15px rgba(232, 67, 147, 0.3);
}

.btn-danger:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(232, 67, 147, 0.4);
}

.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(52, 152, 219, 0.4);
}

.btn-filter {
    padding: 12px 24px;
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    height: 48px;
}

.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
}

/* View Modal Specific */
.date-range-picker {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.date-range-item {
    flex: 1;
    min-width: 200px;
}

.date-range-item label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 14px;
}

.date-range-item label i {
    color: #3498db;
    margin-right: 5px;
}

.date-range-item input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 14px;
}

.date-range-item input:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
}

.search-bar {
    margin-bottom: 20px;
    position: relative;
}

.search-bar i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 16px;
    z-index: 1;
}

.search-bar input {
    width: 100%;
    padding: 14px 16px 14px 45px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 15px;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.search-bar input:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.15);
}

/* View Table */
.view-table-container {
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-top: 20px;
}

.view-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1300px;
}

.view-table th {
    background: linear-gradient(135deg, #00e8f8, #00e8f8);
    padding: 15px;
    text-align: left;
    font-size: 14px;
    font-weight: 600;
    color: white;
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}

.view-table th:first-child {
    border-radius: 10px 0 0 10px;
}

.view-table th:last-child {
    border-radius: 0 10px 10px 0;
}

.view-table td {
    padding: 12px 15px;
    font-size: 14px;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
}

.view-table tbody tr:hover {
    background: var(--hover-bg);
}

.view-table .stock-out {
    color: #d63031;
    font-weight: 700;
    font-size: 16px;
}

.view-count {
    margin-top: 15px;
    text-align: right;
    color: var(--text-secondary);
    font-size: 13px;
}

.view-count span {
    color: #3498db;
    font-weight: 700;
}

/* Alerts */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    padding: 16px 24px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    animation: slideInRight 0.3s ease;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 400px;
}

.alert-success {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: #1a1c3c;
}

.alert-danger {
    background: linear-gradient(135deg, #ff0223, #d63031);
    color: white;
}

.alert i {
    font-size: 20px;
}

/* Text Utilities */
.text-muted {
    color: var(--text-secondary);
    font-style: italic;
}

/* ========== ANIMATIONS ========== */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
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

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideInUp {
    from {
        transform: translateY(50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(232, 67, 147, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(232, 67, 147, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(232, 67, 147, 0);
    }
}

/* ========== RESPONSIVE DESIGN ========== */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stock-tracker-container {
        padding: 15px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .welcome-section {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        width: 100%;
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
        justify-content: center;
    }
    
    .date-navigation {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-selector {
        justify-content: center;
    }
    
    .date-actions {
        flex-direction: column;
    }
    
    .tab-navigation {
        flex-direction: column;
        gap: 5px;
    }
    
    .tab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .tab-btn.active::after {
        display: none;
    }
    
    .modal-content {
        margin: 15px;
        width: auto;
    }
    
    .modal-body {
        padding: 20px;
    }
    
    .two-column {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .selected-product-details {
        grid-template-columns: 1fr;
    }
    
    .selected-product-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .date-range-picker {
        flex-direction: column;
    }
    
    .btn-filter {
        width: 100%;
        justify-content: center;
    }
    
    .modal-footer {
        flex-direction: column-reverse;
        gap: 10px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .stat-value {
        font-size: 22px;
    }
    
    .current-date {
        font-size: 16px;
    }
    
    .tracker-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .tracker-badge {
        align-self: flex-start;
    }
    
    .selected-product-card {
        padding: 20px;
    }
}
</style>





<div class="stock-tracker-container">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-text">
            <h1>Stock In - Out - Balance Tracker</h1>
            <p>Monitor daily inventory movements from purchases and manual entries</p>
        </div>
        <div class="action-buttons">
            <button class="btn-action btn-stock-out" onclick="openStockOutModal()">
                <i class="fas fa-minus-circle"></i> Add Stock Out
            </button>
            <button class="btn-action btn-view-stock" onclick="openViewStockOutModal()">
                <i class="fas fa-history"></i> View Stock Out
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

    <!-- Daily Tracker Tab - WITH CATEGORY COLUMN -->
    <div id="dailyTab" class="tab-content active">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3>
                    <i class="fas fa-clipboard-list"></i>
                    Stock Movements for <?php echo date('M d, Y', strtotime($selected_date)); ?>
                </h3>
                <span class="tracker-badge">
                    <i class="fas fa-box"></i> <?php echo ($daily_movements) ? $daily_movements->num_rows : 0; ?> items
                </span>
            </div>
            
            <table class="tracker-table">
                <thead>
                    <tr>
                        <th>Item No</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Date & Time</th>
                        <th>Quantity</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
<?php if ($daily_movements && $daily_movements->num_rows > 0): ?>
    <?php 
    $daily_movements->data_seek(0);
    while($movement = $daily_movements->fetch_assoc()): 
    ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong></td>
        <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?></td>
        
        <!-- CATEGORY CELL -->
        <td>
            <?php 
            $category = isset($movement['category']) ? trim($movement['category']) : '';
            if (!empty($category)): 
            ?>
                <span class="category-badge">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                </span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        
        <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?></td>
        <td><?php echo date('M d, Y h:i A', strtotime($movement['created_at'])); ?></td>
        <td class="<?php echo $movement['type'] == 'in' ? 'stock-in' : 'stock-out'; ?>">
            <?php echo $movement['type'] == 'in' ? '+' : '-'; ?>
            <?php echo number_format($movement['quantity']); ?>
        </td>
        <td>
            <span class="reference-tag <?php echo strpos($movement['reference'], 'PURCHASE') !== false ? 'reference-purchase' : 'reference-other'; ?>">
                <i class="fas fa-tag"></i> 
                <?php echo htmlspecialchars($movement['reference'] ?: 'N/A'); ?>
            </span>
        </td>
    </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr>
        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary);">
            <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
            No stock movements found for this date: <?php echo date('M d, Y', strtotime($selected_date)); ?>
        </td>
    </tr>
<?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer-stats">
                <div>
                    <i class="fas fa-boxes" style="color: #75e6da;"></i>
                    Total Items: <strong style="color: #75e6da;"><?php echo ($daily_movements) ? $daily_movements->num_rows : 0; ?></strong>
                </div>
                <div>
                    <i class="fas fa-calendar" style="color: #6c5ce7;"></i>
                    Date: <strong style="color: #6c5ce7;"><?php echo date('F d, Y', strtotime($selected_date)); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Balances Tab - WITH CATEGORY COLUMN -->
    <div id="balancesTab" class="tab-content">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3>
                    <i class="fas fa-boxes"></i>
                    Current Stock Balances
                </h3>
                <span class="tracker-badge">
                    <i class="fas fa-box"></i> <?php echo ($balances) ? $balances->num_rows : 0; ?> products
                </span>
            </div>
            
            <table class="tracker-table">
                <thead>
                    <tr>
                        <th>Item No</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Current Balance</th>
                        <th>Status</th>
                        <th>Last Movement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($balances && $balances->num_rows > 0): ?>
                        <?php 
                        $balances->data_seek(0);
                        while($product = $balances->fetch_assoc()): 
                            $balance = isset($product['current_balance']) ? $product['current_balance'] : 0;
                            $threshold = isset($product['low_stock_threshold']) ? $product['low_stock_threshold'] : 10;
                            
                            // Get last movement
                            $last_mov_sql = "SELECT reference, created_at FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 1";
                            $last_stmt = $conn->prepare($last_mov_sql);
                            $last_stmt->bind_param("i", $product['id']);
                            $last_stmt->execute();
                            $last_result = $last_stmt->get_result();
                            $last_mov = $last_result->fetch_assoc();
                            
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
                            
                            $item_no_display = isset($product['item_no']) ? $product['item_no'] : '';
                            $description_display = isset($product['description']) ? $product['description'] : (isset($product['name']) ? $product['name'] : '');
                            
                            // If no item_no, try to extract from name
                            if (empty($item_no_display) && isset($product['name']) && strpos($product['name'], ' - ') !== false) {
                                $parts = explode(' - ', $product['name'], 2);
                                $item_no_display = $parts[0];
                                $description_display = $parts[1];
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item_no_display ?: 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($description_display ?: 'N/A'); ?></td>
                            
                            <!-- CATEGORY CELL -->
                            <td>
                                <?php 
                                $category = isset($product['category']) ? trim($product['category']) : '';
                                if (!empty($category)): 
                                ?>
                                    <span class="category-badge">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            
                            <td><?php echo isset($product['unit']) ? htmlspecialchars($product['unit']) : 'pcs'; ?></td>
                            <td class="stock-balance"><?php echo number_format($balance); ?></td>
                            <td><span class="type-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td>
                                <?php if ($last_mov): ?>
                                    <span style="font-size: 11px; color: var(--text-secondary);">
                                        <?php echo isset($last_mov['reference']) ? htmlspecialchars($last_mov['reference']) : 'N/A'; ?><br>
                                        <small><?php echo isset($last_mov['created_at']) ? date('M d, Y H:i', strtotime($last_mov['created_at'])) : 'N/A'; ?></small>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 11px;">No movements</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary);">
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

<!-- STOCK OUT MODAL - RECORD STOCK OUT -->
<div id="stockOutModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-minus-circle"></i> Record Stock Out</h2>
            <button class="close-btn" onclick="closeStockOutModal()">&times;</button>
        </div>
        
        <form method="POST" action="" onsubmit="return validateStockOut()">
            <div class="modal-body" style="max-height: calc(90vh - 180px);">
                <input type="hidden" name="add_stock_out" value="1">
                <input type="hidden" name="product_id" id="selectedProductId">
                
                <!-- UPPER SECTION - Item Search -->
                <div class="upper-section">
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Item Number / Product Name</label>
                        <input type="text" id="itemSearchInput" class="form-control" placeholder="Type item number or product name..." onkeyup="searchItems()" autocomplete="off" required>
                        <div id="searchResults"></div>
                    </div>
                    
                    <div id="selectedProductInfo" style="display: none; margin-bottom: 15px;">
                        <div style="background: rgba(232, 67, 147, 0.08); padding: 10px 12px; border-radius: 8px; border-left: 4px solid #e84393;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1;">
                                    <span style="font-size: 10px; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Selected:</span>
                                    <h4 id="selectedProductName" style="margin: 2px 0 0; font-size: 14px; font-weight: 600;"></h4>
                                    <p id="selectedProductDetails" style="margin: 2px 0 0; font-size: 11px; color: var(--text-secondary);"></p>
                                </div>
                                <span class="availability-badge available" id="selectedStockBadge" style="padding: 3px 10px; font-size: 11px;">In Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr style="margin: 10px 0 15px; border: 1px dashed var(--border-color); opacity: 0.5;">

                <!-- LOWER SECTION -->
                <div class="lower-section">
                    <div class="two-column">
                        <div class="form-group">
                            <label><i class="fas fa-cubes"></i> Quantity</label>
                            <input type="number" name="quantity" id="stockOutQuantity" min="1" required placeholder="Enter quantity" onkeyup="updateAvailableStock()" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-boxes"></i> Available Stock</label>
                            <input type="text" id="availableStock" readonly disabled class="form-control" value="0">
                        </div>
                    </div>

                    <div id="quantityWarningContainer"></div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="e.g., SO-2024-001">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea name="notes" class="form-control" rows="4" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStockOutModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-danger" id="submitStockOutBtn"><i class="fas fa-save"></i> Record Stock Out</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW STOCK OUT MODAL - WITH CATEGORY COLUMN -->
<div id="viewStockOutModal" class="modal">
    <div class="modal-content large-modal">
        <div class="modal-header view-header">
            <h2><i class="fas fa-history"></i> Stock Out History</h2>
            <button class="close-btn" onclick="closeViewStockOutModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <!-- Date Range Picker -->
            <div class="date-range-picker">
                <div class="date-range-item">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <input type="date" id="dateFrom" value="<?php echo $date_from; ?>">
                </div>
                <div class="date-range-item">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <input type="date" id="dateTo" value="<?php echo $date_to; ?>">
                </div>
                <button class="btn-filter" onclick="filterByDateRange()"><i class="fas fa-filter"></i> Apply Filter</button>
                <button class="btn-filter" style="background: linear-gradient(135deg, #95a5a6, #7f8c8d);" onclick="resetDateRange()"><i class="fas fa-undo"></i> Reset</button>
            </div>

            <!-- Search Bar -->
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="stockOutSearch" placeholder="Search by Item No, Description, Category, Reference..." onkeyup="searchStockOut()">
            </div>
            
            <!-- Table Container -->
            <div class="view-table-container">
                <table class="view-table" id="stockOutTable">
                    <thead>
                        <tr>
                            <th>Item No</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Date & Time</th>
                            <th>Quantity</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody id="stockOutTableBody">
                        <?php if ($all_stock_out && $all_stock_out->num_rows > 0): ?>
                            <?php 
                            $all_stock_out->data_seek(0);
                            while($movement = $all_stock_out->fetch_assoc()): 
                            ?>
                            <tr class="stock-out-row">
                                <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?></td>
                                
                                <!-- CATEGORY CELL -->
                                <td>
                                    <?php 
                                    $category = isset($movement['category']) ? trim($movement['category']) : '';
                                    if (!empty($category)): 
                                    ?>
                                        <span class="category-badge">
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($movement['created_at'])); ?></td>
                                <td class="stock-out">-<?php echo number_format($movement['quantity']); ?></td>
                                <td>
                                    <span class="reference-tag reference-other">
                                        <i class="fas fa-tag"></i> 
                                        <?php echo htmlspecialchars($movement['reference'] ?: 'N/A'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                    <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                    No stock out records found for the selected date range.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="view-count">
                Total Records: <span id="stockOutCount"><?php echo $all_stock_out ? $all_stock_out->num_rows : 0; ?></span>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewStockOutModal()"><i class="fas fa-times"></i> Close</button>
            <button type="button" class="btn btn-primary" onclick="exportStockOutData()"><i class="fas fa-download"></i> Export</button>
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
// Search items function for Record Stock Out
function searchItems() {
    const searchTerm = document.getElementById('itemSearchInput').value.trim();
    const resultsDiv = document.getElementById('searchResults');
    
    if (searchTerm.length < 1) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center;">Searching...</div>';
    resultsDiv.style.display = 'block';
    
    fetch('search_products.php?term=' + encodeURIComponent(searchTerm))
        .then(response => response.json())
        .then(data => {
            if (data.length > 0) {
                let html = '';
                data.forEach(product => {
                    const stockValue = product.quantity || 0;
                    const stockStatus = stockValue > 0 ? 'available' : 'unavailable';
                    const stockText = stockValue > 0 ? 'Stock: ' + stockValue : 'Out of Stock';
                    
                    html += `
                        <div onclick="selectProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.item_no || ''}', ${stockValue}, '${product.unit || 'pcs'}')">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${product.item_no ? product.item_no + ' - ' : ''}${product.name}</strong>
                                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 3px;">
                                        ${product.description || ''} | 
                                        Unit: ${product.unit || 'pcs'} | 
                                        Category: <span style="color: #667eea;">${product.category || 'N/A'}</span>
                                    </div>
                                </div>
                                <span class="availability-badge ${stockStatus}" style="font-size: 12px; padding: 5px 12px;">
                                    ${stockText}
                                </span>
                            </div>
                        </div>
                    `;
                });
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-secondary);">No products found</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #d63031;">Error loading products</div>';
        });
}

// Select product from search results
function selectProduct(id, name, itemNo, stock, unit) {
    let availableStock = 0;
    if (stock !== undefined && stock !== null) {
        availableStock = parseInt(stock);
        if (isNaN(availableStock)) availableStock = 0;
    }
    
    document.getElementById('selectedProductId').value = id;
    document.getElementById('itemSearchInput').value = itemNo ? itemNo + ' - ' + name : name;
    document.getElementById('selectedProductName').textContent = name;
    document.getElementById('selectedProductDetails').textContent = `Item No: ${itemNo || 'N/A'} | Unit: ${unit}`;
    document.getElementById('availableStock').value = availableStock;
    
    const badge = document.getElementById('selectedStockBadge');
    if (availableStock > 0) {
        badge.className = 'availability-badge available';
        badge.innerHTML = '<i class="fas fa-check-circle"></i> In Stock: ' + availableStock;
    } else {
        badge.className = 'availability-badge unavailable';
        badge.innerHTML = '<i class="fas fa-times-circle"></i> Out of Stock';
    }
    
    document.getElementById('selectedProductInfo').style.display = 'block';
    document.getElementById('searchResults').style.display = 'none';
    
    const quantityInput = document.getElementById('stockOutQuantity');
    quantityInput.value = '';
    quantityInput.style.borderColor = '';
    quantityInput.style.boxShadow = '';
    document.getElementById('quantityWarningContainer').innerHTML = '';
    
    document.getElementById('submitStockOutBtn').disabled = true;
    quantityInput.focus();
}

// Update available stock with validation
function updateAvailableStock() {
    const quantity = parseInt(document.getElementById('stockOutQuantity').value) || 0;
    const available = parseInt(document.getElementById('availableStock').value) || 0;
    const quantityInput = document.getElementById('stockOutQuantity');
    const submitBtn = document.getElementById('submitStockOutBtn');
    const container = document.getElementById('quantityWarningContainer');
    
    container.innerHTML = '';
    
    if (quantity > available) {
        quantityInput.style.borderColor = '#d63031';
        quantityInput.style.boxShadow = '0 0 0 4px rgba(214, 48, 49, 0.15)';
        
        container.innerHTML = `
            <div id="quantityWarning">
                <i class="fas fa-exclamation-circle"></i> ERROR: Insufficient stock! Available: ${available}
            </div>
        `;
        
        submitBtn.disabled = true;
        
    } else if (quantity > 0 && quantity <= available) {
        quantityInput.style.borderColor = '#00b894';
        quantityInput.style.boxShadow = '0 0 0 4px rgba(0, 184, 148, 0.15)';
        submitBtn.disabled = false;
    } else {
        quantityInput.style.borderColor = '';
        quantityInput.style.boxShadow = '';
        submitBtn.disabled = true;
    }
}

// Validate before submit
function validateStockOut() {
    const productId = document.getElementById('selectedProductId').value;
    const quantity = parseInt(document.getElementById('stockOutQuantity').value);
    const available = parseInt(document.getElementById('availableStock').value);
    
    if (!productId) {
        alert('Please select a product first');
        return false;
    }
    
    if (isNaN(quantity) || quantity <= 0) {
        alert('Please enter a valid quantity');
        return false;
    }
    
    if (quantity > available) {
        alert('Error: Insufficient stock! Available: ' + available);
        return false;
    }
    
    return true;
}

// Filter by date range
function filterByDateRange() {
    const fromDate = document.getElementById('dateFrom').value;
    const toDate = document.getElementById('dateTo').value;
    
    if (!fromDate || !toDate) {
        alert('Please select both from and to dates');
        return;
    }
    
    window.location.href = 'stock_tracker.php?from=' + fromDate + '&to=' + toDate + '#viewStockOutModal';
    setTimeout(() => {
        openViewStockOutModal();
    }, 100);
}

// Reset date range
function resetDateRange() {
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    const fromDate = thirtyDaysAgo.toISOString().split('T')[0];
    const toDate = today.toISOString().split('T')[0];
    
    window.location.href = 'stock_tracker.php?from=' + fromDate + '&to=' + toDate + '#viewStockOutModal';
    setTimeout(() => {
        openViewStockOutModal();
    }, 100);
}

// Search function for Stock Out History
function searchStockOut() {
    const searchTerm = document.getElementById('stockOutSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#stockOutTableBody .stock-out-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const cells = row.cells;
        let rowText = '';
        
        for(let i = 0; i < cells.length; i++) {
            rowText += cells[i]?.textContent.toLowerCase() + ' ';
        }
        
        if (searchTerm === '') {
            row.style.display = '';
            visibleCount++;
        } else {
            if (rowText.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    document.getElementById('stockOutCount').textContent = visibleCount;
}

// Close modals
function closeStockOutModal() {
    document.getElementById('stockOutModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function closeViewStockOutModal() {
    document.getElementById('viewStockOutModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

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
    window.location.href = 'stock_tracker.php?date=' + currentDate.toISOString().split('T')[0];
}

function goToDate() {
    window.location.href = 'stock_tracker.php?date=' + document.getElementById('datePicker').value;
}

function goToToday() {
    window.location.href = 'stock_tracker.php?date=' + new Date().toISOString().split('T')[0];
}

// Open modals
function openStockOutModal() {
    document.getElementById('stockOutModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('itemSearchInput').focus(), 100);
}

function openViewStockOutModal() {
    document.getElementById('viewStockOutModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('stockOutSearch').focus(), 100);
}

// Export functions
function exportDailyData() {
    const date = document.getElementById('datePicker').value;
    const rows = document.querySelectorAll('#dailyTab .tracker-table tbody tr');
    let csv = 'Date,Item No,Description,Category,Unit,Quantity,Reference\n';
    
    rows.forEach(row => {
        if (row.style.display !== 'none' && row.cells.length > 1) {
            const cells = row.querySelectorAll('td');
            csv += `"${document.getElementById('currentDateDisplay').textContent}","${cells[0]?.textContent.trim() || ''}","${cells[1]?.textContent.trim() || ''}","${cells[2]?.textContent.trim() || ''}","${cells[3]?.textContent.trim() || ''}","${cells[5]?.textContent.trim() || ''}","${cells[6]?.textContent.trim() || ''}"\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'stock_tracker_' + date + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    alert('Daily data exported successfully!');
}

function exportStockOutData() {
    const rows = document.querySelectorAll('#stockOutTableBody .stock-out-row');
    let csv = 'Item No,Description,Category,Unit,Date & Time,Quantity,Reference\n';
    
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const cells = row.querySelectorAll('td');
            csv += `"${cells[0]?.textContent.trim() || ''}","${cells[1]?.textContent.trim() || ''}","${cells[2]?.textContent.trim() || ''}","${cells[3]?.textContent.trim() || ''}","${cells[4]?.textContent.trim() || ''}","${cells[5]?.textContent.trim() || ''}","${cells[6]?.textContent.trim() || ''}"\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'stock_out_history_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    alert('Stock Out data exported successfully!');
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target == document.getElementById('stockOutModal')) {
        closeStockOutModal();
    }
    if (event.target == document.getElementById('viewStockOutModal')) {
        closeViewStockOutModal();
    }
};

// Auto-hide alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.style.display = 'none', 300);
    });
}, 3000);

// Check if URL has hash to open modal
if (window.location.hash === '#viewStockOutModal') {
    setTimeout(() => {
        openViewStockOutModal();
    }, 100);
}
</script>

<?php require_once 'include/footer.php'; ?>