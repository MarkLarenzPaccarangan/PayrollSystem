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

// Get current user
$current_user = getCurrentUser();

// Create sites table if not exists
$create_sites_table = "CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(255) NOT NULL UNIQUE,
    location_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($create_sites_table);

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

// Get all sites
$sites_sql = "SELECT id, site_name, location_description, created_at FROM sites ORDER BY site_name";
$sites_result = $conn->query($sites_sql);
$sites = [];
$site_names = [];
if ($sites_result) {
    while ($row = $sites_result->fetch_assoc()) {
        $sites[] = $row;
        $site_names[] = $row['site_name'];
    }
}

// Get selected site for viewing deployed items
$selected_site = isset($_GET['view_site']) ? $_GET['view_site'] : '';
$deployed_items = [];
$selected_site_details = null;

if ($selected_site) {
    // Get site details
    $site_detail_sql = "SELECT * FROM sites WHERE site_name = ?";
    $site_detail_stmt = $conn->prepare($site_detail_sql);
    $site_detail_stmt->bind_param("s", $selected_site);
    $site_detail_stmt->execute();
    $site_detail_result = $site_detail_stmt->get_result();
    $selected_site_details = $site_detail_result->fetch_assoc();
    
    // Get deployed items for this site
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

// Get date filter parameters from URL
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$site_filter = isset($_GET['site']) ? $_GET['site'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Get statistics
$total_sites = count($sites);

// Get pull out statistics for the filtered date range
$stats_sql = "SELECT 
                COUNT(*) as total_pullouts,
                SUM(quantity) as total_quantity
              FROM stock_movements 
              WHERE type = 'out'
              AND DATE(created_at) BETWEEN ? AND ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("ss", $date_from, $date_to);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$total_pullouts = $stats['total_pullouts'] ?? 0;
$total_quantity = $stats['total_quantity'] ?? 0;

// Include header
require_once 'include/header.php';
?>

<style>
/* Site Page Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* Main Content Area */
.content {
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: hidden;
}

.main-content {
    flex: 1;
    padding: 20px 40px 0 40px;
    width: 100%;
    height: 100%;
    overflow-y: scroll !important;
    overflow-x: hidden;
    background-color: transparent;
    color: #333;
    position: relative;
    display: flex;
    justify-content: center;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

/* Custom Scrollbar */
.main-content::-webkit-scrollbar {
    width: 12px;
}

.main-content::-webkit-scrollbar-track {
    background: #e9ecef;
    border-radius: 10px;
}

.main-content::-webkit-scrollbar-thumb {
    background: #2E7D32;
    border-radius: 10px;
    border: 3px solid #e9ecef;
}

.main-content::-webkit-scrollbar-thumb:hover {
    background: #1B5E20;
}

.main-content {
    scrollbar-width: thin;
    scrollbar-color: #2E7D32 #e9ecef;
}

/* Dashboard Container */
.dashboard-container {
    max-width: 1400px;
    width: 100%;
    margin: 0 auto 0 auto;
    padding: 0 20px 50px 20px;
    min-height: calc(100vh - 150px);
}

/* Dashboard Header */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    background: white;
    padding: 20px 30px;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    width: 100%;
    border: 1px solid #e9ecef;
}

.dashboard-title {
    font-size: 24px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.dashboard-title i {
    color: #2E7D32;
    font-size: 26px;
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
    background: white;
    padding: 20px;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: #4CAF50;
}

.stat-info h3 {
    margin: 0;
    font-size: 14px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.stat-info .number {
    font-size: 32px;
    font-weight: 700;
    color: #0f172a;
    margin-top: 6px;
    line-height: 1;
}

.stat-icon {
    font-size: 42px;
    color: #2E7D32;
    opacity: 0.8;
}

/* Filter Section */
.filter-section {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 20px;
    margin-bottom: 25px;
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
    border: 1px solid #e9ecef;
}

.filter-group {
    flex: 1;
    min-width: 160px;
}

.filter-group label {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 5px;
    font-weight: 600;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    color: #1e293b;
    font-size: 14px;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
}

.btn-filter {
    padding: 10px 20px;
    background: #2E7D32;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #1B5E20;
    transform: translateY(-2px);
}

.btn-reset {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.btn-reset:hover {
    background: #e2e8f0;
}

/* Sites Container */
.sites-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 25px;
    margin-top: 20px;
    width: 100%;
    border: 1px solid #e9ecef;
    margin-bottom: 20px;
}

.sites-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f1f5f9;
    flex-wrap: wrap;
    gap: 15px;
}

.sites-header h2 {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.sites-header h2 i {
    color: #2E7D32;
}

/* Sites List */
.sites-list {
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 20px;
}

.site-item {
    background: #f8fafc;
    border: 1px solid #e9ecef;
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
    background: white;
    border-color: #2E7D32;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transform: translateX(3px);
}

.site-info {
    flex: 1;
}

.site-info h4 {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 6px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.site-info h4 i {
    color: #2E7D32;
    font-size: 14px;
}

.site-info p {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0;
}

.site-info .site-date {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 6px;
}

.site-actions {
    display: flex;
    gap: 10px;
}

/* Action Buttons */
.action-btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-view {
    background: #3498db;
    color: white;
}

.btn-view:hover {
    background: #2980b9;
    transform: translateY(-2px);
}

.btn-edit {
    background: #f39c12;
    color: white;
}

.btn-edit:hover {
    background: #e67e22;
    transform: translateY(-2px);
}

.btn-delete {
    background: #e74c3c;
    color: white;
}

.btn-delete:hover {
    background: #c0392b;
    transform: translateY(-2px);
}

.btn-add-site {
    background: #2E7D32;
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

/* Deployed Items Section */
.deployed-section {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 25px;
    margin-top: 25px;
    border: 1px solid #e9ecef;
    animation: slideDown 0.3s ease;
}

.deployed-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f1f5f9;
    flex-wrap: wrap;
    gap: 15px;
}

.deployed-header h3 {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.deployed-header h3 i {
    color: #2E7D32;
    font-size: 20px;
}

.close-deployed-btn {
    background: #f1f5f9;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    color: #64748b;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.close-deployed-btn:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* Deployed Table */
.deployed-table-container {
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
}

.deployed-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.deployed-table th {
    background: #f8fafc;
    padding: 12px 15px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    border-bottom: 2px solid #e9ecef;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.deployed-table td {
    padding: 12px 15px;
    font-size: 13px;
    color: #334155;
    border-bottom: 1px solid #e9ecef;
}

.deployed-table tbody tr:hover {
    background: #f8fafc;
}

.category-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(102, 126, 234, 0.2);
    color: #667eea;
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

.empty-deployed {
    text-align: center;
    padding: 40px;
    color: #64748b;
}

.empty-deployed i {
    font-size: 48px;
    color: #94a3b8;
    margin-bottom: 16px;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 500px;
    animation: slideInUp 0.3s ease;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #75e6da, #62d4c8);
    border-radius: 20px 20px 0 0;
}

.modal-header h3 {
    font-size: 18px;
    color: white;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-modal {
    background: rgba(255,255,255,0.2);
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}

.close-modal:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: #f8fafc;
    border-radius: 0 0 20px 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #2E7D32;
    color: white;
}

.btn-primary:hover {
    background: #1B5E20;
    transform: translateY(-2px);
}

.btn-secondary {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

.btn-danger {
    background: #e74c3c;
    color: white;
}

.btn-danger:hover {
    background: #c0392b;
}

/* Toast Message */
.toast-message {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 8px;
    background: white;
    color: #1e293b;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10001;
    animation: slideInRight 0.3s ease;
    border-left: 4px solid #2E7D32;
}

.toast-message.success {
    border-left-color: #00b894;
}

.toast-message.error {
    border-left-color: #e74c3c;
}

/* No Data */
.no-data {
    text-align: center;
    padding: 50px 20px;
    background: white;
    border-radius: 16px;
    color: #64748b;
}

.no-data i {
    font-size: 48px;
    color: #94a3b8;
    margin-bottom: 16px;
}

/* Scroll Indicator */
.scroll-indicator {
    text-align: center;
    padding: 20px;
    color: #94a3b8;
    font-size: 12px;
    border-top: 1px dashed #75e6da;
    margin-top: 30px;
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

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

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
    
    .site-item {
        flex-direction: column;
        text-align: center;
    }
    
    .site-actions {
        justify-content: center;
    }
    
    .deployed-table-container {
        max-height: 300px;
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
                <button class="btn-add-site" onclick="openAddSiteModal()">
                    <i class="fas fa-plus-circle"></i> Add New Site
                </button>
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
                        <div class="number"><?php echo number_format($total_sites); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-location-dot"></i>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Date Range</h3>
                        <div class="number" style="font-size: 14px;"><?php echo date('M d', strtotime($date_from)); ?> - <?php echo date('M d', strtotime($date_to)); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-calendar"></i>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> From Date</label>
                    <input type="date" id="dateFrom" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> To Date</label>
                    <input type="date" id="dateTo" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-location-dot"></i> Site/Location</label>
                    <select id="siteFilter">
                        <option value="">All Sites</option>
                        <?php foreach ($site_names as $site): ?>
                            <option value="<?php echo htmlspecialchars($site); ?>" <?php echo $site_filter == $site ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search Item</label>
                    <input type="text" id="searchInput" placeholder="Item No, Description, Reference..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <button class="btn-filter" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply Filter</button>
                <button class="btn-filter btn-reset" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                <button class="btn-filter" onclick="exportData()"><i class="fas fa-download"></i> Export</button>
            </div>

            <!-- Sites List Section -->
            <div class="sites-container">
                <div class="sites-header">
                    <h2>
                        <i class="fas fa-building"></i>
                        Saved Sites
                    </h2>
                    <span style="color: #64748b; font-size: 13px; background: #f1f5f9; padding: 5px 12px; border-radius: 20px;">
                        <i class="fas fa-database"></i> Total: <?php echo count($sites); ?> sites
                    </span>
                </div>
                
                <?php if (empty($sites)): ?>
                    <div class="no-data">
                        <i class="fas fa-building"></i>
                        <p>No sites added yet</p>
                        <div class="sub-text">Click "Add New Site" to create your first site location.</div>
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
                                    <button class="action-btn btn-view" onclick="viewSiteDeployed('<?php echo addslashes($site['site_name']); ?>')">
                                        <i class="fas fa-eye"></i> View
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

            <!-- Deployed Items Section - ITO ANG LALABAS KAPAG MAY VIEW SITE -->
            <?php if ($selected_site && $selected_site_details): ?>
            <div class="deployed-section" id="deployedSection">
                <div class="deployed-header">
                    <h3>
                        <i class="fas fa-boxes"></i>
                        Deployed Items at: <?php echo htmlspecialchars($selected_site_details['site_name']); ?>
                    </h3>
                    <button class="close-deployed-btn" onclick="closeDeployedView()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                
                <?php if ($deployed_items && $deployed_items->num_rows > 0): ?>
                    <div class="deployed-table-container">
                        <table class="deployed-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Item No</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Reference</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($item = $deployed_items->fetch_assoc()): 
                                    $item_no = $item['item_no'] ?? '';
                                    $description = $item['description'] ?? $item['product_name'] ?? 'N/A';
                                    $category = $item['category'] ?? '';
                                ?>
                                    <tr>
                                        <td><?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($item_no ?: 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($description); ?></td>
                                        <td>
                                            <?php if (!empty($category)): ?>
                                                <span class="category-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?></span>
                                            <?php else: ?>
                                                <span class="site-empty">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: #d63031; font-weight: 700;">-<?php echo number_format($item['quantity']); ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                                        <td>
                                            <span style="font-size: 12px; background: #f1f5f9; padding: 4px 8px; border-radius: 6px;">
                                                <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($item['reference'] ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 200px; white-space: normal; word-wrap: break-word;">
                                            <?php echo htmlspecialchars($item['notes'] ?: '—'); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-deployed">
                        <i class="fas fa-box-open"></i>
                        <p>No items have been deployed to this site yet.</p>
                        <div class="sub-text">Pull out items from stock tracker and assign them to this site.</div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="scroll-indicator">
                <i class="fas fa-arrow-down"></i> End of content <i class="fas fa-arrow-down"></i>
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
                    <input type="text" name="site_name" class="form-control" placeholder="e.g., Makati Site, BGC Office, Project Site A..." required>
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
                <small style="color: #64748b;">This action cannot be undone.</small>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteSiteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Site</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter Functions
function applyFilters() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const siteFilter = document.getElementById('siteFilter').value;
    const searchInput = document.getElementById('searchInput').value;
    
    let url = 'site.php?';
    if (dateFrom) url += 'from=' + dateFrom + '&';
    if (dateTo) url += 'to=' + dateTo + '&';
    if (siteFilter) url += 'site=' + encodeURIComponent(siteFilter) + '&';
    if (searchInput) url += 'search=' + encodeURIComponent(searchInput);
    
    window.location.href = url;
}

function resetFilters() {
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    window.location.href = 'site.php?from=' + thirtyDaysAgo.toISOString().split('T')[0] + '&to=' + today.toISOString().split('T')[0];
}

function exportData() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const siteFilter = document.getElementById('siteFilter').value;
    const searchInput = document.getElementById('searchInput').value;
    
    let url = 'export_site_data.php?';
    if (dateFrom) url += 'from=' + dateFrom + '&';
    if (dateTo) url += 'to=' + dateTo + '&';
    if (siteFilter) url += 'site=' + encodeURIComponent(siteFilter) + '&';
    if (searchInput) url += 'search=' + encodeURIComponent(searchInput);
    
    window.open(url, '_blank');
    showToast('Export started!', 'success');
}

// View Site Deployed Items
function viewSiteDeployed(siteName) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('view_site', siteName);
    // Preserve other filter parameters
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const siteFilter = document.getElementById('siteFilter').value;
    const searchInput = document.getElementById('searchInput').value;
    
    if (dateFrom) currentUrl.searchParams.set('from', dateFrom);
    if (dateTo) currentUrl.searchParams.set('to', dateTo);
    if (siteFilter) currentUrl.searchParams.set('site', siteFilter);
    if (searchInput) currentUrl.searchParams.set('search', searchInput);
    
    window.location.href = currentUrl.toString();
}

function closeDeployedView() {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete('view_site');
    window.location.href = currentUrl.toString();
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

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast-message ${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i> <span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Close modals when clicking outside
window.onclick = function(event) {
    const modals = ['addSiteModal', 'editSiteModal', 'deleteSiteModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
};

// Scroll to deployed section when it appears
document.addEventListener('DOMContentLoaded', function() {
    const deployedSection = document.getElementById('deployedSection');
    if (deployedSection) {
        setTimeout(() => {
            deployedSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
});

// Display toast if exists
<?php if (isset($_SESSION['toast'])): ?>
    showToast('<?php echo $_SESSION['toast']['message']; ?>', '<?php echo $_SESSION['toast']['type']; ?>');
    <?php unset($_SESSION['toast']); ?>
<?php endif; ?>
</script>

<?php require_once 'include/footer.php'; ?>