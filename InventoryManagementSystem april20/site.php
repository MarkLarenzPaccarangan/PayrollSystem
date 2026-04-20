<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once __DIR__ . '/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$host = DB_HOST;
$username = DB_USER;
$password = DB_PASS;
$database = DB_NAME;

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create deduction_history table if not exists (with remarks)
$create_history_table = "CREATE TABLE IF NOT EXISTS deduction_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    site_name VARCHAR(255) NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255),
    item_no VARCHAR(100),
    quantity_deducted INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reference VARCHAR(100),
    notes TEXT,
    remarks TEXT,
    deducted_by INT,
    deducted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_deducted_at (deducted_at),
    INDEX idx_site_id (site_id)
)";
$conn->query($create_history_table);

// Add remarks column if not exists (for existing tables)
$check_column_sql = "SHOW COLUMNS FROM deduction_history LIKE 'remarks'";
$check_result = $conn->query($check_column_sql);
if ($check_result && $check_result->num_rows == 0) {
    $add_remarks_column = "ALTER TABLE deduction_history ADD COLUMN remarks TEXT AFTER notes";
    $conn->query($add_remarks_column);
}

// Create sites table if not exists
$create_sites_table = "CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(255) NOT NULL UNIQUE,
    location_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($create_sites_table);

// Get current user
$current_user = getCurrentUser();

// Handle form submissions for site management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new site
    if (isset($_POST['add_site'])) {
        $site_name = $conn->real_escape_string($_POST['site_name']);
        $location_description = $conn->real_escape_string($_POST['location_description']);
        
        $check_sql = "SELECT id FROM sites WHERE site_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $site_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['toast'] = ['message' => 'Site already exists!', 'type' => 'error'];
        } else {
            $insert_sql = "INSERT INTO sites (site_name, location_description) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ss", $site_name, $location_description);
            
            if ($insert_stmt->execute()) {
                $_SESSION['toast'] = ['message' => 'Site added successfully!', 'type' => 'success'];
            } else {
                $_SESSION['toast'] = ['message' => 'Error adding site: ' . $conn->error, 'type' => 'error'];
            }
        }
        
        header("Location: site.php");
        exit();
    }
    
    // Edit site
    if (isset($_POST['edit_site'])) {
        $site_id = intval($_POST['site_id']);
        $site_name = $conn->real_escape_string($_POST['site_name']);
        $location_description = $conn->real_escape_string($_POST['location_description']);
        
        $update_sql = "UPDATE sites SET site_name = ?, location_description = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssi", $site_name, $location_description, $site_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['toast'] = ['message' => 'Site updated successfully!', 'type' => 'success'];
        } else {
            $_SESSION['toast'] = ['message' => 'Error updating site: ' . $conn->error, 'type' => 'error'];
        }
        
        header("Location: site.php");
        exit();
    }
    
    // Delete site
    if (isset($_POST['delete_site'])) {
        $site_id = intval($_POST['site_id']);
        
        $delete_sql = "DELETE FROM sites WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $site_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['toast'] = ['message' => 'Site deleted successfully!', 'type' => 'success'];
        } else {
            $_SESSION['toast'] = ['message' => 'Error deleting site: ' . $conn->error, 'type' => 'error'];
        }
        
        header("Location: site.php");
        exit();
    }
}

// Get filter parameters from URL
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$single_date = isset($_GET['date']) ? $_GET['date'] : '';
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';

// Get sites
$sites_sql = "SELECT id, site_name, location_description, created_at FROM sites ORDER BY site_name";

if (!empty($site_filter)) {
    $sites_sql = "SELECT id, site_name, location_description, created_at FROM sites WHERE site_name = ? ORDER BY site_name";
    $sites_stmt = $conn->prepare($sites_sql);
    $sites_stmt->bind_param("s", $site_filter);
    $sites_stmt->execute();
    $sites_result = $sites_stmt->get_result();
} else {
    $sites_result = $conn->query($sites_sql);
}

$sites = [];
$site_names = [];
if ($sites_result) {
    while ($row = $sites_result->fetch_assoc()) {
        $sites[] = $row;
        $site_names[] = $row['site_name'];
    }
}

if (isset($sites_stmt)) {
    $sites_stmt->close();
}

// Get selected site for viewing deployed items
$selected_site = isset($_GET['view_site']) ? $_GET['view_site'] : '';
$deployed_items = [];
$selected_site_details = null;

if ($selected_site) {
    $site_detail_sql = "SELECT * FROM sites WHERE site_name = ?";
    $site_detail_stmt = $conn->prepare($site_detail_sql);
    $site_detail_stmt->bind_param("s", $selected_site);
    $site_detail_stmt->execute();
    $site_detail_result = $site_detail_stmt->get_result();
    $selected_site_details = $site_detail_result->fetch_assoc();
    
    $deployed_sql = "SELECT 
                        sm.id,
                        sm.created_at,
                        sm.reference,
                        sm.quantity,
                        sm.notes,
                        p.id as product_id,
                        p.name as product_name,
                        p.item_no,
                        p.description,
                        p.category,
                        p.unit
                    FROM stock_movements sm
                    LEFT JOIN products p ON sm.product_id = p.id
                    WHERE sm.type = 'out' 
                    AND sm.site_location = ?
                    ORDER BY sm.created_at DESC";
    $deployed_stmt = $conn->prepare($deployed_sql);
    $deployed_stmt->bind_param("s", $selected_site);
    $deployed_stmt->execute();
    $deployed_items = $deployed_stmt->get_result();
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_pullouts,
                SUM(quantity) as total_quantity
              FROM stock_movements 
              WHERE type = 'out'";
$params = [];
$types = "";

if (!empty($single_date)) {
    $stats_sql .= " AND DATE(created_at) = ?";
    $params[] = $single_date;
    $types .= "s";
}

if (!empty($site_filter)) {
    $stats_sql .= " AND site_location = ?";
    $params[] = $site_filter;
    $types .= "s";
}

if (!empty($params)) {
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->bind_param($types, ...$params);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
    } else {
        $stats = ['total_pullouts' => 0, 'total_quantity' => 0];
    }
} else {
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result->fetch_assoc();
}
$total_pullouts = $stats['total_pullouts'] ?? 0;
$total_quantity = $stats['total_quantity'] ?? 0;

// Get deduction history statistics
$total_deductions_sql = "SELECT COUNT(*) as total, SUM(quantity_deducted) as total_qty FROM deduction_history";
$total_deductions_result = $conn->query($total_deductions_sql);
$total_deductions = $total_deductions_result->fetch_assoc();
$total_deduction_count = $total_deductions['total'] ?? 0;
$total_deducted_qty = $total_deductions['total_qty'] ?? 0;

// Include header
require_once 'include/header.php';
?>

<style>
/* Site Page Styles - Enhanced with Dark Mode Support */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* Dark Mode Variables */
:root {
    --bg-primary: #272757;
    --bg-secondary: #2e2e6a;
    --text-primary: #ffffff;
    --text-secondary: #a0a0a0;
    --border-color: #2d2d44;
    --card-bg: #181840;
    --shadow: 0 4px 12px rgba(0,0,0,0.3);
    --hover-bg: #1e1e3a;
    --accent-color: #75e6da;
    --accent-gradient: linear-gradient(135deg, #75e6da, #62d4c8);
    --danger-color: #e74c3c;
    --warning-color: #e67e22;
    --info-color: #3498db;
    --success-color: #2E7D32;
}

/* Light Theme */
body.light-theme {
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --border-color: #e9ecef;
    --card-bg: #ffffff;
    --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --hover-bg: #f8fafc;
}

html, body {
    margin: 0;
    padding: 0;
    border: none;
    background-color: var(--bg-primary) !important;
}

.content {
    display: block;
    height: auto;
    overflow: visible;
    background-color: var(--bg-primary) !important;
}

.main-content {
    padding: 20px 40px 0 40px;
    width: 100%;
    background-color: var(--bg-primary) !important;
    color: var(--text-primary);
    position: relative;
    display: flex;
    justify-content: center;
    transition: background-color 0.3s ease, color 0.3s ease;
    overflow: visible !important;
    height: auto !important;
    margin: 0 !important;
    border: none !important;
    box-shadow: none !important;
}

.dashboard-container {
    overflow: visible;
    width: 100%;
}

/* Dashboard Header */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    background: var(--card-bg);
    padding: 20px 30px;
    border-radius: 16px;
    box-shadow: var(--shadow);
    width: 100%;
    border: 1px solid var(--border-color);
}

.dashboard-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.dashboard-title i {
    color: var(--accent-color);
    font-size: 26px;
}

/* Header Buttons */
.header-buttons {
    display: flex;
    gap: 12px;
}

