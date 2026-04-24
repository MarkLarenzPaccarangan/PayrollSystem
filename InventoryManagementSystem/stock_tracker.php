<?php
// stock_tracker.php - COMPLETE WITH EXPORT DATE RANGE AND PRINT FUNCTIONALITY
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
        
        $conn->begin_transaction();
        
        try {
            $update_sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $quantity, $product_id);
            $stmt->execute();
            
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

    $pullout_date = isset($_POST['pullout_date']) && !empty($_POST['pullout_date']) ? $_POST['pullout_date'] : date('Y-m-d');
    // No time needed - use start of day or current time for timestamp
    $custom_datetime = $pullout_date . ' ' . date('H:i:s');
    
    // Calculate stock AS OF the selected pull-out date
    $check_sql = "SELECT 
                    COALESCE(
                        (SELECT SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END) 
                         FROM stock_movements sm 
                         WHERE sm.product_id = ? AND DATE(sm.created_at) <= ?), 0
                    ) -
                    COALESCE(
                        (SELECT SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END) 
                         FROM stock_movements sm 
                         WHERE sm.product_id = ? AND DATE(sm.created_at) <= ?), 0
                    ) as available_stock";
    
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("isis", $product_id, $pullout_date, $product_id, $pullout_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stock_data = $result->fetch_assoc();
    $available_stock = intval($stock_data['available_stock']);
    
    if ($available_stock < $quantity) {
        $_SESSION['error'] = "Insufficient stock as of " . date('M d, Y', strtotime($pullout_date)) . "! Available: " . $available_stock;
        header("Location: stock_tracker.php");
        exit();
    }
    
    // Rest of the stock-out logic continues...
    // Continue with the rest of the stock-out logic...
    $check_column = "SHOW COLUMNS FROM stock_movements LIKE 'site_location'";
    // ... existing code continues ...
    $column_result = $conn->query($check_column);
    if ($column_result->num_rows == 0) {
        $conn->query("ALTER TABLE stock_movements ADD COLUMN site_location VARCHAR(255) DEFAULT NULL");
    }
    
    $conn->begin_transaction();
    
    try {
        $update_sql = "UPDATE products SET quantity = quantity - ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ii", $quantity, $product_id);
        $stmt->execute();
        
        $site_location_value = !empty($site_location) ? $site_location : null;
        
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

// Get stock movements for the selected date
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
                  AND sm.type != 'deduct'
                  ORDER BY sm.created_at DESC";
$stmt = $conn->prepare($movements_sql);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$daily_movements = $stmt->get_result();

// Get all stock out movements for history with optional date range
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
                      AND sm.site_location IS NOT NULL
                      AND sm.site_location != ''
                      AND DATE(sm.created_at) BETWEEN ? AND ?
                      ORDER BY sm.created_at DESC";

$stmt = $conn->prepare($all_stock_out_sql);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$all_stock_out = $stmt->get_result();

// Get statistics
$total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] ?? 0;
$total_stock_in = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'in'")->fetch_assoc()['total'] ?? 0;
$total_stock_out = $conn->query("SELECT SUM(quantity) as total FROM stock_movements WHERE type = 'out'")->fetch_assoc()['total'] ?? 0;
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < low_stock_threshold")->fetch_assoc()['count'] ?? 0;

require_once 'include/header.php';
?>

<style>
    .search-result-item {
        transition: all 0.2s ease;
    }
    .search-result-item:hover {
        background: var(--hover-bg);
        transform: translateX(5px);
    }
    .search-result-item:hover .select-this-badge {
        background: #e84393 !important;
        color: white !important;
    }
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
    
    .stock-tracker-container {
        padding: 20px;
        max-width: 1600px;
        margin: 0 auto;
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

    .site-quick-select select option {
        background-color: #1a1c3c;
        color: #75e6da;
    }

    .site-quick-select select:focus {
        outline: none;
        border-color: #1a1c3c;
    }

    .site-input-group {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .site-input-group input {
        flex: 1;
    }

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
    }

    .welcome-text p {
        color: var(--text-secondary);
        font-size: 15px;
    }

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
    }

    .date-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }

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
        left: 0 !important;
        right: auto !important;
        transform: none !important;
        width: 280px !important;
        min-width: 280px;
        background: var(--bg-primary);
        border: 2px solid #75e6da;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        z-index: 10000;
        display: none;
    }

    .modal .calendar-wrapper {
        z-index: 10002;
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
        .calendar-wrapper {
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

    .tracker-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
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
    min-height: 300px;
    overflow-y: scroll !important;
    overflow-x: hidden;
    background: var(--bg-primary);
    scrollbar-width: thin;
    scrollbar-color: #75e6da var(--bg-secondary);
    box-sizing: border-box;
}

/* Always visible scrollbar INSIDE the modal body */
.modal-body::-webkit-scrollbar {
    width: 10px !important;
    display: block !important;
    background: transparent;
}

.modal-body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 8px;
    margin: 5px 0;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #75e6da;
    border-radius: 8px;
    border: 2px solid var(--bg-secondary);
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: #5fd9d0;
}

