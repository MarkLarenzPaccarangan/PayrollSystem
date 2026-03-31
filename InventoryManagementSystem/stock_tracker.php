<?php
// stock_tracker.php - COMPLETE WITH PURCHASE INTEGRATION AND ENHANCED DATE PICKER
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

// ========== GET SITES FOR QUICK SELECT DROPDOWN ==========
$sites_for_dropdown = [];
$sites_sql = "SELECT site_name FROM sites ORDER BY site_name";
$sites_result = $conn->query($sites_sql);
if ($sites_result) {
    while ($row = $sites_result->fetch_assoc()) {
        $sites_for_dropdown[] = $row['site_name'];
    }
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
    $site_location = isset($_POST['site_location']) ? trim($_POST['site_location']) : '';

    // Get custom pull-out date and time - IMPORTANT FOR CUMULATIVE LOGIC
    $pullout_date = isset($_POST['pullout_date']) && !empty($_POST['pullout_date']) ? $_POST['pullout_date'] : date('Y-m-d');
    $pullout_time = isset($_POST['pullout_time']) && !empty($_POST['pullout_time']) ? $_POST['pullout_time'] : date('H:i:s');
    $custom_datetime = $pullout_date . ' ' . $pullout_time . ':00';
    
    // Check if enough stock at the time of pull-out? 
    // For simplicity, we check current stock
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
    
    // Check if site_location column exists, if not add it
    $check_column = "SHOW COLUMNS FROM stock_movements LIKE 'site_location'";
    $column_result = $conn->query($check_column);
    if ($column_result->num_rows == 0) {
        $conn->query("ALTER TABLE stock_movements ADD COLUMN site_location VARCHAR(255) DEFAULT NULL");
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update product stock - DEDUCT STOCK
        $update_sql = "UPDATE products SET quantity = quantity - ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ii", $quantity, $product_id);
        $stmt->execute();
        
        // Set site_location to NULL if empty
        $site_location_value = !empty($site_location) ? $site_location : null;
        
        // Insert stock movement with custom datetime - CRITICAL FOR CUMULATIVE LOGIC
        $movement_sql = "INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by, site_location, created_at) 
                         VALUES (?, 'out', ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($movement_sql);
        $stmt->bind_param("iississ", $product_id, $quantity, $reference, $notes, $current_user['id'], $site_location_value, $custom_datetime);
        $stmt->execute();
        
        $conn->commit();
        $_SESSION['success'] = "Stock removed successfully! " . $quantity . " units deducted from inventory.";
        if (!empty($site_location)) {
            $_SESSION['success'] .= " Site: " . $site_location;
        }
        $_SESSION['success'] .= " Date: " . date('F d, Y', strtotime($custom_datetime));
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

// Get stock movements for the selected date - FIXED QUERY
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

// Get all stock out movements for history with optional date range - WITH CATEGORY AND SITE LOCATION
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

$all_stock_out_sql = "SELECT 
                        sm.id,
                        sm.created_at,
                        sm.reference,
                        sm.quantity,
                        sm.type,
                        sm.site_location,
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
    /* Date and Time Picker Styles */
    input[type="date"].form-control,
    input[type="time"].form-control {
        cursor: pointer;
    }

    input[type="date"].form-control::-webkit-calendar-picker-indicator,
    input[type="time"].form-control::-webkit-calendar-picker-indicator {
        filter: invert(0.5);
        cursor: pointer;
    }

    input[type="date"].form-control:hover,
    input[type="time"].form-control:hover {
        border-color: #75e6da;
    }
    
    /* Purchase Success Badge - NEW */
    .purchase-success-badge {
        background: linear-gradient(135deg, #00b894, #75e6da);
        color: #1a1c3c;
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 600;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideInRight 0.3s ease;
    }
    
    .purchase-success-badge i {
        font-size: 20px;
    }
    
    /* Rest of your existing styles remain the same */
    .stock-tracker-container {
        padding: 20px;
        max-width: 1600px;
        margin: 0 auto;
    }

    /* Add styles for site dropdown */
    .site-quick-select {
        margin-top: 10px;
    }

    .site-quick-select select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--input-bg);
        color: var(--text-primary);
        font-size: 13px;
        cursor: pointer;
    }

    .site-quick-select select:focus {
        outline: none;
        border-color: #e84393;
    }

    .site-input-group {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .site-input-group input {
        flex: 1;
    }

    /* Site badge for history */
    .site-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        background: linear-gradient(135deg, #00b894, #75e6da);
        color: #1a1c3c;
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
        background: linear-gradient(135deg, #ffffff);
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
        background: linear-gradient(135deg, #d63031);
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
        align-items: center;
    }

    /* ========== ENHANCED DATE PICKER STYLES ========== */
    .date-picker-wrapper {
        position: relative;
        width: 100%;
    }

    .date-input-group {
        display: flex;
        align-items: center;
        position: relative;
        width: 100%;
    }

    .date-field {
        width: 100%;
        padding: 10px 15px 10px 12px;
        border: 2px solid var(--border-color);
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s;
        background: var(--bg-secondary);
        color: var(--text-primary);
        cursor: pointer;
        font-weight: 500;
        height: 42px;
    }

    .date-field:hover {
        border-color: #75e6da;
    }

    .date-field:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.25);
        outline: none;
    }

    .calendar-dropdown-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #75e6da;
        cursor: pointer;
        font-size: 0.9rem;
        padding: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }

    .calendar-dropdown-btn:hover {
        color: #62d4c8;
        transform: translateY(-50%) scale(1.1);
    }

    .calendar-wrapper {
        position: absolute;
        top: calc(100% + 5px);
        left: 50% !important;
        transform: translateX(-50%) !important;
        width: 40% !important;
        min-width: 240px;
        right: auto !important;
        background: var(--bg-primary);
        border: 2px solid #75e6da;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        z-index: 2000;
        display: none;
    }

    .calendar-wrapper.show {
        display: block;
    }

    .calendar-day {
        padding: 3px;
        font-size: 0.7rem;
    }

    .calendar-weekdays {
        font-size: 0.65rem;
        padding: 5px 2px;
    }

    .calendar-select {
        padding: 3px 5px;
        font-size: 0.65rem;
    }

    .calendar-header {
        padding: 6px 8px;
    }

    .calendar-nav-btn {
        width: 20px;
        height: 20px;
        font-size: 0.9rem;
    }

    .calendar-month-year {
        font-size: 0.8rem;
    }

    .calendar-footer {
        padding: 5px 8px;
    }

    .calendar-action-btn {
        padding: 3px 8px;
        font-size: 0.65rem;
    }

    .calendar-box {
        width: 100%;
        background: var(--bg-primary);
        border-radius: 12px;
        overflow: hidden;
    }

    .calendar-header {
        background: linear-gradient(135deg, #75e6da, #62d4c8);
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .calendar-nav {
        display: flex;
        gap: 8px;
    }

    .calendar-nav-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
    }

    .calendar-nav-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }

    .calendar-selectors {
        display: flex;
        gap: 8px;
        padding: 10px 10px 5px 10px;
        background: var(--bg-primary);
    }

    .calendar-select {
        flex: 1;
        border: 2px solid var(--border-color);
        border-radius: 6px;
        font-weight: 600;
        color: var(--text-primary);
        background: var(--bg-secondary);
        cursor: pointer;
        transition: all 0.3s;
        outline: none;
    }

    .calendar-select:hover {
        border-color: #75e6da;
    }

    .calendar-select:focus {
        border-color: #75e6da;
        box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.25);
    }

    .calendar-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: var(--bg-secondary);
        text-align: center;
        font-weight: 600;
        color: var(--text-primary);
    }

    .calendar-days-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        padding: 8px 5px;
        background: var(--bg-primary);
    }

    .calendar-day {
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-radius: 50%;
        transition: all 0.2s;
        color: var(--text-primary);
        text-decoration: none;
        border: none;
        background: none;
        width: 100%;
    }

    .calendar-day:hover {
        background: #e8f5e9;
        color: #62d4c8;
    }

    .calendar-day.selected {
        background: #75e6da;
        color: white;
        font-weight: 600;
    }

    .calendar-day.today {
        border: 2px solid #75e6da;
        font-weight: 600;
    }

    .calendar-day.weekend {
        color: #e74c3c;
    }

    .calendar-day.other-month {
        color: #7f8c8d;
        opacity: 0.5;
    }

    .calendar-footer {
        background: var(--bg-secondary);
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
    }

    .calendar-action-btn {
        border-radius: 5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border: none;
    }

    .calendar-action-btn.clear {
        background: var(--bg-primary);
        color: #7f8c8d;
        border: 1px solid var(--border-color);
    }

    .calendar-action-btn.clear:hover {
        background: #e74c3c;
        color: white;
        border-color: #e74c3c;
    }

    .calendar-action-btn.today {
        background: #75e6da;
        color: white;
        border: 1px solid #75e6da;
    }

    .calendar-action-btn.today:hover {
        background: #62d4c8;
    }

    @media (max-width: 768px) {
        .calendar-wrapper,
        #fromCalendar.calendar-wrapper,
        #toCalendar.calendar-wrapper {
            width: 90% !important;
            min-width: 260px;
        }
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
        background: linear-gradient(135deg, #ffffff);
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
        border-radius: 20px;
        width: 100%;
        max-width: 500px;
        box-shadow: 0 30px 60px rgba(232, 67, 147, 0.3);
        animation: slideInUp 0.4s ease;
        overflow: hidden;
    }

    .modal-content.large-modal {
        max-width: 1300px;
        width: 95%;
        margin: 20px auto;
        max-height: 90vh;
    }

    .modal-header {
        background: linear-gradient(135deg, #75e6da, #75e6da);
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 20px 20px 0 0;
    }

    .modal-header.view-header {
        background: linear-gradient(135deg, #75e6da);
    }

    .modal-header h2 {
        color: white;
        font-size: 18px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .modal-header h2 i {
        font-size: 20px;
    }

    .close-btn {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        transition: all 0.3s ease;
    }

    .close-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 20px;
        max-height: calc(90vh - 140px);
        min-height: 500px;
        overflow-y: auto;
        background: var(--bg-primary);
        scrollbar-width: thin;
        scrollbar-color: #08e2ff var(--bg-secondary);
    }

    .modal-body::-webkit-scrollbar {
        width: 6px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: var(--bg-secondary);
        border-radius: 8px;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #e84393, #d63031);
        border-radius: 8px;
    }

    .form-group {
        margin-bottom: 15px;
        position: relative;
    }

    .form-group:last-child {
        margin-bottom: 0;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        color: var(--text-primary);
        font-weight: 600;
        font-size: 13px;
    }

    .form-group label i {
        color: #f8017d;
        margin-right: 5px;
        width: 16px;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-size: 13px;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }

    .form-control:focus {
        border-color: #e84393;
        outline: none;
        box-shadow: 0 0 0 3px rgba(232, 67, 147, 0.15);
    }

    .form-control:disabled {
        background: var(--bg-secondary);
        opacity: 0.7;
        cursor: not-allowed;
    }

    textarea.form-control {
        resize: vertical;
        min-height: 60px;
    }

    .two-column {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 15px;
    }

    .warning-message {
        background: rgba(214, 48, 49, 0.15);
        border: 2px solid #d63031;
        border-radius: 8px;
        padding: 10px 15px;
        color: #d63031;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        animation: shake 0.5s ease;
        font-size: 13px;
        margin-bottom: 15px;
    }

    .modal-footer {
        padding: 15px 20px;
        background: var(--bg-secondary);
        border-top: 2px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        border-radius: 0 0 20px 20px;
    }

    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
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
        padding: 10px 18px;
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s ease;
        height: 40px;
    }

    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
    }

    /* View Modal Specific */
    .date-range-picker {
        display: flex;
        gap: 5px;
        margin-bottom: 15px;
        align-items: flex-end;
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .date-range-item {
        flex: 0 1 auto;
        min-width: 140px;
        margin-right: 2px;
    }

    .date-range-item label {
        display: block;
        margin-bottom: 5px;
        color: var(--text-primary);
        font-weight: 600;
        font-size: 12px;
    }

    .date-range-item label i {
        color: #3498db;
        margin-right: 3px;
    }

    .date-range-item .date-picker-wrapper {
        width: 100%;
        min-width: 180px;
    }

    .search-bar {
        margin-bottom: 15px;
        position: relative;
        display: flex;
        justify-content: flex-start;
    }

    .search-bar i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-secondary);
        font-size: 13px;
        z-index: 1;
        pointer-events: none;
    }

    .search-bar input {
        width: 250px;
        padding: 8px 12px 8px 35px;
        border: 1px solid var(--border-color);
        border-radius: 20px;
        background: var(--bg-secondary);
        color: var(--text-primary);
        font-size: 12px;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }

    .search-bar input:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        width: 280px;
    }

    .view-table-container {
        overflow-x: auto;
        max-height: 350px;
        overflow-y: auto;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        margin-top: 15px;
    }

    .view-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    .view-table th {
        background: linear-gradient(135deg, #00e8f8, #00e8f8);
        padding: 10px 8px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: white;
        border-bottom: 2px solid var(--border-color);
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .view-table th:first-child {
        border-radius: 8px 0 0 8px;
    }

    .view-table th:last-child {
        border-radius: 0 8px 8px 0;
    }

    .view-table td {
        padding: 8px;
        font-size: 12px;
        color: var(--text-primary);
        border-bottom: 1px solid var(--border-color);
    }

    .view-table tbody tr:hover {
        background: var(--hover-bg);
    }

    .view-table .stock-out {
        color: #d63031;
        font-weight: 700;
        font-size: 14px;
    }

    .view-count {
        margin-top: 12px;
        text-align: right;
        color: var(--text-secondary);
        font-size: 12px;
    }

    .view-count span {
        color: #3498db;
        font-weight: 700;
    }

    .alert {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: slideInRight 0.3s ease;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        max-width: 350px;
        font-size: 13px;
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
        font-size: 18px;
    }

    .text-muted {
        color: var(--text-secondary);
        font-style: italic;
    }

    .search-results {
        position: absolute;
        background: var(--bg-primary);
        border: 2px solid #e84393;
        border-radius: 12px;
        max-height: 300px;
        overflow-y: auto;
        width: calc(100% - 30px);
        z-index: 10000;
        margin-top: 5px;
        box-shadow: 0 15px 40px rgba(232, 67, 147, 0.25);
        left: 15px;
        right: 15px;
        display: none;
    }

    .availability-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 600;
    }

    .availability-badge.available {
        background: rgba(0, 184, 148, 0.15);
        color: #00b894;
        border: 1px solid #00b894;
    }

    .availability-badge.unavailable {
        background: rgba(214, 48, 49, 0.15);
        color: #d63031;
        border: 1px solid #d63031;
    }

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

    @media (max-width: 768px) {
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
            align-items: stretch;
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
            padding: 15px;
        }
        
        .two-column {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .date-range-picker {
            flex-direction: column;
            gap: 10px;
        }
        
        .date-range-item {
            width: 100%;
            margin-right: 0;
        }
        
        .btn-filter {
            width: 100%;
            justify-content: center;
            margin-left: 0;
        }
        
        .search-bar {
            justify-content: flex-start;
            width: 100%;
        }
        
        .search-bar input {
            width: 100%;
        }
        
        .search-bar input:focus {
            width: 100%;
        }
        
        .modal-footer {
            flex-direction: column-reverse;
            gap: 8px;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="stock-tracker-container">
    <!-- Purchase Success Notification - NEW -->
    <?php if(isset($_GET['purchased']) && $_GET['purchased'] == 'success'): ?>
    <div class="purchase-success-badge" id="purchaseSuccessAlert">
        <i class="fas fa-check-circle"></i>
        <div>
            <strong>Order Completed Successfully!</strong><br>
            <small>The item has been added to stock movements for <?php echo date('F d, Y', strtotime($selected_date)); ?></small>
        </div>
        <i class="fas fa-times" style="cursor: pointer; margin-left: auto;" onclick="this.parentElement.remove()"></i>
    </div>
    <?php endif; ?>
    
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-text">
            <h1>Stock In - Out - Balance Tracker</h1>
            <p>Monitor daily inventory movements from purchases and manual entries</p>
        </div>
        <div class="action-buttons">
            <button class="btn-action btn-stock-out" onclick="openStockOutModal()">
                <i class="fas fa-minus-circle"></i> Pull out Item
            </button>
            <button class="btn-action btn-view-stock" onclick="openViewStockOutModal()">
                <i class="fas fa-history"></i> Pull-out Records
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
            <div class="stat-details">
                <h3>TOTAL PRODUCTS</h3>
                <p class="stat-value"><?php echo number_format($total_products); ?></p>
                <span class="stat-trend positive">Active items</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-details">
                <h3>TOTAL STOCK IN</h3>
                <p class="stat-value"><?php echo number_format($total_stock_in); ?></p>
                <span class="stat-trend positive">All time</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-details">
                <h3>TOTAL STOCK OUT</h3>
                <p class="stat-value"><?php echo number_format($total_stock_out); ?></p>
                <span class="stat-trend negative">All time</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-details">
                <h3>LOW STOCK ITEMS</h3>
                <p class="stat-value <?php echo $low_stock_count > 0 ? 'warning' : ''; ?>"><?php echo number_format($low_stock_count); ?></p>
                <span class="stat-trend <?php echo $low_stock_count > 0 ? 'warning' : 'positive'; ?>">Need attention</span>
            </div>
        </div>
    </div>

    <!-- Date Navigation with Enhanced Date Picker -->
    <div class="date-navigation">
        <div class="date-selector">
            <button class="date-nav-btn" onclick="changeDate(-1)" title="Previous Day"><i class="fas fa-chevron-left"></i></button>
            <span class="current-date" id="currentDateDisplay"><?php echo date('F d, Y', strtotime($selected_date)); ?></span>
            <button class="date-nav-btn" onclick="changeDate(1)" title="Next Day"><i class="fas fa-chevron-right"></i></button>
        </div>
        
        <div class="date-actions">
            <div class="date-picker-wrapper" style="width: 180px;">
                <div class="date-input-group">
                    <input type="text" id="datePickerField" class="date-field" value="<?php echo date('m/d/Y', strtotime($selected_date)); ?>" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('main')">
                    <input type="hidden" id="datePicker" name="datePicker" value="<?php echo $selected_date; ?>">
                    <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('main')"><i class="fas fa-chevron-down"></i></button>
                </div>
                <div class="calendar-wrapper" id="mainCalendar">
                    <div class="calendar-box">
                        <div class="calendar-header"><div class="calendar-month-year" id="mainMonthYear"></div><div class="calendar-nav"><button type="button" class="calendar-nav-btn" onclick="navigateMonth('main', -1)">‹</button><button type="button" class="calendar-nav-btn" onclick="navigateMonth('main', 1)">›</button></div></div>
                        <div class="calendar-selectors"><select id="mainMonthSelect" class="calendar-select" onchange="changeMonthYear('main')"><option value="0">January</option><option value="1">February</option><option value="2">March</option><option value="3">April</option><option value="4">May</option><option value="5">June</option><option value="6">July</option><option value="7">August</option><option value="8">September</option><option value="9">October</option><option value="10">November</option><option value="11">December</option></select><select id="mainYearSelect" class="calendar-select" onchange="changeMonthYear('main')"></select></div>
                        <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                        <div class="calendar-days-grid" id="mainDaysGrid"></div>
                        <div class="calendar-footer"><button type="button" class="calendar-action-btn clear" onclick="clearDate('main')"><i class="fas fa-times"></i> Clear</button><button type="button" class="calendar-action-btn today" onclick="setToday('main')"><i class="fas fa-calendar-check"></i> Today</button></div>
                    </div>
                </div>
            </div>
            <button class="btn-date btn-today" onclick="goToToday()"><i class="fas fa-calendar-day"></i> Today</button>
            <button class="btn-date btn-export" onclick="exportDailyData()"><i class="fas fa-download"></i> Export</button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <button class="tab-btn active" onclick="switchTab('daily')" id="tabDaily"><i class="fas fa-calendar-day"></i> DAILY TRACKER</button>
        <button class="tab-btn" onclick="switchTab('balances')" id="tabBalances"><i class="fas fa-balance-scale"></i> CURRENT BALANCES</button>
    </div>

    <!-- Daily Tracker Tab -->
    <div id="dailyTab" class="tab-content active">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3><i class="fas fa-clipboard-list"></i> Stock Movements for <?php echo date('M d, Y', strtotime($selected_date)); ?></h3>
                <span class="tracker-badge"><i class="fas fa-box"></i> <?php echo ($daily_movements) ? $daily_movements->num_rows : 0; ?> items</span>
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
                        <?php while($movement = $daily_movements->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?></td>
                            <td><?php if(!empty($movement['category'])): ?><span class="category-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($movement['category']); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?></td>
                            <td><?php echo date('M d, Y h:i:s A', strtotime($movement['created_at'])); ?></td>
                            <td class="<?php echo $movement['type'] == 'in' ? 'stock-in' : 'stock-out'; ?>"><?php echo $movement['type'] == 'in' ? '+' : '-'; ?><?php echo number_format($movement['quantity']); ?></td>
                            <td><span class="reference-tag <?php echo strpos($movement['reference'], 'PURCHASE') !== false ? 'reference-purchase' : 'reference-other'; ?>"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($movement['reference'] ?: 'N/A'); ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No stock movements found for this date: <?php echo date('M d, Y', strtotime($selected_date)); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="footer-stats"><div><i class="fas fa-boxes" style="color: #75e6da;"></i> Total Items: <strong style="color: #75e6da;"><?php echo ($daily_movements) ? $daily_movements->num_rows : 0; ?></strong></div><div><i class="fas fa-calendar" style="color: #6c5ce7;"></i> Date: <strong style="color: #6c5ce7;"><?php echo date('F d, Y', strtotime($selected_date)); ?></strong></div></div>
        </div>
    </div>

    <!-- Current Balances Tab -->
    <div id="balancesTab" class="tab-content">
        <div class="tracker-container">
            <div class="tracker-header">
                <h3><i class="fas fa-boxes"></i> Current Stock Balances</h3>
                <span class="tracker-badge"><i class="fas fa-box"></i> <?php echo ($balances) ? $balances->num_rows : 0; ?> products</span>
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
                        <?php while($product = $balances->fetch_assoc()): 
                            $balance = $product['current_balance'] ?? 0;
                            $threshold = $product['low_stock_threshold'] ?? 10;
                            if ($balance <= 0) { $status_class = 'stock-out'; $status_text = 'Out of Stock'; }
                            elseif ($balance < $threshold) { $status_class = 'warning'; $status_text = 'Low Stock'; }
                            else { $status_class = 'stock-in'; $status_text = 'In Stock'; }
                            $item_no_display = $product['item_no'] ?? '';
                            $description_display = $product['description'] ?? $product['name'] ?? '';
                            if (empty($item_no_display) && isset($product['name']) && strpos($product['name'], ' - ') !== false) {
                                $parts = explode(' - ', $product['name'], 2);
                                $item_no_display = $parts[0];
                                $description_display = $parts[1];
                            }
                            $last_mov_sql = "SELECT reference, created_at FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 1";
                            $last_stmt = $conn->prepare($last_mov_sql);
                            $last_stmt->bind_param("i", $product['id']);
                            $last_stmt->execute();
                            $last_result = $last_stmt->get_result();
                            $last_mov = $last_result->fetch_assoc();
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item_no_display ?: 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($description_display ?: 'N/A'); ?></td>
                            <td><?php if(!empty($product['category'])): ?><span class="category-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($product['category']); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($product['unit'] ?? 'pcs'); ?></td>
                            <td class="stock-balance"><?php echo number_format($balance); ?></td>
                            <td><span class="type-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td><?php if ($last_mov): ?><span style="font-size: 11px;"><?php echo htmlspecialchars($last_mov['reference'] ?? 'N/A'); ?><br><small><?php echo date('M d, Y h:i:s A', strtotime($last_mov['created_at'])); ?></small></span><?php else: ?><span class="text-muted">No movements</span><?php endif; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px;"><i class="fas fa-box-open" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- STOCK OUT MODAL - RECORD STOCK OUT (WITH SITE QUICK SELECT DROPDOWN) -->
<div id="stockOutModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-minus-circle"></i> Record Stock Out</h2>
            <button class="close-btn" onclick="closeStockOutModal()">&times;</button>
        </div>
        
        <form method="POST" action="" onsubmit="return validateStockOut()">
            <div class="modal-body">
                <input type="hidden" name="add_stock_out" value="1">
                <input type="hidden" name="product_id" id="selectedProductId">
                
                <!-- UPPER SECTION - Item Search -->
                <div class="upper-section">
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Item Number / Product Name</label>
                        <input type="text" id="itemSearchInput" class="form-control" placeholder="Type item number or product name..." onkeyup="searchItems()" autocomplete="off" required>
                        <div id="searchResults"></div>
                    </div>
                    
                    <div id="selectedProductInfo" style="display: none; margin-bottom: 10px;">
                        <div style="background: rgba(232, 67, 147, 0.08); padding: 8px 10px; border-radius: 6px; border-left: 3px solid #e84393;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1;">
                                    <span style="font-size: 9px; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Selected:</span>
                                    <h4 id="selectedProductName" style="margin: 2px 0 0; font-size: 13px; font-weight: 600;"></h4>
                                    <p id="selectedProductDetails" style="margin: 2px 0 0; font-size: 10px; color: var(--text-secondary);"></p>
                                </div>
                                <span class="availability-badge available" id="selectedStockBadge" style="padding: 3px 8px; font-size: 10px;">In Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr style="margin: 8px 0 12px; border: 1px dashed var(--border-color); opacity: 0.5;">

                <!-- LOWER SECTION - WITH SITE QUICK SELECT DROPDOWN -->
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
                    
                   <!-- DATE AND TIME PICKER SECTION - UPDATED -->
<div class="two-column">
    <div class="form-group">
        <label><i class="fas fa-calendar-alt"></i> Pull-out Date *</label>
        <input type="date" name="pullout_date" id="pulloutDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
        <small style="color: var(--text-secondary); font-size: 11px;">
            <i class="fas fa-info-circle"></i> Select the date when item was pulled out (affects cumulative total)
        </small>
    </div>
    
    <div class="form-group">
        <label><i class="fas fa-clock"></i> Pull-out Time</label>
        <input type="time" name="pullout_time" id="pulloutTime" class="form-control" value="<?php echo date('H:i'); ?>">
        <small style="color: var(--text-secondary); font-size: 11px;">
            <i class="fas fa-info-circle"></i> Optional: Select the time of pull-out
        </small>
    </div>
</div>
                    
                    <div id="quantityWarningContainer"></div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Site / Location</label>
                        <div class="site-input-group">
                            <input type="text" name="site_location" id="siteLocation" class="form-control" placeholder="Enter site/location where item will be deployed" autocomplete="off">
                        </div>
                    </div>
                    
                    <!-- QUICK SELECT DROPDOWN -->
                    <?php if (!empty($sites_for_dropdown)): ?>
                    <div class="form-group site-quick-select">
                        <label><i class="fas fa-building"></i> Quick Select Site</label>
                        <select id="quickSiteSelect" onchange="selectQuickSite()">
                            <option value="">-- Select from existing sites --</option>
                            <?php foreach ($sites_for_dropdown as $site): ?>
                                <option value="<?php echo htmlspecialchars($site); ?>"><?php echo htmlspecialchars($site); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-secondary); font-size: 11px; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Choose a site from the list or type a new one above
                        </small>
                    </div>
                    <?php else: ?>
                    <div class="form-group site-quick-select">
                        <label><i class="fas fa-building"></i> Quick Select Site</label>
                        <select id="quickSiteSelect" onchange="selectQuickSite()">
                            <option value="">-- No sites available, add one in Site tab --</option>
                        </select>
                        <small style="color: var(--text-secondary); font-size: 11px; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Go to <strong>Site</strong> tab to add locations
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="e.g., SO-2024-001">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
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

<!-- VIEW STOCK OUT MODAL - WITH SITE LOCATION COLUMN -->
<div id="viewStockOutModal" class="modal">
    <div class="modal-content large-modal">
        <div class="modal-header view-header">
            <h2><i class="fas fa-history"></i> Pull-out History</h2>
            <button class="close-btn" onclick="closeViewStockOutModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="date-range-picker">
                <div class="date-range-item">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="dateFromField" class="date-field" value="<?php echo date('m/d/Y', strtotime($date_from)); ?>" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('from')">
                            <input type="hidden" id="dateFrom" name="dateFrom" value="<?php echo $date_from; ?>">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('from')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="fromCalendar"><div class="calendar-box"><div class="calendar-header"><div class="calendar-month-year" id="fromMonthYear"></div><div class="calendar-nav"><button type="button" class="calendar-nav-btn" onclick="navigateMonth('from', -1)">‹</button><button type="button" class="calendar-nav-btn" onclick="navigateMonth('from', 1)">›</button></div></div><div class="calendar-selectors"><select id="fromMonthSelect" class="calendar-select" onchange="changeMonthYear('from')"><option value="0">January</option><option value="1">February</option><option value="2">March</option><option value="3">April</option><option value="4">May</option><option value="5">June</option><option value="6">July</option><option value="7">August</option><option value="8">September</option><option value="9">October</option><option value="10">November</option><option value="11">December</option></select><select id="fromYearSelect" class="calendar-select" onchange="changeMonthYear('from')"></select></div><div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div><div class="calendar-days-grid" id="fromDaysGrid"></div><div class="calendar-footer"><button type="button" class="calendar-action-btn clear" onclick="clearDate('from')"><i class="fas fa-times"></i> Clear</button><button type="button" class="calendar-action-btn today" onclick="setToday('from')"><i class="fas fa-calendar-check"></i> Today</button></div></div></div>
                    </div>
                </div>
                <div class="date-range-item">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="dateToField" class="date-field" value="<?php echo date('m/d/Y', strtotime($date_to)); ?>" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('to')">
                            <input type="hidden" id="dateTo" name="dateTo" value="<?php echo $date_to; ?>">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('to')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="toCalendar"><div class="calendar-box"><div class="calendar-header"><div class="calendar-month-year" id="toMonthYear"></div><div class="calendar-nav"><button type="button" class="calendar-nav-btn" onclick="navigateMonth('to', -1)">‹</button><button type="button" class="calendar-nav-btn" onclick="navigateMonth('to', 1)">›</button></div></div><div class="calendar-selectors"><select id="toMonthSelect" class="calendar-select" onchange="changeMonthYear('to')"><option value="0">January</option><option value="1">February</option><option value="2">March</option><option value="3">April</option><option value="4">May</option><option value="5">June</option><option value="6">July</option><option value="7">August</option><option value="8">September</option><option value="9">October</option><option value="10">November</option><option value="11">December</option></select><select id="toYearSelect" class="calendar-select" onchange="changeMonthYear('to')"></select></div><div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div><div class="calendar-days-grid" id="toDaysGrid"></div><div class="calendar-footer"><button type="button" class="calendar-action-btn clear" onclick="clearDate('to')"><i class="fas fa-times"></i> Clear</button><button type="button" class="calendar-action-btn today" onclick="setToday('to')"><i class="fas fa-calendar-check"></i> Today</button></div></div></div>
                    </div>
                </div>
                <button class="btn-filter" onclick="filterByDateRange()"><i class="fas fa-filter"></i> Apply Filter</button>
                <button class="btn-filter" style="background: linear-gradient(135deg, #95a5a6, #7f8c8d);" onclick="resetDateRange()"><i class="fas fa-undo"></i> Reset</button>
            </div>

            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="stockOutSearch" placeholder="Search by Item No, Description, Category, Site, Reference..." onkeyup="searchStockOut()">
            </div>
            
            <div class="view-table-container">
                <table class="view-table" id="stockOutTable">
                    <thead>
                        <tr>
                            <th>Item No</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Date & Time (with seconds)</th>
                            <th>Quantity</th>
                            <th>Site / Location</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody id="stockOutTableBody">
                        <?php if ($all_stock_out && $all_stock_out->num_rows > 0): ?>
                            <?php while($movement = $all_stock_out->fetch_assoc()): ?>
                            <tr class="stock-out-row">
                                <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong>   </tr>
                                <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?>   </tr>
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
                                  </tr>
                                <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?>   </tr>
                                <td><?php echo date('M d, Y h:i:s A', strtotime($movement['created_at'])); ?>   </tr>
                                <td class="stock-out">-<?php echo number_format($movement['quantity']); ?>   </tr>
                                <td>
                                    <?php if (!empty($movement['site_location'])): ?>
                                        <span class="site-badge"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($movement['site_location']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                  </tr>
                                <td>
                                    <span class="reference-tag reference-other">
                                        <i class="fas fa-tag"></i> 
                                        <?php echo htmlspecialchars($movement['reference'] ?: 'N/A'); ?>
                                    </span>
                                  </tr>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
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
// Calendar state (adapted from employee.php)
let activeCalendar = null;
let calendarDates = {
    main: { currentDate: new Date(), selectedDate: '<?php echo $selected_date; ?>' },
    from: { currentDate: new Date(), selectedDate: '<?php echo $date_from; ?>' },
    to: { currentDate: new Date(), selectedDate: '<?php echo $date_to; ?>' }
};

// Initialize year dropdowns
function initializeYearSelects() {
    const yearSelects = ['mainYearSelect', 'fromYearSelect', 'toYearSelect'];
    const currentYear = new Date().getFullYear();
    
    yearSelects.forEach(selectId => {
        const select = document.getElementById(selectId);
        if (select) {
            select.innerHTML = '';
            for (let year = 2000; year <= 2030; year++) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                if (year === currentYear) {
                    option.selected = true;
                }
                select.appendChild(option);
            }
        }
    });
}

// Update calendar display
function updateCalendar(calendarId) {
    const date = calendarDates[calendarId].currentDate || new Date();
    const year = date.getFullYear();
    const month = date.getMonth();
    
    const monthYearElement = document.getElementById(calendarId + 'MonthYear');
    if (monthYearElement) {
        monthYearElement.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    
    const monthSelect = document.getElementById(calendarId + 'MonthSelect');
    const yearSelect = document.getElementById(calendarId + 'YearSelect');
    
    if (monthSelect) monthSelect.value = month;
    if (yearSelect) yearSelect.value = year;
    
    generateCalendarDays(calendarId);
}

// Generate calendar days
function generateCalendarDays(calendarId) {
    const date = calendarDates[calendarId].currentDate || new Date();
    const year = date.getFullYear();
    const month = date.getMonth();
    const daysGrid = document.getElementById(calendarId + 'DaysGrid');
    
    if (!daysGrid) return;
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    const selectedDate = calendarDates[calendarId].selectedDate;
    
    let html = '';
    
    // Previous month days
    const prevMonthDays = new Date(year, month, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
    }
    
    // Current month days
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
        const isSelected = selectedDate === dateStr;
        const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
        
        let classes = 'calendar-day';
        if (isToday) classes += ' today';
        if (isSelected) classes += ' selected';
        if (isWeekend) classes += ' weekend';
        
        html += `<div class="${classes}" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
    }
    
    // Next month days
    const totalCells = 42;
    const cellsUsed = firstDay + daysInMonth;
    const nextMonthDays = totalCells - cellsUsed;
    for (let day = 1; day <= nextMonthDays; day++) {
        const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
    }
    
    daysGrid.innerHTML = html;
}

// Toggle calendar
function toggleCalendar(calendarId) {
    const calendar = document.getElementById(calendarId + 'Calendar');
    if (calendar) {
        document.querySelectorAll('.calendar-wrapper').forEach(cal => {
            if (cal.id !== calendarId + 'Calendar') {
                cal.style.display = 'none';
            }
        });
        
        if (calendar.style.display === 'block') {
            calendar.style.display = 'none';
            activeCalendar = null;
        } else {
            updateCalendar(calendarId);
            calendar.style.display = 'block';
            activeCalendar = calendarId;
        }
    }
}

// Navigate months
function navigateMonth(calendarId, direction) {
    const date = calendarDates[calendarId].currentDate;
    date.setMonth(date.getMonth() + direction);
    updateCalendar(calendarId);
}

// Change month/year from selects
function changeMonthYear(calendarId) {
    const monthSelect = document.getElementById(calendarId + 'MonthSelect');
    const yearSelect = document.getElementById(calendarId + 'YearSelect');
    
    if (monthSelect && yearSelect) {
        const newMonth = parseInt(monthSelect.value);
        const newYear = parseInt(yearSelect.value);
        
        calendarDates[calendarId].currentDate = new Date(newYear, newMonth, 1);
        updateCalendar(calendarId);
    }
}

function selectDate(calendarId, dateStr) {
    const date = new Date(dateStr);
    const formattedDisplay = date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    let fieldId = '';
    let hiddenId = '';
    
    switch(calendarId) {
        case 'main':
            fieldId = 'datePickerField';
            hiddenId = 'datePicker';
            // Update the page with the selected date
            updatePageDate(dateStr);
            break;
        case 'from':
            fieldId = 'dateFromField';
            hiddenId = 'dateFrom';
            break;
        case 'to':
            fieldId = 'dateToField';
            hiddenId = 'dateTo';
            break;
    }
    
    const field = document.getElementById(fieldId);
    const hidden = document.getElementById(hiddenId);
    
    if (field) {
        field.value = formattedDisplay;
    }
    
    if (hidden) {
        hidden.value = dateStr;
    }
    
    calendarDates[calendarId].selectedDate = dateStr;
    
    const calendar = document.getElementById(calendarId + 'Calendar');
    if (calendar) calendar.style.display = 'none';
    activeCalendar = null;
    
    updateCalendar(calendarId);
    
    // If it's the main calendar, also update the arrows and display
    if (calendarId === 'main') {
        // Update the current date display
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        const month = monthNames[date.getMonth()];
        const day = date.getDate();
        const year = date.getFullYear();
        document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    }
}

// Clear date
function clearDate(calendarId) {
    let fieldId = '';
    let hiddenId = '';
    
    switch(calendarId) {
        case 'main':
            fieldId = 'datePickerField';
            hiddenId = 'datePicker';
            break;
        case 'from':
            fieldId = 'dateFromField';
            hiddenId = 'dateFrom';
            break;
        case 'to':
            fieldId = 'dateToField';
            hiddenId = 'dateTo';
            break;
    }
    
    const field = document.getElementById(fieldId);
    const hidden = document.getElementById(hiddenId);
    
    if (field) field.value = '';
    if (hidden) hidden.value = '';
    
    calendarDates[calendarId].selectedDate = null;
    
    const calendar = document.getElementById(calendarId + 'Calendar');
    if (calendar) calendar.style.display = 'none';
    activeCalendar = null;
    
    updateCalendar(calendarId);
}

// Set today's date
function setToday(calendarId) {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    
    calendarDates[calendarId].currentDate = new Date(dateStr);
    selectDate(calendarId, dateStr);
}

// Close calendar when clicking outside
document.addEventListener('click', function(e) {
    const isCalendarClick = e.target.closest('.calendar-wrapper') || e.target.closest('.date-input-group');
    if (!isCalendarClick && activeCalendar) {
        const calendar = document.getElementById(activeCalendar + 'Calendar');
        if (calendar) calendar.style.display = 'none';
        activeCalendar = null;
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeYearSelects();
    
    ['main', 'from', 'to'].forEach(calId => {
        updateCalendar(calId);
    });

    // Set initial selected dates
    const mainDate = '<?php echo $selected_date; ?>';
    const fromDate = '<?php echo $date_from; ?>';
    const toDate = '<?php echo $date_to; ?>';
    
    if (mainDate) {
        calendarDates.main.selectedDate = mainDate;
        const date = new Date(mainDate);
        document.getElementById('datePickerField').value = date.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
        
        // Also update the current date display
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        const month = monthNames[date.getMonth()];
        const day = date.getDate();
        const year = date.getFullYear();
        document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    }
    
    if (fromDate) {
        calendarDates.from.selectedDate = fromDate;
        const date = new Date(fromDate);
        document.getElementById('dateFromField').value = date.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
    }
    
    if (toDate) {
        calendarDates.to.selectedDate = toDate;
        const date = new Date(toDate);
        document.getElementById('dateToField').value = date.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
    }
    
    // Auto-hide purchase success alert after 5 seconds
    setTimeout(() => {
        const alert = document.getElementById('purchaseSuccessAlert');
        if (alert) {
            alert.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        }
    }, 5000);
});


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
                        <div class="search-result-item" onclick="selectProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.item_no || ''}', ${stockValue}, '${product.unit || 'pcs'}')">
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
            <div class="warning-message">
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

// Validate before submit - updated to check date
function validateStockOut() {
    const productId = document.getElementById('selectedProductId').value;
    const quantity = parseInt(document.getElementById('stockOutQuantity').value);
    const available = parseInt(document.getElementById('availableStock').value);
    const pulloutDate = document.getElementById('pulloutDate').value;
    
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
    
    if (!pulloutDate) {
        alert('Please select the pull-out date');
        return false;
    }
    
    return true;
}

// Quick Select Site function
function selectQuickSite() {
    const select = document.getElementById('quickSiteSelect');
    const siteInput = document.getElementById('siteLocation');
    if (select.value) {
        siteInput.value = select.value;
    }
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

function changeDate(days) {
    const currentDateStr = document.getElementById('datePicker').value;
    const currentDate = new Date(currentDateStr || new Date());
    currentDate.setDate(currentDate.getDate() + days);
    const newDateStr = currentDate.toISOString().split('T')[0];
    
    // Update the date picker field
    const formattedDisplay = currentDate.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    document.getElementById('datePickerField').value = formattedDisplay;
    document.getElementById('datePicker').value = newDateStr;
    
    // Update the calendar's selected date
    calendarDates.main.selectedDate = newDateStr;
    updateCalendar('main');
    
    // Update the current date display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
    const month = monthNames[currentDate.getMonth()];
    const day = currentDate.getDate();
    const year = currentDate.getFullYear();
    document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    
    // Reload the page with the new date
    window.location.href = 'stock_tracker.php?date=' + newDateStr;
}

function goToToday() {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    const formattedDisplay = today.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    document.getElementById('datePickerField').value = formattedDisplay;
    document.getElementById('datePicker').value = todayStr;
    
    // Update the calendar's selected date
    calendarDates.main.selectedDate = todayStr;
    updateCalendar('main');
    
    // Update the current date display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
    const month = monthNames[today.getMonth()];
    const day = today.getDate();
    const year = today.getFullYear();
    document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    
    window.location.href = 'stock_tracker.php?date=' + todayStr;
}
// Add this new function to update the page when date changes
function updatePageDate(dateStr) {
    // Update the hidden input
    document.getElementById('datePicker').value = dateStr;
    
    // Update the display format
    const displayDate = new Date(dateStr);
    const formattedDisplay = displayDate.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    document.getElementById('datePickerField').value = formattedDisplay;
    
    // Update the current date display (F d, Y format)
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
    const month = monthNames[displayDate.getMonth()];
    const day = displayDate.getDate();
    const year = displayDate.getFullYear();
    document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    
    // Update the calendar's selected date
    calendarDates.main.selectedDate = dateStr;
    updateCalendar('main');
    
    // Reload the page with the new date
    window.location.href = 'stock_tracker.php?date=' + dateStr;
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
    setTimeout(() => {
        document.getElementById('stockOutSearch').focus();
        updateCalendar('from');
        updateCalendar('to');
    }, 100);
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
    let csv = 'Item No,Description,Category,Unit,Date & Time,Quantity,Site/Location,Reference\n';
    
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const cells = row.querySelectorAll('td');
            csv += `"${cells[0]?.textContent.trim() || ''}","${cells[1]?.textContent.trim() || ''}","${cells[2]?.textContent.trim() || ''}","${cells[3]?.textContent.trim() || ''}","${cells[4]?.textContent.trim() || ''}","${cells[5]?.textContent.trim() || ''}","${cells[6]?.textContent.trim() || ''}","${cells[7]?.textContent.trim() || ''}"\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'stock_out_history_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    
    showCustomNotification('Pull-out History exported successfully!', 'success');
}

function showCustomNotification(message, type = 'success') {
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
        background: ${type === 'success' ? 'linear-gradient(135deg, #00b894, #75e6da)' : '#d63031'};
        color: ${type === 'success' ? '#1a1c3c' : 'white'};
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0, 184, 148, 0.3);
        animation: slideInRight 0.3s ease;
        max-width: 400px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    `;
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}" style="font-size: 20px;"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
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