.btn-history {
    background: linear-gradient(135deg, #9b59b6, #8e44ad);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 30px;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-history:hover {
    background: linear-gradient(135deg, #8e44ad, #7d3c98);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(155,89,182,0.3);
}

.btn-add-site {
    background: var(--success-color);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 30px;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-add-site:hover {
    background: #1B5E20;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(46,125,50,0.3);
}

/* Statistics Cards */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
    width: 100%;
}

.stat-card {
    background: var(--card-bg);
    padding: 20px;
    border-radius: 16px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    border: 1px solid var(--border-color);
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: var(--accent-color);
}

.stat-info h3 {
    margin: 0;
    font-size: 14px;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.stat-info .number {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 6px;
    line-height: 1;
}

.stat-icon {
    font-size: 42px;
    color: var(--accent-color);
    opacity: 0.8;
    background: transparent !important;
    box-shadow: none !important;
    border: none !important;
}

/* Filter Section */
.filter-section {
    background: var(--card-bg);
    border-radius: 16px;
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 25px;
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
    border: 1px solid var(--border-color);
}

.filter-group {
    flex: 1;
    min-width: 180px;
}

.filter-group label {
    display: block;
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 5px;
    font-weight: 600;
}

/* Date Picker Styles */
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
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
    font-weight: 500;
    height: 42px;
}

.date-field:hover {
    border-color: var(--accent-color);
}

.date-field:focus {
    border-color: var(--accent-color);
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
    color: var(--accent-color);
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

/* COMPACT CALENDAR STYLES - SUPER COMPACT FOR DEDUCTION HISTORY */
.calendar-wrapper {
    position: absolute;
    top: calc(100% + 5px);
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: 240px !important;
    min-width: 240px;
    right: auto !important;
    background: var(--card-bg);
    border: 2px solid var(--accent-color);
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    z-index: 2000;
    display: none;
}

.calendar-wrapper.show {
    display: block;
}

.calendar-box {
    width: 100%;
    background: var(--card-bg);
    border-radius: 8px;
    overflow: hidden;
}

.calendar-header {
    background: var(--accent-gradient);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 3px 5px;
}

.calendar-month-year {
    font-size: 0.6rem;
    font-weight: 600;
}

.calendar-nav {
    display: flex;
    gap: 3px;
}

.calendar-nav-btn {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 0.7rem;
}

.calendar-nav-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}

.calendar-selectors {
    display: flex;
    gap: 3px;
    padding: 4px 4px 2px 4px;
    background: var(--card-bg);
}

.calendar-select {
    flex: 1;
    border: 1px solid var(--border-color);
    border-radius: 3px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-primary);
    cursor: pointer;
    transition: all 0.3s;
    outline: none;
    padding: 1px 2px;
    font-size: 0.5rem;
    height: 18px;
}

.calendar-select:hover {
    border-color: var(--accent-color);
}

.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--bg-secondary);
    text-align: center;
    font-weight: 600;
    color: var(--text-primary);
    padding: 2px 1px;
    font-size: 0.5rem;
}

.calendar-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    padding: 3px 2px;
    background: var(--card-bg);
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
    padding: 1px;
    font-size: 0.55rem;
}

.calendar-day:hover {
    background: var(--accent-color);
    color: white;
}

.calendar-day.selected {
    background: var(--accent-color);
    color: white;
    font-weight: 600;
}

.calendar-day.today {
    border: 1px solid var(--accent-color);
    font-weight: 600;
}

.calendar-day.weekend {
    color: #e74c3c;
}

.calendar-day.other-month {
    color: var(--text-secondary);
    opacity: 0.4;
}

.calendar-footer {
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    padding: 2px 4px;
}

.calendar-action-btn {
    border-radius: 3px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 2px;
    border: none;
    padding: 2px 4px;
    font-size: 0.5rem;
}

.calendar-action-btn.clear {
    background: var(--bg-primary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.calendar-action-btn.clear:hover {
    background: var(--danger-color);
    color: white;
    border-color: var(--danger-color);
}

.calendar-action-btn.today {
    background: var(--accent-color);
    color: white;
    border: 1px solid var(--accent-color);
}

.calendar-action-btn.today:hover {
    background: #62d4c8;
}
.filter-group select {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
}

.filter-group input {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

.btn-filter {
    padding: 10px 20px;
    background: var(--success-color);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    height: 42px;
}

.btn-filter:hover {
    background: #1B5E20;
    transform: translateY(-2px);
}

.btn-reset {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.btn-reset:hover {
    background: var(--border-color);
}

/* Sites Container */
.sites-container {
    background: var(--card-bg);
    border-radius: 16px;
    box-shadow: var(--shadow);
    padding: 25px;
    margin-top: 20px;
    width: 100%;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
    overflow: visible;
}

.sites-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border-color);
    flex-wrap: wrap;
    gap: 15px;
}

.sites-header h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.sites-header h2 i {
    color: var(--accent-color);
}

.sites-list {
    overflow: visible;
    max-height: none;
}

.site-item {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    transition: all 0.3s;
}

.site-item:hover {
    background: var(--card-bg);
    border-color: var(--accent-color);
    box-shadow: var(--shadow);
    transform: translateX(3px);
}

.site-info {
    flex: 1;
}

.site-info h4 {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.site-info h4 i {
    color: var(--accent-color);
    font-size: 14px;
}

.site-info p {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 4px 0;
}

.site-info .site-date {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 6px;
}

.site-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* Action Buttons */
.action-btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    min-width: 85px;
    justify-content: center;
}

.action-btn i {
    font-size: 14px;
}

.btn-view {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    border: 1px solid #2980b9;
}

.btn-view:hover {
    background: linear-gradient(135deg, #2980b9, #1f6392);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(52,152,219,0.3);
}

.btn-deduction {
    background: linear-gradient(135deg, #e67e22, #d35400);
    color: white;
    border: 1px solid #d35400;
}

.btn-deduction:hover {
    background: linear-gradient(135deg, #d35400, #b84300);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(230,126,34,0.3);
}

.btn-edit {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    border: 1px solid #e67e22;
}

.btn-edit:hover {
    background: linear-gradient(135deg, #e67e22, #d35400);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(243,156,18,0.3);
}

.btn-delete {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    border: 1px solid #c0392b;
}

.btn-delete:hover {
    background: linear-gradient(135deg, #c0392b, #a93226);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(231,76,60,0.3);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

.modal-content {
    background: var(--card-bg);
    border-radius: 24px;
    width: 90%;
    max-width: 500px;
    animation: slideInUp 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* Extra Large Modal for Deployed Items and Deduction History */
.modal-content-extra-large {
    max-width: 1400px !important;
    width: 95% !important;
    margin: 20px auto !important;
    max-height: 90vh !important;
    display: flex;
    flex-direction: column;
}

.modal-content-extra-large .modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    min-height: 0;
}

/* Large Modal for Deduction - WITH SCROLLBAR AND ALWAYS VISIBLE FOOTER */
.modal-content-large {
    max-width: 1200px !important;
    width: 90% !important;
    max-height: 85vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

.modal-content-large .modal-header {
    flex-shrink: 0 !important;
}

.modal-content-large .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    padding: 20px;
    min-height: 150px !important; /* Minimum height even when empty */
}

.modal-content-large .modal-footer {
    flex-shrink: 0 !important;
    padding: 15px 25px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex !important;
    justify-content: flex-end !important;
    gap: 12px;
}

/* ============================================ */
/* DEDUCTION MODAL - WITH SCROLLBAR AND ALWAYS VISIBLE FOOTER */
/* ============================================ */
.modal-content-large {
    max-width: 1200px !important;
    width: 90% !important;
    max-height: 85vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

.modal-content-large .modal-header {
    flex-shrink: 0 !important;
}

.modal-content-large .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    padding: 20px;
    min-height: 150px !important;
}

.modal-content-large .modal-footer {
    flex-shrink: 0 !important;
    padding: 15px 25px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex !important;
    justify-content: flex-end !important;
    gap: 12px;
}

/* Deduction Modal specific fixes */
#deductionModal .modal-content {
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    max-height: 85vh !important;
}

#deductionModal .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    min-height: 150px !important;
}

#deductionModal .modal-footer {
    flex-shrink: 0 !important;
    display: flex !important;
    justify-content: flex-end !important;
}

#deductionForm {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
}

#deductionForm .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    min-height: 150px !important;
}

#deductionItemsContainer {
    min-height: 120px;
}

#deductionItemsContainer .empty-deployed {
    padding: 30px 20px !important;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

#deductionItemsContainer .empty-deployed i {
    font-size: 48px !important;
    margin-bottom: 12px !important;
}

#deductionItemsContainer .empty-deployed p {
    margin-bottom: 8px;
}

#deductionItemsContainer .empty-deployed .sub-text {
    font-size: 13px;
}

#deductionItemsContainer .loading-spinner {
    padding: 30px 20px !important;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

#deductionItemsContainer .loading-spinner i {
    font-size: 36px !important;
    margin-bottom: 12px !important;
}

/* Remarks Section Toggle */
#remarksSection {
    margin-top: 20px;
    display: none;
}

/* ============================================ */
/* DEDUCTION HISTORY MODAL - INCREASED HEIGHT WITH VISIBLE SCROLLBAR */
/* ============================================ */
#deductionHistoryModal .modal-content {
    max-width: 1400px !important;
    width: 95% !important;
    margin: 20px auto !important;
    max-height: 90vh !important;
    height: 85vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