/* Firefox scrollbar inside modal */
.modal-body {
    scrollbar-width: thin;
    scrollbar-color: #75e6da var(--bg-secondary);
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

 /* Date Range Container - EXACT MATCH to Calendar Size (280px) */
.date-range-picker {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    align-items: flex-end;
    flex-wrap: wrap;
    justify-content: flex-start;
}

.date-range-item {
    flex: 0 0 280px;
    width: 280px;
    min-width: 280px;
    max-width: 280px;
}

.date-range-item .date-picker-wrapper {
    width: 280px;
    min-width: 280px;
    max-width: 280px;
}

/* Make the date field match calendar wrapper width EXACTLY */
.date-range-item .date-field {
    width: 280px;
    min-width: 280px;
    max-width: 280px;
}

/* Ensure calendar wrapper aligns perfectly with container */
.date-range-item .calendar-wrapper {
    width: 280px !important;
    min-width: 280px !important;
    max-width: 280px !important;
}

/* For mobile responsiveness */
@media (max-width: 768px) {
    .date-range-item {
        width: 100%;
        min-width: 100%;
        max-width: 100%;
    }
    
    .date-range-item .date-picker-wrapper,
    .date-range-item .date-field,
    .date-range-item .calendar-wrapper {
        width: 100% !important;
        min-width: 100% !important;
        max-width: 100% !important;
    }
}
/* For mobile responsiveness */
@media (max-width: 768px) {
    .date-range-item {
        width: 100%;
        min-width: 100%;
    }
    
    .date-range-item .date-picker-wrapper,
    .date-range-item .date-field,
    .date-range-item .calendar-wrapper {
        width: 100% !important;
        min-width: 100% !important;
    }
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
        min-width: 1000px;
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
    
    <!-- Purchase Success Notification -->
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
            <h1>Stock In - Out</h1>
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
            <button class="btn-date btn-export" onclick="openExportDateRangeModal()"><i class="fas fa-download"></i> Export</button>
        </div>
    </div>

    <!-- Daily Tracker Tab -->
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
                </tr>
            </thead>
            <tbody>
                <?php if ($daily_movements && $daily_movements->num_rows > 0): ?>
                    <?php while($movement = $daily_movements->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong></td>
                        <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?></td>
                     <td>
    <?php 
    $category = isset($movement['category']) ? trim($movement['category']) : '';
    if (!empty($category) && $category !== '0'): 
        echo htmlspecialchars($category);
    else: 
        echo '—';
    endif; 
    ?>
</td>
                        <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?></td>
                        <td><?php echo date('M d, Y', strtotime($movement['created_at'])); ?></td>
                        <td class="<?php echo $movement['type'] == 'in' ? 'stock-in' : 'stock-out'; ?>"><?php echo $movement['type'] == 'in' ? '+' : '-'; ?><?php echo number_format($movement['quantity']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No stock movements found for this date: <?php echo date('M d, Y', strtotime($selected_date)); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer-stats"><div><i class="fas fa-boxes" style="color: #75e6da;"></i> Total Items: <strong style="color: #75e6da;"><?php echo ($daily_movements) ? $daily_movements->num_rows : 0; ?></strong></div><div><i class="fas fa-calendar" style="color: #6c5ce7;"></i> Date: <strong style="color: #6c5ce7;"><?php echo date('F d, Y', strtotime($selected_date)); ?></strong></div></div>
    </div>
</div>

<!-- STOCK OUT MODAL -->
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
                
                <!-- PULL-OUT DATE - FIRST FIELD -->
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Pull-out Date *</label>
                    <input type="date" name="pullout_date" id="pulloutDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    <small style="color: var(--text-secondary); font-size: 11px; margin-top: 5px; display: block;">
                        <i class="fas fa-info-circle"></i> Select the date when item was pulled out. Stock availability will be based on this date.
                    </small>
                </div>
                
                <hr style="margin: 15px 0; border: 1px dashed var(--border-color); opacity: 0.5;">
                
                <!-- ITEM SEARCH - SECOND FIELD -->
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

                <hr style="margin: 8px 0 12px; border: 1px dashed var(--border-color); opacity: 0.5;">

                <!-- QUANTITY AND AVAILABLE STOCK - TWO COLUMNS -->
                <div class="two-column">
                    <div class="form-group">
                        <label><i class="fas fa-cubes"></i> Quantity to Pull Out *</label>
                        <input type="number" name="quantity" id="stockOutQuantity" min="1" required placeholder="Enter quantity" onkeyup="updateAvailableStock()" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-boxes"></i> Available Stock</label>
                        <input type="text" id="availableStock" readonly disabled class="form-control" value="0">
                    </div>
                </div>
                
                <div id="quantityWarningContainer"></div>
                
                <!-- SITE / LOCATION -->
                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Site / Location</label>
                    <div class="site-input-group">
                        <input type="text" name="site_location" id="siteLocation" class="form-control" placeholder="Enter site/location where item will be deployed" autocomplete="off">
                    </div>
                </div>
                
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
                
                <!-- REFERENCE -->
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Reference (Optional)</label>
                    <input type="text" name="reference" class="form-control" placeholder="e.g., SO-2024-001">
                </div>
                
                <!-- NOTES -->
                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStockOutModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-danger" id="submitStockOutBtn"><i class="fas fa-save"></i> Record Stock Out</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW STOCK OUT MODAL -->
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
                <input type="text" id="stockOutSearch" placeholder="Search by Item No, Description, Category, Site..." onkeyup="searchStockOut()">
            </div>
            
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
                            <th>Site / Location</th>
                        </tr>
                    </thead>
                    <tbody id="stockOutTableBody">
                        <?php if ($all_stock_out && $all_stock_out->num_rows > 0): ?>
                            <?php while($movement = $all_stock_out->fetch_assoc()): ?>
                            <tr class="stock-out-row">
                                <td><strong><?php echo htmlspecialchars($movement['item_no'] ?: 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($movement['description'] ?: 'N/A'); ?></td>
                               <td>
    <?php 
    $category = isset($movement['category']) ? trim($movement['category']) : '';
    if (!empty($category) && $category !== '0'): 
        echo htmlspecialchars($category);
    else: 
        echo '—';
    endif; 
    ?>
</td>
                                <td><?php echo htmlspecialchars($movement['unit'] ?: 'pcs'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($movement['created_at'])); ?></td>
                                <td class="stock-out">-<?php echo number_format($movement['quantity']); ?></td>
                                <td>
                                    <?php if (!empty($movement['site_location'])): ?>
                                        <span class="site-badge"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($movement['site_location']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <td>
                                <td colspan="7" style="text-align: center; padding: 40px;">
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
            <button type="button" class="btn btn-primary" onclick="exportStockOutData()"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button type="button" class="btn btn-primary" onclick="printPulloutHistory()" style="background: linear-gradient(135deg, #e84393, #d63031);"><i class="fas fa-print"></i> Print PDF</button>
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
// Calendar state
let activeCalendar = null;
let calendarDates = {
    main: { currentDate: new Date(), selectedDate: '<?php echo $selected_date; ?>' },
    from: { currentDate: new Date(), selectedDate: '<?php echo $date_from; ?>' },
    to: { currentDate: new Date(), selectedDate: '<?php echo $date_to; ?>' },
    exportFrom: { currentDate: new Date(), selectedDate: '<?php echo date('Y-m-d', strtotime('-30 days')); ?>' },
    exportTo: { currentDate: new Date(), selectedDate: '<?php echo date('Y-m-d'); ?>' }
};

// Inside DOMContentLoaded, add this:
const pulloutDateInput = document.getElementById('pulloutDate');
if (pulloutDateInput) {
    pulloutDateInput.addEventListener('change', function() {
        // Clear search and selected product when date changes
        document.getElementById('itemSearchInput').value = '';
        document.getElementById('selectedProductInfo').style.display = 'none';
        document.getElementById('searchResults').style.display = 'none';
        document.getElementById('selectedProductId').value = '';
        document.getElementById('availableStock').value = '0';
        document.getElementById('stockOutQuantity').value = '';
        document.getElementById('submitStockOutBtn').disabled = true;
        document.getElementById('quantityWarningContainer').innerHTML = '';
        
        // Reset quantity input styling
        const quantityInput = document.getElementById('stockOutQuantity');
        quantityInput.style.borderColor = '';
        quantityInput.style.boxShadow = '';
    });
}

function initializeYearSelects() {
    const yearSelects = ['mainYearSelect', 'fromYearSelect', 'toYearSelect', 'exportFromYearSelect', 'exportToYearSelect'];
    const currentYear = new Date().getFullYear();
    const startYear = 2000;
    const endYear = 2030;
    
    yearSelects.forEach(selectId => {
        const select = document.getElementById(selectId);
        if (select) {
            select.innerHTML = '';
            for (let year = startYear; year <= endYear; year++) {
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
function updateCalendar(calendarId) {
    const date = calendarDates[calendarId]?.currentDate || new Date();
    const year = date.getFullYear();
    const month = date.getMonth();
    
    const monthYearElement = document.getElementById(calendarId + 'MonthYear');
    if (monthYearElement) {
        monthYearElement.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    
    const monthSelect = document.getElementById(calendarId + 'MonthSelect');
    const yearSelect = document.getElementById(calendarId + 'YearSelect');
    
    if (monthSelect) {
        monthSelect.value = month;
    }
    
    if (yearSelect) {
        // Make sure the year option exists, if not add it
        let yearOptionExists = false;
        for (let i = 0; i < yearSelect.options.length; i++) {
            if (parseInt(yearSelect.options[i].value) === year) {
                yearOptionExists = true;
                break;
            }
        }
        
        if (!yearOptionExists) {
            // Add the missing year
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearSelect.appendChild(option);
        }
        
        yearSelect.value = year;
    }
    
    generateCalendarDays(calendarId);
}

function generateCalendarDays(calendarId) {
    const date = calendarDates[calendarId]?.currentDate || new Date();
    const year = date.getFullYear();
    const month = date.getMonth();
    const daysGrid = document.getElementById(calendarId + 'DaysGrid');
    
    if (!daysGrid) return;
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    const selectedDate = calendarDates[calendarId]?.selectedDate;
    
    let html = '';
    
    const prevMonthDays = new Date(year, month, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
    }
    
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
    
    const totalCells = 42;
    const cellsUsed = firstDay + daysInMonth;
    const nextMonthDays = totalCells - cellsUsed;
    for (let day = 1; day <= nextMonthDays; day++) {
        const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
    }
    
    daysGrid.innerHTML = html;
}

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

function navigateMonth(calendarId, direction) {
    const date = calendarDates[calendarId].currentDate;
    date.setMonth(date.getMonth() + direction);
    updateCalendar(calendarId);
}

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
        case 'exportFrom':
            fieldId = 'exportFromField';
            hiddenId = 'exportFrom';
            break;
        case 'exportTo':
            fieldId = 'exportToField';
            hiddenId = 'exportTo';
            break;
    }
    
    const field = document.getElementById(fieldId);
    const hidden = document.getElementById(hiddenId);
    
    if (field) field.value = formattedDisplay;
    if (hidden) hidden.value = dateStr;
    
    calendarDates[calendarId].selectedDate = dateStr;
    
    const calendar = document.getElementById(calendarId + 'Calendar');
    if (calendar) calendar.style.display = 'none';
    activeCalendar = null;
    
    updateCalendar(calendarId);
    
    if (calendarId === 'main') {
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        const month = monthNames[date.getMonth()];
        const day = date.getDate();
        const year = date.getFullYear();
        document.getElementById('currentDateDisplay').textContent = `${month} ${day}, ${year}`;
    }
}

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
        case 'exportFrom':
            fieldId = 'exportFromField';
            hiddenId = 'exportFrom';
            break;
        case 'exportTo':
            fieldId = 'exportToField';
            hiddenId = 'exportTo';
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

function setToday(calendarId) {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    
    calendarDates[calendarId].currentDate = new Date(dateStr);
    selectDate(calendarId, dateStr);
}

document.addEventListener('click', function(e) {
    const isCalendarClick = e.target.closest('.calendar-wrapper') || e.target.closest('.date-input-group');
    if (!isCalendarClick && activeCalendar) {
        const calendar = document.getElementById(activeCalendar + 'Calendar');
        if (calendar) calendar.style.display = 'none';
        activeCalendar = null;
    }
});
document.addEventListener('DOMContentLoaded', function() {
    initializeYearSelects();
    
    ['main', 'from', 'to'].forEach(calId => {
        updateCalendar(calId);
    });

    // Don't initialize export calendars here because the modal doesn't exist yet
    // They will be initialized when the modal opens

    const mainDate = '<?php echo $selected_date; ?>';
    const fromDate = '<?php echo $date_from; ?>';
    const toDate = '<?php echo $date_to; ?>';
    
    if (mainDate) {
        calendarDates.main.selectedDate = mainDate;
        const date = new Date(mainDate);
        document.getElementById('datePickerField').value = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        document.getElementById('currentDateDisplay').textContent = `${monthNames[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
    }
    
    if (fromDate) {
        calendarDates.from.selectedDate = fromDate;
        const date = new Date(fromDate);
        document.getElementById('dateFromField').value = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
    }
    
    if (toDate) {
        calendarDates.to.selectedDate = toDate;
        const date = new Date(toDate);
        document.getElementById('dateToField').value = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
    }
    
    setTimeout(() => {
        const alert = document.getElementById('purchaseSuccessAlert');
        if (alert) {
            alert.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        }
    }, 5000);
});

function searchItems() {
    const searchTerm = document.getElementById('itemSearchInput').value.trim();
    const resultsDiv = document.getElementById('searchResults');
    const pulloutDate = document.getElementById('pulloutDate').value;
    
    if (searchTerm.length < 1) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    if (!pulloutDate) {
        resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #d63031;"><i class="fas fa-exclamation-triangle"></i> Please select a pull-out date first</div>';
        resultsDiv.style.display = 'block';
        return;
    }
    
    resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    resultsDiv.style.display = 'block';
    
    // Pass the pull-out date to the backend
    fetch('search_products.php?term=' + encodeURIComponent(searchTerm) + '&date=' + encodeURIComponent(pulloutDate))
        .then(response => response.json())
        .then(data => {
            if (data.length > 0) {
                let html = '';
                data.forEach(product => {
                    let displayText = product.name;
                    if (product.item_no) {
                        let cleanName = product.name;
                        if (product.name.indexOf(product.item_no) === 0) {
                            let afterItemNo = product.name.substring(product.item_no.length).trim();
                            if (afterItemNo.startsWith('-')) afterItemNo = afterItemNo.substring(1).trim();
                            cleanName = afterItemNo;
                        }
                        if (cleanName.match(/^\d+\s*-\s*\d+/)) cleanName = cleanName.replace(/^\d+\s*-\s*\d+\s*-\s*/, '');
                        displayText = product.item_no + ' - ' + cleanName;
                    }
                    
                    const stockAvailable = product.quantity || 0;
                    const stockWarning = stockAvailable === 0 ? 'style="opacity: 0.6;"' : '';
                    const formattedDate = formatDisplayDate(pulloutDate);
                    
                    html += `
                        <div class="search-result-item" onclick="selectProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.item_no || ''}', ${stockAvailable}, '${product.unit || 'pcs'}')" style="cursor: pointer; padding: 12px 15px; border-bottom: 1px solid var(--border-color); transition: all 0.2s ease;" ${stockWarning}>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${displayText}</strong>
                                    <div style="font-size: 11px; color: var(--text-secondary); margin-top: 3px;">
                                        Stock as of ${formattedDate}: <strong style="color: ${stockAvailable > 0 ? '#00b894' : '#d63031'};">${stockAvailable}</strong> ${product.unit || 'pcs'}
                                    </div>
                                </div>
                                <div style="background: ${stockAvailable > 0 ? '#75e6da' : '#95a5a6'}; color: #1a1c3c; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;">
                                    <i class="fas fa-hand-pointer"></i> ${stockAvailable > 0 ? 'Select' : 'No Stock'}
                                </div>
                            </div>
                        </div>
                    `;
                });
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-secondary);"><i class="fas fa-info-circle"></i> No products found</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #d63031;"><i class="fas fa-exclamation-circle"></i> Error loading products</div>';
        });
}

// Helper function to format date for display
function formatDisplayDate(dateStr) {
    const date = new Date(dateStr);
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${monthNames[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
}

function selectProduct(id, name, itemNo, stock, unit) {
    let availableStock = 0;
    if (stock !== undefined && stock !== null) {
        availableStock = parseInt(stock);
        if (isNaN(availableStock)) availableStock = 0;
    }
    
    document.getElementById('selectedProductId').value = id;
    
    let displayValue = name;
    if (itemNo) {
        let cleanName = name;
        if (name.indexOf(itemNo) === 0) {
            let afterItemNo = name.substring(itemNo.length).trim();
            if (afterItemNo.startsWith('-')) afterItemNo = afterItemNo.substring(1).trim();
            cleanName = afterItemNo;
        }
        if (cleanName.match(/^\d+\s*-\s*\d+/)) cleanName = cleanName.replace(/^\d+\s*-\s*\d+\s*-\s*/, '');
        displayValue = itemNo + ' - ' + cleanName;
    }
    
    document.getElementById('itemSearchInput').value = displayValue;
    document.getElementById('selectedProductName').textContent = displayValue;
    document.getElementById('selectedProductDetails').textContent = `Unit: ${unit}`;
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
        container.innerHTML = `<div class="warning-message"><i class="fas fa-exclamation-circle"></i> ERROR: Insufficient stock! Available: ${available}</div>`;
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

function validateStockOut() {
    const productId = document.getElementById('selectedProductId').value;
    const quantity = parseInt(document.getElementById('stockOutQuantity').value);
    const available = parseInt(document.getElementById('availableStock').value);
    const pulloutDate = document.getElementById('pulloutDate').value;
    
    if (!productId) { alert('Please select a product first'); return false; }
    if (isNaN(quantity) || quantity <= 0) { alert('Please enter a valid quantity'); return false; }
    if (quantity > available) { alert('Error: Insufficient stock! Available: ' + available); return false; }
    if (!pulloutDate) { alert('Please select the pull-out date'); return false; }
    return true;
}

function selectQuickSite() {
    const select = document.getElementById('quickSiteSelect');
    const siteInput = document.getElementById('siteLocation');
    if (select.value) siteInput.value = select.value;
}

function filterByDateRange() {
    const fromDate = document.getElementById('dateFrom').value;
    const toDate = document.getElementById('dateTo').value;
    if (!fromDate || !toDate) { alert('Please select both from and to dates'); return; }
    window.location.href = 'stock_tracker.php?from=' + fromDate + '&to=' + toDate + '#viewStockOutModal';
    setTimeout(() => openViewStockOutModal(), 100);
}

function resetDateRange() {
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    window.location.href = 'stock_tracker.php?from=' + thirtyDaysAgo.toISOString().split('T')[0] + '&to=' + today.toISOString().split('T')[0] + '#viewStockOutModal';
    setTimeout(() => openViewStockOutModal(), 100);
}

function searchStockOut() {
    const searchTerm = document.getElementById('stockOutSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#stockOutTableBody .stock-out-row');
    let visibleCount = 0;
    rows.forEach(row => {
        const cells = row.cells;
        let rowText = '';
        for(let i = 0; i < cells.length; i++) rowText += cells[i]?.textContent.toLowerCase() + ' ';
        if (searchTerm === '') { row.style.display = ''; visibleCount++; }
        else if (rowText.includes(searchTerm)) { row.style.display = ''; visibleCount++; }
        else { row.style.display = 'none'; }
    });
    document.getElementById('stockOutCount').textContent = visibleCount;
}

function closeStockOutModal() {
    document.getElementById('stockOutModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function closeViewStockOutModal() {
    document.getElementById('viewStockOutModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function changeDate(days) {
    const currentDateStr = document.getElementById('datePicker').value;
    const currentDate = new Date(currentDateStr || new Date());
    currentDate.setDate(currentDate.getDate() + days);
    const newDateStr = currentDate.toISOString().split('T')[0];
    const formattedDisplay = currentDate.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
    document.getElementById('datePickerField').value = formattedDisplay;
    document.getElementById('datePicker').value = newDateStr;
    calendarDates.main.selectedDate = newDateStr;
    updateCalendar('main');
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    document.getElementById('currentDateDisplay').textContent = `${monthNames[currentDate.getMonth()]} ${currentDate.getDate()}, ${currentDate.getFullYear()}`;
    window.location.href = 'stock_tracker.php?date=' + newDateStr;
}

function goToToday() {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    window.location.href = 'stock_tracker.php?date=' + todayStr;
}

function updatePageDate(dateStr) {
    document.getElementById('datePicker').value = dateStr;
    const displayDate = new Date(dateStr);
    document.getElementById('datePickerField').value = displayDate.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    document.getElementById('currentDateDisplay').textContent = `${monthNames[displayDate.getMonth()]} ${displayDate.getDate()}, ${displayDate.getFullYear()}`;
    calendarDates.main.selectedDate = dateStr;
    updateCalendar('main');
    window.location.href = 'stock_tracker.php?date=' + dateStr;
}

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

// ========== EXPORT FUNCTIONS ==========

function openExportDateRangeModal() {
    const modalHtml = `
        <div id="exportDateRangeModal" class="modal" style="display: block; z-index: 10001;">
            <div class="modal-content" style="max-width: 700px; width: 90%;">
                <div class="modal-header" style="background: linear-gradient(135deg, #75e6da, #5fd9d0); padding: 20px 25px;">
                    <h2><i class="fas fa-calendar-alt"></i> Export Stock Movements</h2>
                    <button class="close-btn" onclick="closeExportDateRangeModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding: 30px; min-height: 350px;">
                    <div style="display: flex; gap: 30px; align-items: flex-start; flex-wrap: wrap;">
                        <!-- From Date Column -->
                        <div style="flex: 1; min-width: 250px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 14px; margin-bottom: 10px;"><i class="fas fa-calendar-alt" style="color: #75e6da; margin-right: 8px;"></i> From Date</label>
                                <div class="date-picker-wrapper" style="position: relative;">
                                    <div class="date-input-group">
                                        <input type="text" id="exportFromField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('exportFrom')" style="cursor: pointer; width: 100%; padding: 12px 15px; font-size: 14px; height: 45px;">
                                        <input type="hidden" id="exportFrom" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('exportFrom')" style="font-size: 16px;"><i class="fas fa-chevron-down"></i></button>
                                    </div>
                                    <div class="calendar-wrapper" id="exportFromCalendar" style="position: absolute; top: 100%; left: 0; right: auto; transform: none; width: 300px; z-index: 10002;">
                                        <div class="calendar-box">
                                            <div class="calendar-header"><div class="calendar-month-year" id="exportFromMonthYear"></div><div class="calendar-nav"><button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportFrom', -1)">‹</button><button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportFrom', 1)">›</button></div></div>
                                            <div class="calendar-selectors"><select id="exportFromMonthSelect" class="calendar-select" onchange="changeMonthYear('exportFrom')"><option value="0">January</option><option value="1">February</option><option value="2">March</option><option value="3">April</option><option value="4">May</option><option value="5">June</option><option value="6">July</option><option value="7">August</option><option value="8">September</option><option value="9">October</option><option value="10">November</option><option value="11">December</option></select><select id="exportFromYearSelect" class="calendar-select" onchange="changeMonthYear('exportFrom')"></select></div>
                                            <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                            <div class="calendar-days-grid" id="exportFromDaysGrid"></div>
                                            <div class="calendar-footer"><button type="button" class="calendar-action-btn clear" onclick="clearDate('exportFrom')"><i class="fas fa-times"></i> Clear</button><button type="button" class="calendar-action-btn today" onclick="setToday('exportFrom')"><i class="fas fa-calendar-check"></i> Today</button></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- To Date Column -->
                        <div style="flex: 1; min-width: 250px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 14px; margin-bottom: 10px;"><i class="fas fa-calendar-alt" style="color: #75e6da; margin-right: 8px;"></i> To Date</label>
                                <div class="date-picker-wrapper" style="position: relative;">
                                    <div class="date-input-group">
                                        <input type="text" id="exportToField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('exportTo')" style="cursor: pointer; width: 100%; padding: 12px 15px; font-size: 14px; height: 45px;">
                                        <input type="hidden" id="exportTo" value="<?php echo date('Y-m-d'); ?>">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('exportTo')" style="font-size: 16px;"><i class="fas fa-chevron-down"></i></button>
                                    </div>
                                    <div class="calendar-wrapper" id="exportToCalendar" style="position: absolute; top: 100%; left: 0; right: auto; transform: none; width: 300px; z-index: 10002;">
                                        <div class="calendar-box">
                                            <div class="calendar-header"><div class="calendar-month-year" id="exportToMonthYear"></div><div class="calendar-nav"><button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportTo', -1)">‹</button><button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportTo', 1)">›</button></div></div>
                                            <div class="calendar-selectors"><select id="exportToMonthSelect" class="calendar-select" onchange="changeMonthYear('exportTo')"><option value="0">January</option><option value="1">February</option><option value="2">March</option><option value="3">April</option><option value="4">May</option><option value="5">June</option><option value="6">July</option><option value="7">August</option><option value="8">September</option><option value="9">October</option><option value="10">November</option><option value="11">December</option></select><select id="exportToYearSelect" class="calendar-select" onchange="changeMonthYear('exportTo')"></select></div>
                                            <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                            <div class="calendar-days-grid" id="exportToDaysGrid"></div>
                                            <div class="calendar-footer"><button type="button" class="calendar-action-btn clear" onclick="clearDate('exportTo')"><i class="fas fa-times"></i> Clear</button><button type="button" class="calendar-action-btn today" onclick="setToday('exportTo')"><i class="fas fa-calendar-check"></i> Today</button></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 20px 25px;">
                    <button type="button" class="btn btn-secondary" onclick="closeExportDateRangeModal()" style="padding: 10px 20px; font-size: 14px;"><i class="fas fa-times"></i> Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="exportWithDateRange()" style="background: linear-gradient(135deg, #75e6da, #5fd9d0); padding: 10px 25px; font-size: 14px;"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
        </div>
    `;
    
    const existingModal = document.getElementById('exportDateRangeModal');
    if (existingModal) existingModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.body.style.overflow = 'hidden';
    
    // Initialize year selects for export calendars after modal is added to DOM
    setTimeout(() => {
        // Populate year selects for export calendars
        const exportFromYearSelect = document.getElementById('exportFromYearSelect');
        const exportToYearSelect = document.getElementById('exportToYearSelect');
        const currentYear = new Date().getFullYear();
        const startYear = 2000;
        const endYear = 2030;
        
        if (exportFromYearSelect) {
            exportFromYearSelect.innerHTML = '';
            for (let year = startYear; year <= endYear; year++) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                if (year === currentYear) {
                    option.selected = true;
                }
                exportFromYearSelect.appendChild(option);
            }
        }
        
        if (exportToYearSelect) {
            exportToYearSelect.innerHTML = '';
            for (let year = startYear; year <= endYear; year++) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                if (year === currentYear) {
                    option.selected = true;
                }
                exportToYearSelect.appendChild(option);
            }
        }
        
        // Initialize calendar dates for export
        const fromDate = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
        const toDate = '<?php echo date('Y-m-d'); ?>';
        
        if (fromDate) {
            calendarDates.exportFrom.selectedDate = fromDate;
            calendarDates.exportFrom.currentDate = new Date(fromDate);
            const date = new Date(fromDate);
            const fromField = document.getElementById('exportFromField');
            if (fromField) {
                fromField.value = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
            }
        }
        
        if (toDate) {
            calendarDates.exportTo.selectedDate = toDate;
            calendarDates.exportTo.currentDate = new Date(toDate);
            const date = new Date(toDate);
            const toField = document.getElementById('exportToField');
            if (toField) {
                toField.value = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
            }
        }
        
        // Update calendars
        updateCalendar('exportFrom');
        updateCalendar('exportTo');
        
        // Position calendars to prevent overlap
        const fromCalendar = document.getElementById('exportFromCalendar');
        const toCalendar = document.getElementById('exportToCalendar');
        
        if (fromCalendar) {
            fromCalendar.style.position = 'absolute';
            fromCalendar.style.top = '100%';
            fromCalendar.style.left = '0';
            fromCalendar.style.right = 'auto';
            fromCalendar.style.transform = 'none';
        }
        
        if (toCalendar) {
            toCalendar.style.position = 'absolute';
            toCalendar.style.top = '100%';
            toCalendar.style.left = '0';
            toCalendar.style.right = 'auto';
            toCalendar.style.transform = 'none';
        }
    }, 50);
}

function closeExportDateRangeModal() {
    const modal = document.getElementById('exportDateRangeModal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            modal.remove();
            document.body.style.overflow = 'auto';
        }, 200);
    }
}

function exportWithDateRange() {
    const fromDate = document.getElementById('exportFrom')?.value;
    const toDate = document.getElementById('exportTo')?.value;
    
    if (!fromDate || !toDate) {
        showCustomNotification('Please select both from and to dates', 'error');
        return;
    }
    
    closeExportDateRangeModal();
    
    showCustomNotification('Generating report...', 'info');
    
    window.location.href = `export_stock_range.php?from=${fromDate}&to=${toDate}&type=detailed`;
}

function exportStockOutData() {
    // Get the date range values
    const fromDateField = document.getElementById('dateFromField');
    const toDateField = document.getElementById('dateToField');
    const fromDate = fromDateField ? fromDateField.value : '';
    const toDate = toDateField ? toDateField.value : '';
    
    // Get data from the pull-out history table
    const tbody = document.getElementById('stockOutTableBody');
    const rows = tbody.querySelectorAll('.stock-out-row');
    
    // Filter visible rows only
    const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
    
    if (visibleRows.length === 0) {
        showCustomNotification('No data to export', 'error');
        return;
    }
    
    // Create CSV content with title and date range headers
    let csv = '';
    
    // Add title
    csv += '"PULL-OUT HISTORY REPORT"\n';
    csv += '\n';
    
    // Add date range information
    csv += `"From Date:","${fromDate}"\n`;
    csv += `"To Date:","${toDate}"\n`;
    csv += `"Export Date:","${new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })}"\n`;
    csv += `"Total Records:","${visibleRows.length}"\n`;
    csv += '\n';
    
    // Add column headers (7 columns including Site/Location)
  csv += 'Item No,Description,Category,Unit,Date,Quantity,Site / Location\n';
    
    visibleRows.forEach(row => {
        const cells = row.cells;
        if (cells.length >= 7) {
            const itemNo = cells[0]?.textContent.trim() || '';
            const description = cells[1]?.textContent.trim() || '';
            
            // Category - get plain text without badge styling
            let category = cells[2]?.textContent.trim() || '';
            if (category === '—' || category === '-') category = '';
            
            const unit = cells[3]?.textContent.trim() || '';
            const dateTime = cells[4]?.textContent.trim() || '';
            
            // Quantity - keep the negative sign and number
            let quantity = cells[5]?.textContent.trim() || '0';
            
            // Site/Location - get text without badge styling
            let siteLocation = cells[6]?.textContent.trim() || '';
            if (siteLocation === '—' || siteLocation === '-') siteLocation = '';
            
            csv += `"${itemNo}","${description}","${category}","${unit}","${dateTime}","${quantity}","${siteLocation}"\n`;
        }
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    const url = URL.createObjectURL(blob);
    a.href = url;
    
    // Create filename with date range
    const filename = `pullout_history_${fromDate.replace(/\//g, '-')}_to_${toDate.replace(/\//g, '-')}.csv`;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    showCustomNotification('Pull-out History exported successfully!', 'success');
}
function showCustomNotification(message, type = 'success') {
    const existing = document.querySelector('.custom-notification');
    if (existing) existing.remove();
    
    const notification = document.createElement('div');
    notification.className = 'custom-notification';
    notification.style.cssText = `
        position: fixed; top: 20px; right: 20px; z-index: 99999; padding: 15px 25px;
        background: ${type === 'success' ? 'linear-gradient(135deg, #75e6da, #5fd9d0)' : (type === 'error' ? '#d63031' : '#3498db')};
        color: ${type === 'success' ? '#1a1c3c' : 'white'}; border-radius: 8px;
        box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3); animation: slideInRight 0.3s ease;
        max-width: 400px; font-weight: 600; display: flex; align-items: center; gap: 10px;
    `;
    notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle')}" style="font-size: 20px;"></i><span>${message}</span>`;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function printPulloutHistory() {
    const fromDate = document.getElementById('dateFromField')?.value || '';
    const toDate = document.getElementById('dateToField')?.value || '';
    
    const visibleRows = Array.from(document.querySelectorAll('#stockOutTableBody .stock-out-row')).filter(row => row.style.display !== 'none');
    
    if (visibleRows.length === 0) {
        showCustomNotification('No data to print', 'error');
        return;
    }
    
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Pull-out History Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; padding: 20px; }
                h2 { color: #333; border-bottom: 2px solid #75e6da; padding-bottom: 10px; }
                .report-header { margin-bottom: 20px; }
                .report-header p { margin: 5px 0; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: linear-gradient(135deg, #75e6da, #62d4c8); color: white; padding: 10px; text-align: left; border: 1px solid #ddd; }
                td { padding: 8px; border: 1px solid #ddd; text-align: left; }
                .stock-out { color: #d63031; font-weight: bold; }
                .category-badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
                .site-badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; background: linear-gradient(135deg, #00b894, #75e6da); color: #1a1c3c; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #ddd; padding-top: 20px; }
                @media print { body { margin: 0; padding: 10px; } }
            </style>
        </head>
        <body>
            <div class="report-header">
                <h2>Pull-out History Report</h2>
                <p><strong>Date Range:</strong> ${fromDate} - ${toDate}</p>
                <p><strong>Generated on:</strong> ${new Date().toLocaleString()}</p>
                <p><strong>Total Records:</strong> ${visibleRows.length}</p>
            </div>
            <table>
                <thead>
                    <tr><th>Item No</th><th>Description</th><th>Category</th><th>Unit</th><th>Date</th><th>Quantity</th><th>Site / Location</th></tr>
                </thead>
                <tbody>
                    ${visibleRows.map(row => {
                        const cells = row.cells;
                        return `<tr>
                            <td>${cells[0]?.textContent.trim() || 'N/A'}</td>
                            <td>${cells[1]?.textContent.trim() || 'N/A'}</td>
                            <td>${cells[2]?.innerHTML || '—'}</td>
                            <td>${cells[3]?.textContent.trim() || 'pcs'}</td>
                            <td>${cells[4]?.textContent.trim() || 'N/A'}</td>
                            <td class="stock-out">${cells[5]?.textContent.trim() || '0'}</td>
                            <td>${cells[6]?.textContent.trim() || '—'}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
            <div class="footer"><p>This is a system-generated report from Stock Tracker</p></div>
            <script>window.onload = function() { window.print(); setTimeout(function() { window.close(); }, 500); };<\/script>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
}

window.onclick = function(event) {
    if (event.target == document.getElementById('stockOutModal')) closeStockOutModal();
    if (event.target == document.getElementById('viewStockOutModal')) closeViewStockOutModal();
};

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.style.display = 'none', 300);
    });
}, 3000);

if (window.location.hash === '#viewStockOutModal') {
    setTimeout(() => openViewStockOutModal(), 100);
}
</script>

<?php require_once 'include/footer.php'; ?>