#deductionHistoryModal .modal-header {
    flex-shrink: 0 !important;
}

#deductionHistoryModal .modal-body {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    padding: 20px;
    min-height: 0 !important;
    display: flex;
    flex-direction: column;
}

#deductionHistoryModal .modal-footer {
    flex-shrink: 0 !important;
    padding: 15px 25px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
}

/* History Stats - Compact with Dark Background */
.history-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 12px 15px;
     background: var(--bg-primary);
    border-radius: 12px;
    color: white;
    flex-shrink: 0;
}

.history-stat-item {
    flex: 1;
    text-align: center;
}

.history-stat-item .label {
    font-size: 11px;
    opacity: 0.9;
    margin-bottom: 4px;
}

.history-stat-item .value {
    font-size: 22px;
    font-weight: 700;
}

/* History Table Container - WITH VISIBLE SCROLLBAR */
#historyTableContainer {
    flex: 1 1 auto;
    min-height: 200px;
    overflow: visible;
}

#historyTableContainer .deployed-table-container {
    max-height: 400px;
    overflow-y: auto !important;
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    background: var(--bg-primary);
}

/* Make scrollbar always visible on history table */
#historyTableContainer .deployed-table-container::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

#historyTableContainer .deployed-table-container::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 10px;
}

#historyTableContainer .deployed-table-container::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 10px;
    border: 2px solid var(--bg-secondary);
}

#historyTableContainer .deployed-table-container::-webkit-scrollbar-thumb:hover {
    background: #62d4c8;
}

#historyTableContainer .deployed-table-container::-webkit-scrollbar-corner {
    background: var(--bg-secondary);
}

/* Empty state in history container */
#historyTableContainer .empty-deployed,
#historyTableContainer .loading-spinner {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-secondary);
    background: var(--bg-primary);
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

#historyTableContainer .empty-deployed i,
#historyTableContainer .loading-spinner i {
    font-size: 48px;
    color: var(--accent-color);
    margin-bottom: 16px;
    opacity: 0.5;
}

/* Deduction History Modal - Table Header Background #272757 */
#historyTableContainer .deployed-table th {
    background: #272757 !important;
    color: white !important;
    padding: 12px 12px;
    font-size: 12px;
    white-space: nowrap;
    border-bottom: 2px solid var(--border-color);
}
#historyTableContainer .deployed-table td {
    padding: 10px 12px;
    font-size: 12px;
}

/* Responsive for History Modal */
@media (max-width: 768px) {
    #deductionHistoryModal .modal-content {
        width: 98% !important;
        height: 90vh !important;
        margin: 10px !important;
    }
    
    #historyTableContainer .deployed-table-container {
        max-height: 350px;
    }
}

@media (max-width: 480px) {
    #historyTableContainer .deployed-table-container {
        max-height: 300px;
    }
}

/* Search Bar Styles */
.view-search-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: var(--bg-secondary);
    border-radius: 12px;
    flex-wrap: wrap;
    gap: 15px;
    border: 1px solid var(--border-color);
}

.search-input-wrapper {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.search-input-wrapper i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.search-input-wrapper input {
    width: 100%;
    padding: 12px 15px 12px 45px;
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.search-input-wrapper input:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

.search-input-wrapper input::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}

.search-info {
    font-size: 13px;
    color: var(--text-secondary);
    background: var(--bg-primary);
    padding: 8px 16px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
}

.search-info span {
    color: #75e6da;
    font-weight: 700;
    font-size: 16px;
}

/* Deployed Table Container */
.deployed-table-container {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 500px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    background: var(--bg-primary);
}

.deployed-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 800px;
}

.deployed-table th {
    background: linear-gradient(135deg, #75e6da, #62d4c8);
    padding: 14px 15px;
    text-align: center;
    font-weight: 600;
    color: white;
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}
/* Override table header background for deduction history */
#historyTableContainer .deployed-table th {
    background: #272757 !important;
    color: white !important;
}
.deployed-table td {
    padding: 12px 15px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
    text-align: center;
}

.deployed-table tbody tr:hover {
    background: var(--bg-secondary);
}

.deployed-table td:first-child,
.deployed-table th:first-child {
    text-align: left;
}

.deployed-table td:nth-child(2),
.deployed-table th:nth-child(2) {
    text-align: left;
}

/* Deduction Items Table */
.deduction-items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.deduction-items-table th {
    background: var(--bg-secondary);
    padding: 12px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}

.deduction-items-table td {
    padding: 12px;
    font-size: 13px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-secondary);
    vertical-align: middle;
}

.deduction-items-table tr:hover {
    background: var(--bg-secondary);
}

.deduction-items-table input[type="number"] {
    width: 100px;
    padding: 8px 10px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    text-align: center;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-weight: 600;
}

.deduction-items-table input[type="number"]:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(117,230,218,0.2);
}

.current-qty-badge {
    display: inline-block;
    background: var(--accent-gradient);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    color: white;
}

/* Plain text category and unit display */
.category-plain {
    color: var(--text-primary);
    font-size: 13px;
}

.unit-plain {
    color: var(--text-primary);
    font-size: 13px;
}

/* Remarks Textarea */
.remarks-textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: all 0.3s;
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
}

.remarks-textarea:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

.remarks-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 14px;
}

.remarks-label i {
    color: var(--accent-color);
    margin-right: 8px;
}

/* Empty State */
.empty-deployed {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.empty-deployed i {
    font-size: 64px;
    color: var(--accent-color);
    margin-bottom: 16px;
    opacity: 0.5;
}

/* Loading Spinner */
.loading-spinner {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.loading-spinner i {
    font-size: 48px;
    color: var(--accent-color);
    margin-bottom: 16px;
    display: block;
}

/* View Deployed Items Modal - Table Header Background #272757 */
#viewSiteItemsContainer .deployed-table th {
    background: #272757 !important;
    color: white !important;
}

/* Scrollbar Styling - VISIBLE */
.deployed-table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.deployed-table-container::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 10px;
}

.deployed-table-container::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 10px;
}

.deployed-table-container::-webkit-scrollbar-thumb:hover {
    background: #62d4c8;
}

/* Modal Body Scrollbar - ALWAYS VISIBLE */
.modal-content-large .modal-body::-webkit-scrollbar,
.modal-content-extra-large .modal-body::-webkit-scrollbar {
    width: 8px;
}

.modal-content-large .modal-body::-webkit-scrollbar-track,
.modal-content-extra-large .modal-body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 10px;
}

.modal-content-large .modal-body::-webkit-scrollbar-thumb,
.modal-content-extra-large .modal-body::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 10px;
}

.modal-content-large .modal-body::-webkit-scrollbar-thumb:hover,
.modal-content-extra-large .modal-body::-webkit-scrollbar-thumb:hover {
    background: #62d4c8;
}

/* Toast Message */
.toast-message {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 12px;
    background: var(--card-bg);
    color: var(--text-primary);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10001;
    animation: slideInRight 0.3s ease;
    border-left: 4px solid var(--success-color);
    display: flex;
    align-items: center;
    gap: 10px;
}

.toast-message.success {
    border-left-color: #00b894;
}

.toast-message.error {
    border-left-color: var(--danger-color);
}

/* Modal Header */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    background: var(--bg-secondary);
    border-bottom: 2px solid var(--border-color);
    flex-shrink: 0;
}

.modal-header h3 {
    margin: 0;
    color: var(--text-primary);
    font-size: 20px;
    font-weight: 700;
}

.modal-header h3 i {
    color: var(--accent-color);
    margin-right: 10px;
}

.close-modal {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: var(--text-secondary);
    transition: all 0.2s;
}

.close-modal:hover {
    color: var(--danger-color);
    transform: scale(1.1);
}

.modal-body {
    padding: 25px;
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

.modal-footer {
    padding: 15px 25px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0;
}

/* Form Groups */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 14px;
}

.form-group label i {
    margin-right: 8px;
    color: var(--accent-color);
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.btn {
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--accent-gradient);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(117,230,218,0.3);
}

.btn-secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--border-color);
}

.btn-danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(231,76,60,0.3);
}

/* Export Modal - LARGER HEIGHT, COMPACT CALENDAR, 3-COLUMN LAYOUT */
#exportModal .modal-content {
    max-width: 900px !important;
    width: 90% !important;
    max-height: 650px !important;
    height: 600px !important;
    display: flex;
    flex-direction: column;
}

#exportModal .modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 25px;
}

/* Export Date Row - 3 Columns Side by Side */
.export-date-row {
    display: flex;
    gap: 15px;
    margin-bottom: 0;
    align-items: flex-end;
}

.export-date-row .form-group {
    flex: 1;
    margin-bottom: 0;
    min-width: 0;
}

/* Site filter in same row */
.export-site-group {
    flex: 1;
    min-width: 0;
}

.export-site-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 14px;
}

.export-site-group label i {
    margin-right: 8px;
    color: var(--accent-color);
}

.export-site-group select {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
    height: 42px;
}

.export-site-group select:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

/* History Filter Section with Compact Calendar */
.history-filter-section {
    background: var(--bg-secondary);
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.history-filter-group {
    flex: 1;
    min-width: 180px;
}

.history-filter-group label {
    display: block;
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 5px;
    font-weight: 600;
}

.history-filter-group input,
.history-filter-group select {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    background: var(--bg-primary);
    color: var(--text-primary);
    height: 42px;
}

.history-filter-group input:focus,
.history-filter-group select:focus {
    outline: none;
    border-color: var(--accent-color);
}

/* History Stats - Dark Background */
.history-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
     background: var(--bg-primary);
    border-radius: 12px;
    color: white;
}
.history-stat-item {
    flex: 1;
    text-align: center;
}

.history-stat-item .label {
    font-size: 12px;
    opacity: 0.9;
    margin-bottom: 5px;
}

.history-stat-item .value {
    font-size: 24px;
    font-weight: 700;
}

/* Info Box */
.info-box {
    margin-top: 15px;
    padding: 10px 15px;
    background: var(--bg-secondary);
    border-radius: 8px;
    border-left: 3px solid var(--accent-color);
}

.info-box small {
    color: var(--text-secondary);
    font-size: 12px;
}

.info-box i {
    color: var(--accent-color);
    margin-right: 5px;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
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

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        padding: 20px 20px 0 20px;
    }
    
    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .filter-section {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .btn-filter {
        width: 100%;
        justify-content: center;
    }
    
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .header-buttons {
        width: 100%;
        justify-content: flex-end;
    }
    
    .site-item {
        flex-direction: column;
        text-align: center;
    }
    
    .site-actions {
        justify-content: center;
    }
    
    .modal-content-extra-large,
    .modal-content-large {
        width: 98% !important;
        margin: 10px !important;
    }
    
    .calendar-wrapper {
        width: 260px !important;
    }
    
    .export-date-row {
        flex-direction: column;
        gap: 15px;
    }
    
    #exportModal .modal-content {
        max-height: 80vh !important;
    }
}

@media (max-width: 480px) {
    .stats-cards {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-icon {
        font-size: 32px;
    }
    
    .stat-info .number {
        font-size: 24px;
    }
}
</style>

<main class="content">
    <div class="main-content">
        <div class="dashboard-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div class="dashboard-title">
                    <i class="fas fa-map-marker-alt"></i>
                    Site Management
                </div>
                <div class="header-buttons">
                    <button class="btn-history" onclick="openDeductionHistoryModal()">
                        <i class="fas fa-history"></i> Deduction History
                        <?php if ($total_deduction_count > 0): ?>
                            <span style="background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 20px; font-size: 12px;"><?php echo $total_deduction_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="btn-add-site" onclick="openAddSiteModal()">
                        <i class="fas fa-plus-circle"></i> Add New Site
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Pull-outs</h3>
                        <div class="number"><?php echo number_format($total_pullouts); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Quantity</h3>
                        <div class="number"><?php echo number_format($total_quantity); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Sites/Locations</h3>
                        <div class="number"><?php echo number_format(count($sites)); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-location-dot"></i>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Deductions</h3>
                        <div class="number"><?php echo number_format($total_deduction_count); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> Search by Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="singleDateField" class="date-field" value="<?php echo $single_date ? date('m/d/Y', strtotime($single_date)) : ''; ?>" placeholder="Select date to view inventory" autocomplete="off" readonly onclick="toggleCalendar('single')">
                            <input type="hidden" id="singleDate" name="singleDate" value="<?php echo $single_date; ?>">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('single')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="singleCalendar">
                            <div class="calendar-box">
                                <div class="calendar-header">
                                    <div class="calendar-month-year" id="singleMonthYear"></div>
                                    <div class="calendar-nav">
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('single', -1)">‹</button>
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('single', 1)">›</button>
                                    </div>
                                </div>
                                <div class="calendar-selectors">
                                    <select id="singleMonthSelect" class="calendar-select" onchange="changeMonthYear('single')">
                                        <option value="0">Jan</option><option value="1">Feb</option><option value="2">Mar</option>
                                        <option value="3">Apr</option><option value="4">May</option><option value="5">Jun</option>
                                        <option value="6">Jul</option><option value="7">Aug</option><option value="8">Sep</option>
                                        <option value="9">Oct</option><option value="10">Nov</option><option value="11">Dec</option>
                                    </select>
                                    <select id="singleYearSelect" class="calendar-select" onchange="changeMonthYear('single')"></select>
                                </div>
                                <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                <div class="calendar-days-grid" id="singleDaysGrid"></div>
                                <div class="calendar-footer">
                                    <button type="button" class="calendar-action-btn clear" onclick="clearDate('single')"><i class="fas fa-times"></i> Clear</button>
                                    <button type="button" class="calendar-action-btn today" onclick="setToday('single')"><i class="fas fa-calendar-check"></i> Today</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-location-dot"></i> Site Location</label>
                    <select id="siteFilter">
                        <option value="">All Sites</option>
                        <?php 
                        $all_sites_sql = "SELECT site_name FROM sites ORDER BY site_name";
                        $all_sites_result = $conn->query($all_sites_sql);
                        if ($all_sites_result) {
                            while($site_row = $all_sites_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo htmlspecialchars($site_row['site_name']); ?>" <?php echo $site_filter == $site_row['site_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site_row['site_name']); ?>
                            </option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </div>
                <button class="btn-filter" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply Filter</button>
                <button class="btn-filter btn-reset" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                <button class="btn-filter" onclick="openExportModal()"><i class="fas fa-download"></i> Export</button>
            </div>
            
            <!-- Sites List Section -->
            <div class="sites-container">
                <div class="sites-header">
                    <h2>
                        <i class="fas fa-building"></i>
                        Saved Sites
                        <?php if (!empty($site_filter)): ?>
                            <span style="font-size: 14px; background: var(--accent-gradient); padding: 4px 12px; border-radius: 20px; margin-left: 10px;">
                                <i class="fas fa-filter"></i> Filtered: <?php echo htmlspecialchars($site_filter); ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                    <span style="color: var(--text-secondary); font-size: 13px; background: var(--bg-secondary); padding: 5px 12px; border-radius: 20px;">
                        <i class="fas fa-database"></i> Total: <?php echo count($sites); ?> sites
                        <?php if (!empty($site_filter) && count($sites) > 0): ?>
                            <span style="color: var(--accent-color);">(filtered)</span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <?php if (empty($sites)): ?>
                    <div class="empty-deployed">
                        <i class="fas fa-building"></i>
                        <p>No sites found</p>
                        <?php if (!empty($site_filter)): ?>
                            <div class="sub-text">No site matching "<?php echo htmlspecialchars($site_filter); ?>" found. <a href="#" onclick="resetFilters(); return false;" style="color: var(--accent-color);">Clear filter</a> to see all sites.</div>
                        <?php else: ?>
                            <div class="sub-text">Click "Add New Site" to create your first site location.</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="sites-list" id="sitesList">
                        <?php foreach ($sites as $site): ?>
                            <div class="site-item" data-site-name="<?php echo htmlspecialchars(strtolower($site['site_name'])); ?>">
                                <div class="site-info">
                                    <h4>
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?php echo htmlspecialchars($site['site_name']); ?>
                                    </h4>
                                    <?php if (!empty($site['location_description'])): ?>
                                        <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($site['location_description']); ?></p>
                                    <?php endif; ?>
                                    <div class="site-date">
                                        <i class="fas fa-calendar-alt"></i> Created: <?php echo date('M d, Y', strtotime($site['created_at'])); ?>
                                    </div>
                                </div>
                                <div class="site-actions">
                                    <button class="action-btn btn-view" onclick="openViewSiteModal('<?php echo addslashes($site['site_name']); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="action-btn btn-deduction" onclick="openDeductionModal('<?php echo addslashes($site['site_name']); ?>', <?php echo $site['id']; ?>)">
                                        <i class="fas fa-minus-circle"></i> Deduct
                                    </button>
                                    <button class="action-btn btn-edit" onclick="openEditSiteModal(<?php echo $site['id']; ?>, '<?php echo addslashes($site['site_name']); ?>', '<?php echo addslashes($site['location_description']); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn btn-delete" onclick="openDeleteSiteModal(<?php echo $site['id']; ?>, '<?php echo addslashes($site['site_name']); ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Add Site Modal -->
<div id="addSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New Site</h3>
            <button class="close-modal" onclick="closeAddSiteModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="add_site" value="1">
                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Site Name</label>
                    <input type="text" name="site_name" class="form-control" placeholder="e.g., Makati Site, BGC Office..." required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> Location Description</label>
                    <textarea name="location_description" class="form-control" placeholder="Optional: Address, contact person, etc." rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddSiteModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Site</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Site Modal -->
<div id="editSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Site</h3>
            <button class="close-modal" onclick="closeEditSiteModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="edit_site" value="1">
                <input type="hidden" name="site_id" id="editSiteId">
                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Site Name</label>
                    <input type="text" name="site_name" id="editSiteName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> Location Description</label>
                    <textarea name="location_description" id="editSiteDesc" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditSiteModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Site</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Site Modal -->
<div id="deleteSiteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3><i class="fas fa-trash"></i> Delete Site</h3>
            <button class="close-modal" onclick="closeDeleteSiteModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body" style="text-align: center;">
                <input type="hidden" name="delete_site" value="1">
                <input type="hidden" name="site_id" id="deleteSiteId">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #e74c3c; margin-bottom: 15px;"></i>
                <p>Are you sure you want to delete this site?</p>
                <p><strong id="deleteSiteName"></strong></p>
                <small style="color: var(--text-secondary);">This action cannot be undone.</small>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteSiteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Site</button>
            </div>
        </form>
    </div>
</div>

<!-- View Site Modal -->
<div id="viewSiteModal" class="modal">
    <div class="modal-content modal-content-extra-large">
        <div class="modal-header">
            <div style="flex: 1;">
                <h3 style="margin: 0 0 8px 0;"><i class="fas fa-eye"></i> Deployed Items at: <span id="viewSiteName"></span></h3>
                <div id="viewSiteStats" style="font-size: 12px; opacity: 0.9; display: flex; gap: 20px; flex-wrap: wrap;">
                    <span><i class="fas fa-calendar-alt"></i> As of: <span id="viewAsOfDate">--</span></span>
                    <span><i class="fas fa-cubes"></i> Total Items: <span id="viewTotalItems">0</span> | Total Quantity: <span id="viewTotalQuantity">0</span></span>
                    <span><i class="fas fa-info-circle"></i> Showing cumulative quantity up to <span id="viewCumulativeDate">--</span></span>
                </div>
            </div>
            <button class="close-modal" onclick="closeViewSiteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="view-search-bar">
                <div class="search-input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="deployedItemsSearch" placeholder="Search by Item No, Description, Category..." onkeyup="filterDeployedItems()">
                </div>
                <div class="search-info">
                    <span id="searchResultCount">0</span> items found
                </div>
            </div>
            
            <div id="viewSiteItemsContainer">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading items...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewSiteModal()">Close</button>
            <button type="button" class="btn btn-primary" onclick="exportDeployedItems()"><i class="fas fa-download"></i> Export</button>
        </div>
    </div>
</div>

<!-- Export Modal - 3-COLUMN LAYOUT WITH SITE IN SAME ROW -->
<div id="exportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-download"></i> Export Site Data</h3>
            <button class="close-modal" onclick="closeExportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- 3-COLUMN ROW: From Date, To Date, Site (Optional) -->
            <div class="export-date-row">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="exportFromField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('exportFrom')">
                            <input type="hidden" id="exportFrom" name="exportFrom">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('exportFrom')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="exportFromCalendar">
                            <div class="calendar-box">
                                <div class="calendar-header">
                                    <div class="calendar-month-year" id="exportFromMonthYear"></div>
                                    <div class="calendar-nav">
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportFrom', -1)">‹</button>
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportFrom', 1)">›</button>
                                    </div>
                                </div>
                                <div class="calendar-selectors">
                                    <select id="exportFromMonthSelect" class="calendar-select" onchange="changeMonthYear('exportFrom')">
                                        <option value="0">Jan</option><option value="1">Feb</option><option value="2">Mar</option>
                                        <option value="3">Apr</option><option value="4">May</option><option value="5">Jun</option>
                                        <option value="6">Jul</option><option value="7">Aug</option><option value="8">Sep</option>
                                        <option value="9">Oct</option><option value="10">Nov</option><option value="11">Dec</option>
                                    </select>
                                    <select id="exportFromYearSelect" class="calendar-select" onchange="changeMonthYear('exportFrom')"></select>
                                </div>
                                <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                <div class="calendar-days-grid" id="exportFromDaysGrid"></div>
                                <div class="calendar-footer">
                                    <button type="button" class="calendar-action-btn clear" onclick="clearDate('exportFrom')"><i class="fas fa-times"></i> Clear</button>
                                    <button type="button" class="calendar-action-btn today" onclick="setToday('exportFrom')"><i class="fas fa-calendar-check"></i> Today</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="exportToField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('exportTo')">
                            <input type="hidden" id="exportTo" name="exportTo">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('exportTo')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="exportToCalendar">
                            <div class="calendar-box">
                                <div class="calendar-header">
                                    <div class="calendar-month-year" id="exportToMonthYear"></div>
                                    <div class="calendar-nav">
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportTo', -1)">‹</button>
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('exportTo', 1)">›</button>
                                    </div>
                                </div>
                                <div class="calendar-selectors">
                                    <select id="exportToMonthSelect" class="calendar-select" onchange="changeMonthYear('exportTo')">
                                        <option value="0">Jan</option><option value="1">Feb</option><option value="2">Mar</option>
                                        <option value="3">Apr</option><option value="4">May</option><option value="5">Jun</option>
                                        <option value="6">Jul</option><option value="7">Aug</option><option value="8">Sep</option>
                                        <option value="9">Oct</option><option value="10">Nov</option><option value="11">Dec</option>
                                    </select>
                                    <select id="exportToYearSelect" class="calendar-select" onchange="changeMonthYear('exportTo')"></select>
                                </div>
                                <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                <div class="calendar-days-grid" id="exportToDaysGrid"></div>
                                <div class="calendar-footer">
                                    <button type="button" class="calendar-action-btn clear" onclick="clearDate('exportTo')"><i class="fas fa-times"></i> Clear</button>
                                    <button type="button" class="calendar-action-btn today" onclick="setToday('exportTo')"><i class="fas fa-calendar-check"></i> Today</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="export-site-group">
                    <label><i class="fas fa-location-dot"></i> Site (Optional)</label>
                    <select id="exportSiteFilter">
                        <option value="">All Sites</option>
                        <?php foreach ($site_names as $site): ?>
                            <option value="<?php echo htmlspecialchars($site); ?>"><?php echo htmlspecialchars($site); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeExportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmExport()"><i class="fas fa-download"></i> Export Data</button>
        </div>
    </div>
</div>
<!-- Deduction Modal - WITH VISIBLE SCROLLBAR AND ALWAYS VISIBLE BUTTONS -->
<div id="deductionModal" class="modal">
    <div class="modal-content modal-content-large" style="display: flex !important; flex-direction: column !important; overflow: hidden !important;">
        <div class="modal-header">
            <h3><i class="fas fa-minus-circle"></i> Deduct Items from <span id="deductionSiteName"></span></h3>
            <button class="close-modal" onclick="closeDeductionModal()">&times;</button>
        </div>
        <form id="deductionForm" method="POST" action="process_deduction.php" style="display: flex !important; flex-direction: column !important; flex: 1 1 auto !important; min-height: 0 !important; overflow: hidden !important;">
            <div class="modal-body" style="flex: 1 1 auto !important; overflow-y: auto !important; min-height: 150px !important;">
                <input type="hidden" name="site_id" id="deductionSiteId">
                <input type="hidden" name="site_name" id="deductionSiteNameInput">
                
                <div id="deductionItemsContainer" style="min-height: 120px;">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading items...</p>
                    </div>
                </div>
                
                <!-- REMARKS FIELD - WRAPPED IN DIV FOR TOGGLE -->
                <div id="remarksSection" style="margin-top: 20px; display: none;">
                    <label class="remarks-label"><i class="fas fa-comment"></i> Remarks</label>
                    <textarea name="remarks" id="deductionRemarks" class="remarks-textarea" rows="3" placeholder="Enter remarks for this deduction (e.g., reason for deduction, damaged items, etc.)"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="flex-shrink: 0 !important; display: flex !important; justify-content: flex-end !important;">
                <button type="button" class="btn btn-secondary" onclick="closeDeductionModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveDeductionBtn"><i class="fas fa-save"></i> Save Deduction</button>
            </div>
        </form>
    </div>
</div>
<!-- Deduction History Modal - COMPACT CALENDAR WITH VISIBLE SCROLLBAR -->
<div id="deductionHistoryModal" class="modal">
    <div class="modal-content" style="display: flex !important; flex-direction: column !important; overflow: hidden !important;">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Deduction History</h3>
            <button class="close-modal" onclick="closeDeductionHistoryModal()">&times;</button>
        </div>
        <div class="modal-body" style="flex: 1 1 auto !important; overflow-y: auto !important; display: flex; flex-direction: column;">
            <!-- History Filter Section with Compact Calendar -->
            <div class="history-filter-section">
                <!-- From Date with Compact Calendar -->
                <div class="history-filter-group">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="historyFromField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('historyFrom')">
                            <input type="hidden" id="historyFromDate" name="historyFromDate">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('historyFrom')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="historyFromCalendar">
                            <!-- Calendar content remains the same -->
                            <div class="calendar-box">
                                <div class="calendar-header">
                                    <div class="calendar-month-year" id="historyFromMonthYear"></div>
                                    <div class="calendar-nav">
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('historyFrom', -1)">‹</button>
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('historyFrom', 1)">›</button>
                                    </div>
                                </div>
                                <div class="calendar-selectors">
                                    <select id="historyFromMonthSelect" class="calendar-select" onchange="changeMonthYear('historyFrom')">
                                        <option value="0">Jan</option><option value="1">Feb</option><option value="2">Mar</option>
                                        <option value="3">Apr</option><option value="4">May</option><option value="5">Jun</option>
                                        <option value="6">Jul</option><option value="7">Aug</option><option value="8">Sep</option>
                                        <option value="9">Oct</option><option value="10">Nov</option><option value="11">Dec</option>
                                    </select>
                                    <select id="historyFromYearSelect" class="calendar-select" onchange="changeMonthYear('historyFrom')"></select>
                                </div>
                                <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                <div class="calendar-days-grid" id="historyFromDaysGrid"></div>
                                <div class="calendar-footer">
                                    <button type="button" class="calendar-action-btn clear" onclick="clearDate('historyFrom')"><i class="fas fa-times"></i> Clear</button>
                                    <button type="button" class="calendar-action-btn today" onclick="setToday('historyFrom')"><i class="fas fa-calendar-check"></i> Today</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- To Date with Compact Calendar -->
                <div class="history-filter-group">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <div class="date-picker-wrapper">
                        <div class="date-input-group">
                            <input type="text" id="historyToField" class="date-field" placeholder="MM/DD/YYYY" autocomplete="off" readonly onclick="toggleCalendar('historyTo')">
                            <input type="hidden" id="historyToDate" name="historyToDate">
                            <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('historyTo')"><i class="fas fa-chevron-down"></i></button>
                        </div>
                        <div class="calendar-wrapper" id="historyToCalendar">
                            <div class="calendar-box">
                                <div class="calendar-header">
                                    <div class="calendar-month-year" id="historyToMonthYear"></div>
                                    <div class="calendar-nav">
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('historyTo', -1)">‹</button>
                                        <button type="button" class="calendar-nav-btn" onclick="navigateMonth('historyTo', 1)">›</button>
                                    </div>
                                </div>
                                <div class="calendar-selectors">
                                    <select id="historyToMonthSelect" class="calendar-select" onchange="changeMonthYear('historyTo')">
                                        <option value="0">Jan</option><option value="1">Feb</option><option value="2">Mar</option>
                                        <option value="3">Apr</option><option value="4">May</option><option value="5">Jun</option>
                                        <option value="6">Jul</option><option value="7">Aug</option><option value="8">Sep</option>
                                        <option value="9">Oct</option><option value="10">Nov</option><option value="11">Dec</option>
                                    </select>
                                    <select id="historyToYearSelect" class="calendar-select" onchange="changeMonthYear('historyTo')"></select>
                                </div>
                                <div class="calendar-weekdays"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                                <div class="calendar-days-grid" id="historyToDaysGrid"></div>
                                <div class="calendar-footer">
                                    <button type="button" class="calendar-action-btn clear" onclick="clearDate('historyTo')"><i class="fas fa-times"></i> Clear</button>
                                    <button type="button" class="calendar-action-btn today" onclick="setToday('historyTo')"><i class="fas fa-calendar-check"></i> Today</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="history-filter-group">
                    <label><i class="fas fa-location-dot"></i> Site</label>
                    <select id="historySiteFilter">
                        <option value="">All Sites</option>
                        <?php foreach ($site_names as $site): ?>
                            <option value="<?php echo htmlspecialchars($site); ?>"><?php echo htmlspecialchars($site); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="history-filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="historySearch" placeholder="Item No, Description...">
                </div>
                <button class="btn-filter" onclick="loadDeductionHistory()"><i class="fas fa-search"></i> Filter</button>
                <button class="btn-filter btn-reset" onclick="resetHistoryFilters()"><i class="fas fa-undo"></i> Reset</button>
                <button class="btn-filter" onclick="exportDeductionHistory()"><i class="fas fa-download"></i> Export</button>
            </div>
            
            <!-- History Stats -->
            <div class="history-stats" id="historyStats">
                <div class="history-stat-item">
                    <div class="label">Total Deductions</div>
                    <div class="value" id="totalDeductions">0</div>
                </div>
                <div class="history-stat-item">
                    <div class="label">Total Quantity Deducted</div>
                    <div class="value" id="totalDeductedQty">0</div>
                </div>
                <div class="history-stat-item">
                    <div class="label">Unique Items</div>
                    <div class="value" id="uniqueItems">0</div>
                </div>
            </div>
            
            <!-- History Table Container -->
            <div id="historyTableContainer" style="flex: 1 1 auto; min-height: 200px;">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading history...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeductionHistoryModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Calendar state
let activeCalendar = null;
let calendarDates = {
    single: { currentDate: new Date(), selectedDate: '<?php echo $single_date; ?>' },
    exportFrom: { currentDate: new Date(), selectedDate: '' },
    exportTo: { currentDate: new Date(), selectedDate: '' },
    historyFrom: { currentDate: new Date(), selectedDate: '' },
    historyTo: { currentDate: new Date(), selectedDate: '' }
};

// Initialize year dropdowns
function initializeYearSelects() {
    const yearSelects = ['singleYearSelect', 'exportFromYearSelect', 'exportToYearSelect', 'historyFromYearSelect', 'historyToYearSelect'];
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

// Select a date
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
        case 'single':
            fieldId = 'singleDateField';
            hiddenId = 'singleDate';
            break;
        case 'exportFrom':
            fieldId = 'exportFromField';
            hiddenId = 'exportFrom';
            break;
        case 'exportTo':
            fieldId = 'exportToField';
            hiddenId = 'exportTo';
            break;
        case 'historyFrom':
            fieldId = 'historyFromField';
            hiddenId = 'historyFromDate';
            break;
        case 'historyTo':
            fieldId = 'historyToField';
            hiddenId = 'historyToDate';
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
    
    if (calendarId === 'single') {
        applyFilters();
    }
}

// Clear date
function clearDate(calendarId) {
    let fieldId = '';
    let hiddenId = '';
    
    switch(calendarId) {
        case 'single':
            fieldId = 'singleDateField';
            hiddenId = 'singleDate';
            break;
        case 'exportFrom':
            fieldId = 'exportFromField';
            hiddenId = 'exportFrom';
            break;
        case 'exportTo':
            fieldId = 'exportToField';
            hiddenId = 'exportTo';
            break;
        case 'historyFrom':
            fieldId = 'historyFromField';
            hiddenId = 'historyFromDate';
            break;
        case 'historyTo':
            fieldId = 'historyToField';
            hiddenId = 'historyToDate';
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
    
    if (calendarId === 'single') {
        applyFilters();
    }
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

function applyFilters() {
    const singleDate = document.getElementById('singleDate').value;
    const siteFilter = document.getElementById('siteFilter').value;
    
    let url = 'site.php?';
    if (singleDate) url += 'date=' + singleDate + '&';
    if (siteFilter) url += 'site=' + encodeURIComponent(siteFilter);
    
    if (url.endsWith('&')) url = url.slice(0, -1);
    
    window.location.href = url;
}

function resetFilters() {
    document.getElementById('singleDate').value = '';
    document.getElementById('singleDateField').value = '';
    document.getElementById('siteFilter').value = '';
    calendarDates.single.selectedDate = null;
    updateCalendar('single');
    applyFilters();
}

// Initialize history dates on modal open
function setDefaultHistoryDates() {
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    const fromDateStr = thirtyDaysAgo.toISOString().split('T')[0];
    const toDateStr = today.toISOString().split('T')[0];
    
    calendarDates.historyFrom.selectedDate = fromDateStr;
    calendarDates.historyTo.selectedDate = toDateStr;
    
    selectDate('historyFrom', fromDateStr);
    selectDate('historyTo', toDateStr);
}

function resetHistoryFilters() {
    setDefaultHistoryDates();
    document.getElementById('historySiteFilter').value = '';
    document.getElementById('historySearch').value = '';
    loadDeductionHistory();
}

// Export Modal Functions
function openExportModal() {
    document.getElementById('exportModal').style.display = 'flex';
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    const fromDateStr = thirtyDaysAgo.toISOString().split('T')[0];
    const toDateStr = today.toISOString().split('T')[0];
    
    calendarDates.exportFrom.selectedDate = fromDateStr;
    calendarDates.exportTo.selectedDate = toDateStr;
    
    selectDate('exportFrom', fromDateStr);
    selectDate('exportTo', toDateStr);
}

function closeExportModal() {
    document.getElementById('exportModal').style.display = 'none';
}

function confirmExport() {
    const dateFrom = document.getElementById('exportFrom').value;
    const dateTo = document.getElementById('exportTo').value;
    const siteFilter = document.getElementById('exportSiteFilter').value;
    
    if (!dateFrom || !dateTo) {
        showToast('Please select both from and to dates', 'error');
        return;
    }
    
    let url = 'export_site_data.php?';
    url += 'from=' + dateFrom + '&';
    url += 'to=' + dateTo + '&';
    if (siteFilter) url += 'site=' + encodeURIComponent(siteFilter);
    
    window.open(url, '_blank');
    closeExportModal();
    showToast('Export started!', 'success');
}

// View Site Modal Functions
function openViewSiteModal(siteName) {
    document.getElementById('viewSiteName').textContent = siteName;
    document.getElementById('viewSiteModal').style.display = 'flex';
    loadDeployedItemsForView(siteName);
}

function closeViewSiteModal() {
    document.getElementById('viewSiteModal').style.display = 'none';
}

let currentDeployedItems = [];

function loadDeployedItemsForView(siteName) {
    const container = document.getElementById('viewSiteItemsContainer');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading items...</p></div>';
    
    document.getElementById('viewAsOfDate').textContent = '--';
    document.getElementById('viewTotalItems').textContent = '0';
    document.getElementById('viewTotalQuantity').textContent = '0';
    document.getElementById('viewCumulativeDate').textContent = '--';
    
    const selectedDate = document.getElementById('singleDate').value;
    
    let url = `get_site_deployed_items.php?site_name=${encodeURIComponent(siteName)}`;
    if (selectedDate) url += `&date=${encodeURIComponent(selectedDate)}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentDeployedItems = data.items;
                
                const displayDate = formatDate(data.selected_date);
                document.getElementById('viewAsOfDate').textContent = displayDate;
                document.getElementById('viewTotalItems').textContent = data.total_items;
                document.getElementById('viewTotalQuantity').textContent = data.total_quantity;
                document.getElementById('viewCumulativeDate').textContent = displayDate;
                
                if (data.items.length > 0) {
                    let tableHtml = `
                        <div class="deployed-table-container">
                            <table class="deployed-table" id="deployedItemsTable">
                                <thead>
                                    <tr>
                                        <th>Item No</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Unit</th>
                                    </tr>
                                </thead>
                                <tbody id="deployedItemsTableBody">
                    `;
                    
                    data.items.forEach(item => {
                        tableHtml += generateDeployedItemRow(item);
                    });
                    
                    tableHtml += `
                                </tbody>
                            </table>
                        </div>
                    `;
                    
                    container.innerHTML = tableHtml;
                    updateSearchCount();
                } else {
                    container.innerHTML = `
                        <div class="empty-deployed">
                            <i class="fas fa-box-open"></i>
                            <p>No items have been deployed to this site as of ${displayDate}.</p>
                        </div>
                    `;
                }
            } else {
                container.innerHTML = `
                    <div class="empty-deployed">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading items: ${escapeHtml(data.message)}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            container.innerHTML = `
                <div class="empty-deployed">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error loading items. Please try again.</p>
                </div>
            `;
        });
}

function generateDeployedItemRow(item) {
    let categoryDisplay = '—';
    if (item.category && item.category !== '' && item.category !== '—') {
        categoryDisplay = `<span class="category-plain">${escapeHtml(item.category)}</span>`;
    }
    
    let unitDisplay = '—';
    if (item.unit && item.unit !== '') {
        unitDisplay = `<span class="unit-plain">${escapeHtml(item.unit)}</span>`;
    }
    
    return `
        <tr>
            <td><strong>${escapeHtml(item.item_no || 'N/A')}</strong></td>
            <td>${escapeHtml(item.description || 'N/A')}</td>
            <td>${categoryDisplay}</td>
            <td>${item.quantity}</td>
            <td>${unitDisplay}</td>
        </tr>
    `;
}

function filterDeployedItems() {
    const searchTerm = document.getElementById('deployedItemsSearch').value.trim().toLowerCase();
    const tbody = document.getElementById('deployedItemsTableBody');
    
    if (!tbody) return;
    
    const rows = tbody.querySelectorAll('tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const cells = row.cells;
        let rowText = '';
        
        if (cells.length >= 5) {
            rowText += cells[0]?.textContent.toLowerCase() + ' ';
            rowText += cells[1]?.textContent.toLowerCase() + ' ';
            rowText += cells[2]?.textContent.toLowerCase() + ' ';
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
    
    document.getElementById('searchResultCount').textContent = visibleCount;
}

function updateSearchCount() {
    const tbody = document.getElementById('deployedItemsTableBody');
    if (tbody) {
        const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(row => row.style.display !== 'none').length;
        document.getElementById('searchResultCount').textContent = visibleRows;
    } else {
        document.getElementById('searchResultCount').textContent = '0';
    }
}

function exportDeployedItems() {
    if (currentDeployedItems.length === 0) {
        showToast('No data to export', 'error');
        return;
    }
    
    const selectedDate = document.getElementById('singleDate').value || new Date().toISOString().split('T')[0];
    const siteName = document.getElementById('viewSiteName').textContent;
    
    let csv = 'Item No,Description,Category,Quantity,Unit\n';
    
    const tbody = document.getElementById('deployedItemsTableBody');
    let rowsToExport = [];
    
    if (tbody) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.cells;
                rowsToExport.push({
                    item_no: cells[0]?.textContent.trim() || '',
                    description: cells[1]?.textContent.trim() || '',
                    category: cells[2]?.textContent.trim() || '',
                    quantity: cells[3]?.textContent.trim() || '',
                    unit: cells[4]?.textContent.trim() || ''
                });
            }
        });
    }
    
    rowsToExport.forEach(item => {
        csv += `"${item.item_no}","${item.description}","${item.category}","${item.quantity}","${item.unit}"\n`;
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `deployed_items_${siteName}_as_of_${selectedDate}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showToast('Export completed!', 'success');
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast-message ${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> <span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function exportDeductionHistory() {
    const fromDate = document.getElementById('historyFromDate').value;
    const toDate = document.getElementById('historyToDate').value;
    const siteFilter = document.getElementById('historySiteFilter').value;
    const searchTerm = document.getElementById('historySearch').value;
    
    if (!fromDate || !toDate) {
        showToast('Please select both from and to dates', 'error');
        return;
    }
    
    let url = `export_deduction_history.php?from=${fromDate}&to=${toDate}&site=${encodeURIComponent(siteFilter)}&search=${encodeURIComponent(searchTerm)}`;
    window.open(url, '_blank');
    showToast('Export started!', 'success');
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric'
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Site Management Functions
function openAddSiteModal() {
    document.getElementById('addSiteModal').style.display = 'flex';
}

function closeAddSiteModal() {
    document.getElementById('addSiteModal').style.display = 'none';
}

function openEditSiteModal(id, name, description) {
    document.getElementById('editSiteId').value = id;
    document.getElementById('editSiteName').value = name;
    document.getElementById('editSiteDesc').value = description || '';
    document.getElementById('editSiteModal').style.display = 'flex';
}

function closeEditSiteModal() {
    document.getElementById('editSiteModal').style.display = 'none';
}

function openDeleteSiteModal(id, name) {
    document.getElementById('deleteSiteId').value = id;
    document.getElementById('deleteSiteName').textContent = name;
    document.getElementById('deleteSiteModal').style.display = 'flex';
}

function closeDeleteSiteModal() {
    document.getElementById('deleteSiteModal').style.display = 'none';
}

// ========== DEDUCTION MODAL FUNCTIONS ==========
function openDeductionModal(siteName, siteId) {
    document.getElementById('deductionSiteName').textContent = siteName;
    document.getElementById('deductionSiteId').value = siteId;
    document.getElementById('deductionSiteNameInput').value = siteName;
    
    // Clear remarks field and hide remarks section initially
    document.getElementById('deductionRemarks').value = '';
    const remarksSection = document.getElementById('remarksSection');
    if (remarksSection) {
        remarksSection.style.display = 'none';
    }
    
    document.getElementById('deductionModal').style.display = 'flex';
    loadConsolidatedDeductionItems(siteName);
}

function closeDeductionModal() {
    document.getElementById('deductionModal').style.display = 'none';
}

// Load consolidated deduction items
function loadConsolidatedDeductionItems(siteName) {
    const container = document.getElementById('deductionItemsContainer');
    const remarksSection = document.getElementById('remarksSection');
    
    // Hide remarks section initially while loading
    if (remarksSection) {
        remarksSection.style.display = 'none';
    }
    
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading items...</p></div>';
    
    fetch(`get_consolidated_site_items.php?site_name=${encodeURIComponent(siteName)}`)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.success && data.items && data.items.length > 0) {
                // Show remarks section when items exist
                if (remarksSection) {
                    remarksSection.style.display = 'block';
                }
                
                let tableHtml = `
                    <div class="deployed-table-container">
                        <table class="deduction-items-table" id="deductionItemsTable">
                            <thead>
                                <tr>
                                    <th style="width: 15%">Item No</th>
                                    <th style="width: 35%">Description</th>
                                    <th style="width: 15%">Category</th>
                                    <th style="width: 10%">Quantity</th>
                                    <th style="width: 10%">Unit</th>
                                    <th style="width: 15%">Deduct Quantity</th>
                                </tr>
                            </thead>
                            <tbody id="deductionItemsTableBody">
                `;
                
                data.items.forEach((item, index) => {
                    let categoryDisplay = item.category || '—';
                    if (categoryDisplay === '' || categoryDisplay === 'null' || categoryDisplay === 'NULL') {
                        categoryDisplay = '—';
                    }
                    
                    let unitDisplay = item.unit || 'pcs';
                    if (unitDisplay === '' || unitDisplay === 'null' || unitDisplay === 'NULL') {
                        unitDisplay = 'pcs';
                    }
                    
                    tableHtml += `
                        <tr data-product-id="${item.product_id}" data-total-qty="${item.total_quantity}">
                            <td><strong>${escapeHtml(item.item_no)}</strong></td>
                            <td>${escapeHtml(item.description || item.product_name || 'N/A')}</td>
                            <td><span class="category-plain">${escapeHtml(categoryDisplay)}</span></td>
                            <td><span class="current-qty-badge">${item.total_quantity}</span></td>
                            <td><span class="unit-plain">${escapeHtml(unitDisplay)}</span></td>
                            <td>
                                <input type="hidden" name="items[${index}][product_id]" value="${item.product_id}">
                                <input type="hidden" name="items[${index}][total_qty]" value="${item.total_quantity}">
                                <input type="number" name="items[${index}][deduct_qty]" class="deduct-qty-input" 
                                       value="0" min="0" max="${item.total_quantity}" step="1" 
                                       data-max="${item.total_quantity}" 
                                       data-product-id="${item.product_id}"
                                       style="width: 100px; padding: 8px; text-align: center;
                                              border: 2px solid var(--border-color); border-radius: 8px;
                                              background: var(--bg-primary); color: var(--text-primary);">
                            </td>
                        </tr>
                    `;
                });
                
                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                    <div class="info-box">
                        <small><i class="fas fa-info-circle"></i> Items are consolidated by product. Enter quantity to deduct from this site.</small>
                    </div>
                `;
                
                container.innerHTML = tableHtml;
                
                document.querySelectorAll('.deduct-qty-input').forEach(input => {
                    input.addEventListener('change', function() {
                        const max = parseInt(this.getAttribute('data-max'));
                        let value = parseInt(this.value);
                        if (isNaN(value) || value < 0) {
                            this.value = 0;
                        } else if (value > max) {
                            this.value = max;
                            showToast(`Maximum deduction is ${max}`, 'error');
                        }
                    });
                    
                    input.addEventListener('input', function() {
                        let value = parseInt(this.value);
                        const max = parseInt(this.getAttribute('data-max'));
                        if (isNaN(value)) {
                            this.value = 0;
                        } else if (value > max) {
                            this.value = max;
                        } else if (value < 0) {
                            this.value = 0;
                        }
                    });
                });
            } else {
                // Hide remarks section when no items
                if (remarksSection) {
                    remarksSection.style.display = 'none';
                }
                
                container.innerHTML = `
                    <div class="empty-deployed">
                        <i class="fas fa-box-open"></i>
                        <p>No items found deployed to this site.</p>
                        <div class="sub-text">Pull out items from stock tracker first to deploy them to this site.</div>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading items:', error);
            // Hide remarks section on error
            if (remarksSection) {
                remarksSection.style.display = 'none';
            }
            
            container.innerHTML = `
                <div class="empty-deployed">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error loading items: ${error.message}</p>
                </div>
            `;
        });
}
// Deduction form submission
document.addEventListener('DOMContentLoaded', function() {
    document.body.addEventListener('submit', function(e) {
        const form = e.target.closest('#deductionForm');
        if (form && form.id === 'deductionForm') {
            e.preventDefault();
            e.stopPropagation();
            
            const remarks = document.getElementById('deductionRemarks').value.trim();
            const items = [];
            const quantityInputs = document.querySelectorAll('#deductionItemsTableBody .deduct-qty-input');
            
            quantityInputs.forEach(input => {
                const qty = parseInt(input.value);
                if (qty > 0) {
                    const row = input.closest('tr');
                    const productIdInput = row.querySelector('input[name*="[product_id]"]');
                    const totalQtyInput = row.querySelector('input[name*="[total_qty]"]');
                    
                    if (productIdInput && totalQtyInput) {
                        items.push({
                            product_id: productIdInput.value,
                            deduct_qty: qty,
                            current_qty: totalQtyInput.value
                        });
                    }
                }
            });
            
            if (items.length === 0) {
                showToast('Please enter at least one item quantity to deduct', 'error');
                return;
            }
            
            const siteId = document.getElementById('deductionSiteId').value;
            const siteName = document.getElementById('deductionSiteNameInput').value;
            
            const formData = new FormData();
            formData.append('site_id', siteId);
            formData.append('site_name', siteName);
            formData.append('items_json', JSON.stringify(items));
            formData.append('remarks', remarks);
            
            const submitBtn = document.getElementById('saveDeductionBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            fetch('process_deduction.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeDeductionModal();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    let errorMsg = data.message || 'An error occurred';
                    if (data.errors && data.errors.length > 0) {
                        errorMsg += ': ' + data.errors.join(', ');
                    }
                    showToast(errorMsg, 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showToast('Network error: ' + error.message, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }
    });
});

// Deduction History Functions
function openDeductionHistoryModal() {
    const modal = document.getElementById('deductionHistoryModal');
    if (modal) {
        modal.style.display = 'flex';
        setDefaultHistoryDates();
        loadDeductionHistory();
    }
}

function closeDeductionHistoryModal() {
    const modal = document.getElementById('deductionHistoryModal');
    if (modal) modal.style.display = 'none';
}

function loadDeductionHistory() {
    const fromDate = document.getElementById('historyFromDate').value;
    const toDate = document.getElementById('historyToDate').value;
    const siteFilter = document.getElementById('historySiteFilter').value;
    const searchTerm = document.getElementById('historySearch').value;
    
    const container = document.getElementById('historyTableContainer');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Loading history...</p></div>';
    
    let url = `get_deduction_history.php?from=${encodeURIComponent(fromDate)}&to=${encodeURIComponent(toDate)}&site=${encodeURIComponent(siteFilter)}&search=${encodeURIComponent(searchTerm)}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalDeductions').textContent = data.total_deductions || 0;
                document.getElementById('totalDeductedQty').textContent = data.total_quantity || 0;
                document.getElementById('uniqueItems').textContent = data.unique_items || 0;
                
                if (data.history && data.history.length > 0) {
                    let tableHtml = `
                        <div class="deployed-table-container">
                            <table class="deployed-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Site</th>
                                        <th>Item No</th>
                                        <th>Product Name</th>
                                        <th>Qty Deducted</th>
                                        <th>Previous</th>
                                        <th>New</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                   data.history.forEach(item => {
    let formattedDate = '—';
    if (item.deducted_at) {
        const date = new Date(item.deducted_at);
        formattedDate = date.toLocaleDateString('en-US', {
            month: 'short', day: '2-digit', year: 'numeric'
        });
    }
                        let remarksDisplay = item.remarks || '—';
                        
                        tableHtml += `
                            <tr>
                                <td>${formattedDate}</td>
                                <td>${escapeHtml(item.site_name || '—')}</td>
                                <td>${escapeHtml(item.item_no || '—')}</td>
                                <td>${escapeHtml(item.product_name || '—')}</td>
                                <td style="color: #e74c3c; font-weight: 700;">${item.quantity_deducted}</td>
                                <td>${item.previous_quantity}</td>
                                <td>${item.new_quantity}</td>
                                <td style="max-width: 250px;">${escapeHtml(remarksDisplay)}</td>
                            </tr>
                        `;
                    });
                    
                    tableHtml += `</tbody></table></div>`;
                    container.innerHTML = tableHtml;
                } else {
                    container.innerHTML = `<div class="empty-deployed"><i class="fas fa-history"></i><p>No deduction history found.</p></div>`;
                }
            } else {
                container.innerHTML = `<div class="empty-deployed"><i class="fas fa-exclamation-triangle"></i><p>Error: ${escapeHtml(data.message)}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            container.innerHTML = `<div class="empty-deployed"><i class="fas fa-exclamation-triangle"></i><p>Error loading history.</p></div>`;
        });
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

// Close modals when clicking outside
window.onclick = function(event) {
    const modals = ['addSiteModal', 'editSiteModal', 'deleteSiteModal', 'deductionModal', 'deductionHistoryModal', 'viewSiteModal', 'exportModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) modal.style.display = 'none';
    });
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeYearSelects();
    
    ['single', 'exportFrom', 'exportTo', 'historyFrom', 'historyTo'].forEach(calId => {
        updateCalendar(calId);
    });

    const singleDate = '<?php echo $single_date; ?>';
    if (singleDate) {
        calendarDates.single.selectedDate = singleDate;
        const date = new Date(singleDate);
        document.getElementById('singleDateField').value = date.toLocaleDateString('en-US', {
            month: '2-digit', day: '2-digit', year: 'numeric'
        });
    }
    
    const searchInput = document.getElementById('deployedItemsSearch');
    if (searchInput) searchInput.addEventListener('keyup', filterDeployedItems);
});

// Display toast if exists
<?php if (isset($_SESSION['toast'])): ?>
    showToast('<?php echo addslashes($_SESSION['toast']['message']); ?>', '<?php echo addslashes($_SESSION['toast']['type']); ?>');
    <?php unset($_SESSION['toast']); ?>
<?php endif; ?>
</script>

<?php require_once 'include/footer.php'; ?>