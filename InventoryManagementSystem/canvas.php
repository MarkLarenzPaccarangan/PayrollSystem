<?php
// canvas.php
ob_start();
require_once 'config.php';
requireLogin();

// Get current user
$current_user = getCurrentUser();

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get parameters
$selected_item = isset($_GET['item']) ? intval($_GET['item']) : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : '';
$active_sort = isset($_GET['active_sort']) ? $_GET['active_sort'] : '';

// Get all canvas items for selection
$canvas_items = $conn->query("SELECT * FROM canvas_items ORDER BY item_no");

// Get all active companies
$companies = $conn->query("SELECT * FROM companies WHERE status = 'active' ORDER BY name");
$companies_array = [];
$company_colors = [];
if ($companies && $companies->num_rows > 0) {
    $colors = ['#4e73df', '#1cc88a', '#f6c23e', '#e74a3b', '#36b9cc', '#6f42c1', '#fd7e14', '#20c9a6'];
    $i = 0;
    while($comp = $companies->fetch_assoc()) {
        $companies_array[$comp['id']] = $comp;
        $company_colors[$comp['id']] = $colors[$i % count($colors)];
        $i++;
    }
}

// Base query for ALL ITEMS
$all_items_query = "
    SELECT ci.*, 
           cp.id as price_id,
           cp.company_id,
           cp.quantity as available_quantity,
           cp.price,
           cp.availability,
           c.name as company_name,
           c.contact_person,
           c.contact_number
    FROM canvas_items ci
    INNER JOIN company_prices cp ON ci.id = cp.item_id
    INNER JOIN companies c ON cp.company_id = c.id
    WHERE c.status = 'active'
    ORDER BY ci.item_no ASC, c.name ASC
";

// Default: show ALL items
$items = $conn->query($all_items_query);
$display_mode = 'all';
$sort_label = 'All Items';
$sort_icon = 'fa-list';
$item_info = null;

// Check if we have an active sort and selected item
if ($selected_item > 0 && $active_sort != '') {
    // Get item info for display
    $item_info = $conn->query("SELECT * FROM canvas_items WHERE id = $selected_item")->fetch_assoc();
    
    if ($item_info) {
        $item_no = $conn->real_escape_string($item_info['item_no']);
        
        if ($active_sort == 'company') {
            // Company sort - show ONLY this item's companies
            $query = "
                SELECT ci.*, 
                       cp.id as price_id,
                       cp.company_id,
                       cp.quantity as available_quantity,
                       cp.price,
                       cp.availability,
                       c.name as company_name,
                       c.contact_person,
                       c.contact_number
                FROM canvas_items ci
                INNER JOIN company_prices cp ON ci.id = cp.item_id
                INNER JOIN companies c ON cp.company_id = c.id
                WHERE c.status = 'active' AND ci.id = $selected_item
            ";
            
            if ($sort_by == 'company_asc') {
                $query .= " ORDER BY c.name ASC, cp.price ASC";
                $sort_label = 'Company A-Z';
                $sort_icon = 'fa-sort-alpha-down';
            } else if ($sort_by == 'company_desc') {
                $query .= " ORDER BY c.name DESC, cp.price ASC";
                $sort_label = 'Company Z-A';
                $sort_icon = 'fa-sort-alpha-up';
            }
            
            $items = $conn->query($query);
            $display_mode = 'company';
            
        } else if ($active_sort == 'price') {
            // Price sort - show ALL sellers of this item number
            $query = "
                SELECT ci.*, 
                       cp.id as price_id,
                       cp.company_id,
                       cp.quantity as available_quantity,
                       cp.price,
                       cp.availability,
                       c.name as company_name,
                       c.contact_person,
                       c.contact_number
                FROM canvas_items ci
                INNER JOIN company_prices cp ON ci.id = cp.item_id
                INNER JOIN companies c ON cp.company_id = c.id
                WHERE c.status = 'active' AND ci.item_no = '$item_no'
            ";
            
            if ($sort_by == 'price_asc') {
                $query .= " ORDER BY cp.price ASC, c.name ASC";
                $sort_label = 'Price Low to High';
                $sort_icon = 'fa-sort-amount-down';
            } else if ($sort_by == 'price_desc') {
                $query .= " ORDER BY cp.price DESC, c.name ASC";
                $sort_label = 'Price High to Low';
                $sort_icon = 'fa-sort-amount-up';
            }
            
            $items = $conn->query($query);
            $display_mode = 'price';
        }
    }
}

// Get statistics
$totalItems = $conn->query("SELECT COUNT(*) as count FROM canvas_items")->fetch_assoc()['count'] ?? 0;
$totalCompanies = $conn->query("SELECT COUNT(*) as count FROM companies WHERE status = 'active'")->fetch_assoc()['count'] ?? 0;

require_once 'include/header.php';
?>

<style>
/* Canvas Page Specific Styles */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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

/* Company badge */
.company-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

/* Contact person style */
.contact-person {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 3px;
}

/* Contact number style */
.contact-number {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 3px;
}

.contact-number i {
    color: #75e6da;
    font-size: 10px;
}

/* Price cell */
.price-cell {
    font-weight: 600;
    color: #75e6da;
}

/* Total price cell */
.total-price-cell {
    font-weight: 700;
    color: #6c5ce7;
    background: rgba(108, 92, 231, 0.1);
    padding: 5px 10px;
    border-radius: 20px;
    display: inline-block;
}

/* Availability badge */
.availability-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    display: inline-block;
}

.available {
    background: rgba(117, 230, 218, 0.15);
    color: #75e6da;
}

.unavailable {
    background: rgba(214, 48, 49, 0.15);
    color: #d63031;
}

/* Action button - Add to Cart */
.action-btn.add-to-cart {
    background: linear-gradient(135deg, #6c5ce7, #75e6da);
    color: white;
    width: auto;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.action-btn.add-to-cart:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(108, 92, 231, 0.3);
}

.action-btn.add-to-cart:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Action button - Add Company */
.action-btn.add-company {
    background: linear-gradient(135deg, #00b894, #6c5ce7);
    color: white;
    width: auto;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: 10px;
}

.action-btn.add-company:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 184, 148, 0.3);
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
    min-width: 1100px;
}

.products-table th {
    padding: 15px 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}

.products-table th i {
    margin-left: 5px;
    font-size: 10px;
    color: #75e6da;
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

/* Welcome section */
.welcome-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
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

.welcome-actions {
    display: flex;
    gap: 10px;
}

/* Item selection */
.item-selector {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.item-selector label {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 14px;
    white-space: nowrap;
}

.item-selector select {
    flex: 1;
    min-width: 300px;
    padding: 12px 15px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.item-selector select:hover {
    border-color: #75e6da;
}

.item-selector select:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

/* Display mode badges */
.display-mode-badge {
    background: linear-gradient(135deg, #6c757d, #495057);
    color: white;
    padding: 8px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.display-mode-badge i {
    font-size: 14px;
}

.display-mode-badge.company-mode {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
}

.display-mode-badge.price-mode {
    background: linear-gradient(135deg, #ff7675, #e84342);
}

/* Selected item indicator */
.selected-item-indicator {
    background: rgba(117, 230, 218, 0.1);
    color: #75e6da;
    padding: 8px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.selected-item-indicator i {
    font-size: 14px;
}

/* Top bar with search and sort */
.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
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

/* Sort buttons */
.sort-buttons {
    display: flex;
    gap: 10px;
    align-items: center;
}

.sort-group {
    display: flex;
    align-items: center;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}

.sort-group-title {
    padding: 10px 15px;
    background: var(--bg-primary);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 600;
    border-right: 2px solid var(--border-color);
}

.sort-btn {
    padding: 10px 15px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: none;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.sort-btn:hover {
    background: var(--bg-primary);
    color: #75e6da;
}

.sort-btn.active {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.sort-btn.price-active {
    background: linear-gradient(135deg, #ff7675, #e84342) !important;
    color: white;
}

.sort-btn i {
    font-size: 12px;
}

/* Display info */
.display-info {
    background: rgba(117, 230, 218, 0.1);
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    border-left: 4px solid #75e6da;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.display-info.price-view {
    border-left-color: #ff7675;
    background: rgba(255, 118, 117, 0.1);
}

.display-info h3 {
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.display-info h3 i {
    color: #75e6da;
}

.display-info.price-view h3 i {
    color: #ff7675;
}

.display-info p {
    color: var(--text-secondary);
    font-size: 13px;
    margin-top: 5px;
}

.sort-indicator {
    background: rgba(117, 230, 218, 0.2);
    padding: 8px 20px;
    border-radius: 30px;
    color: #75e6da;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.price-view .sort-indicator {
    background: rgba(255, 118, 117, 0.2);
    color: #ff7675;
}

/* Item count badge */
.item-count-badge {
    background: var(--bg-primary);
    border-radius: 20px;
    padding: 5px 15px;
    font-size: 12px;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    margin-left: 10px;
}

.item-count-badge span {
    color: #75e6da;
    font-weight: 700;
    margin-right: 5px;
}

/* Add Company Modal Styles */
.company-modal {
    max-width: 1200px !important;
    width: 95% !important;
}

.company-modal .modal-header {
    background: linear-gradient(135deg, #00b894, #6c5ce7);
}

.company-search {
    margin-bottom: 20px;
    position: relative;
}

.company-search i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
}

.company-search input {
    width: 100%;
    padding: 12px 15px 12px 45px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
}

.company-table-container {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 20px;
}

.company-table {
    width: 100%;
    border-collapse: collapse;
}

.company-table th {
    position: sticky;
    top: 0;
    background: var(--bg-primary);
    padding: 12px 10px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    z-index: 10;
}

.company-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
    font-size: 13px;
    color: var(--text-primary);
}

.company-table tbody tr:hover {
    background: var(--bg-secondary);
    cursor: pointer;
}

.company-table tbody tr.selected {
    background: rgba(117, 230, 218, 0.1);
    border-left: 3px solid #75e6da;
}

.company-badge-small {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    color: white;
    font-size: 11px;
    font-weight: 500;
}

.modal-footer {
    padding: 20px 25px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 15px;
}

/* Modal Styles - Scrollable */
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
    background: var(--bg-primary);
    margin: 30px auto;
    padding: 0;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s ease;
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.modal-header {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    padding: 20px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    flex-shrink: 0;
}

.modal-header h2 {
    color: white;
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-header h2 i {
    font-size: 24px;
}

.close-modal {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
}

.close-modal:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 25px;
    overflow-y: auto;
    flex: 1;
}

/* Item Details Grid */
.item-details-grid {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.detail-item.full-width {
    grid-column: span 2;
}

.detail-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    padding: 8px 12px;
    background: var(--bg-primary);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.detail-value.company-badge-modal {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 20px;
    color: white;
    font-weight: 600;
}

.detail-value.price-highlight {
    color: #75e6da;
    font-weight: 700;
    font-size: 18px;
}

/* Quantity Selector */
.quantity-section {
    background: linear-gradient(135deg, rgba(117, 230, 218, 0.1), rgba(108, 92, 231, 0.1));
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid rgba(117, 230, 218, 0.3);
}

.quantity-section h3 {
    color: var(--text-primary);
    font-size: 14px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.quantity-section h3 i {
    color: #75e6da;
}

.quantity-control {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.quantity-input-group {
    display: flex;
    align-items: center;
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
}

.quantity-btn {
    width: 45px;
    height: 45px;
    background: var(--bg-secondary);
    border: none;
    color: var(--text-primary);
    font-size: 20px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.quantity-btn:hover:not(:disabled) {
    background: #75e6da;
    color: white;
}

.quantity-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.quantity-input-group input {
    width: 100px;
    height: 45px;
    border: none;
    border-left: 2px solid var(--border-color);
    border-right: 2px solid var(--border-color);
    text-align: center;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-primary);
}

.quantity-input-group input:focus {
    outline: none;
}

.available-stock {
    color: var(--text-secondary);
    font-size: 14px;
}

.available-stock span {
    color: #75e6da;
    font-weight: 700;
    font-size: 18px;
}

/* Total Price Display */
.total-price-display {
    text-align: center;
    padding: 20px;
    background: var(--bg-primary);
    border-radius: 10px;
    margin: 15px 0;
    border: 2px dashed var(--border-color);
}

.total-price-display .label {
    font-size: 13px;
    color: var(--text-secondary);
    display: block;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.total-price-display .amount {
    font-size: 36px;
    font-weight: 700;
    color: #6c5ce7;
    line-height: 1.2;
}

.total-price-display .amount small {
    font-size: 16px;
    font-weight: 400;
    color: var(--text-secondary);
}

/* Modal Actions */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid var(--border-color);
    flex-wrap: wrap;
}

.btn-cancel {
    padding: 12px 30px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-cancel:hover {
    background: var(--border-color);
    border-color: var(--text-secondary);
}

.btn-save-cart {
    padding: 12px 35px;
    background: linear-gradient(135deg, #00b894, #75e6da);
    border: none;
    border-radius: 8px;
    color: white;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 10px rgba(0, 184, 148, 0.3);
}

.btn-save-cart:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 184, 148, 0.4);
}

.btn-add-to-cart-modal {
    padding: 12px 35px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    border: none;
    border-radius: 8px;
    color: white;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 10px rgba(108, 92, 231, 0.3);
}

.btn-add-to-cart-modal:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(108, 92, 231, 0.4);
}

.btn-add-to-cart-modal:disabled,
.btn-save-cart:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-select-company {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-select-company:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(108, 92, 231, 0.3);
}

/* Cart notification */
.cart-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
    padding: 15px 25px;
    border-radius: 10px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 10000;
    animation: slideInRight 0.3s ease;
}

.cart-notification.error {
    background: linear-gradient(135deg, #ff7675, #d63031);
}

.cart-notification i {
    font-size: 20px;
}

.cart-notification.fade-out {
    animation: fadeOut 0.3s ease forwards;
}

/* Loading spinner */
.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from {
        transform: translateY(-50px);
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

@keyframes fadeOut {
    to {
        opacity: 0;
        transform: translateX(100%);
    }
}

/* Animations */
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
</style>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Canvas Price Comparison</h1>
        <p>Compare prices across different suppliers</p>
    </div>
    <div class="welcome-actions">
        <a href="purchase.php" class="btn btn-primary">
            <i class="fas fa-clipboard-list"></i>
            View Purchases
        </a>
        <button class="action-btn add-company" onclick="openCompanyModal()">
            <i class="fas fa-building"></i>
            Add Company
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-box"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL ITEMS</h3>
            <p class="stat-value"><?php echo number_format($totalItems); ?></p>
            <span class="stat-trend positive">Canvas products</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-building"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL COMPANIES</h3>
            <p class="stat-value"><?php echo number_format($totalCompanies); ?></p>
            <span class="stat-trend positive">Active suppliers</span>
        </div>
    </div>
</div>

<!-- Item Selector -->
<div class="item-selector">
    <label for="itemSelect"><i class="fas fa-box"></i> Select Item:</label>
    <select id="itemSelect" onchange="selectItem(this.value)">
        <option value="0">-- Select an Item to Filter --</option>
        <?php 
        if ($canvas_items && $canvas_items->num_rows > 0) {
            while($item = $canvas_items->fetch_assoc()) {
                $selected = ($selected_item == $item['id']) ? 'selected' : '';
                echo "<option value='{$item['id']}' $selected>" . htmlspecialchars($item['item_no']) . " - " . htmlspecialchars($item['description']) . "</option>";
            }
        }
        ?>
    </select>
    
    <!-- Display Mode Badge -->
    <?php if($display_mode == 'all'): ?>
        <div class="display-mode-badge">
            <i class="fas fa-list"></i> VIEWING ALL ITEMS
        </div>
    <?php elseif($display_mode == 'company' && $item_info): ?>
        <div class="display-mode-badge company-mode">
            <i class="fas fa-building"></i> VIEWING: <?php echo htmlspecialchars($item_info['item_no']); ?> (Company Sort)
        </div>
    <?php elseif($display_mode == 'price' && $item_info): ?>
        <div class="display-mode-badge price-mode">
            <i class="fas fa-tags"></i> VIEWING: <?php echo htmlspecialchars($item_info['item_no']); ?> (All Sellers)
        </div>
    <?php endif; ?>
</div>

<!-- Display Info -->
<?php if($display_mode == 'all'): ?>
    <div class="display-info">
        <div>
            <h3><i class="fas fa-list"></i> All Items</h3>
            <p>Showing all items with prices • Default view</p>
            <?php if($items): ?>
                <span class="item-count-badge">
                    <span><?php echo $items->num_rows; ?></span> price entries
                </span>
            <?php endif; ?>
        </div>
    </div>
<?php elseif($display_mode == 'company' && $item_info): ?>
    <div class="display-info">
        <div>
            <h3><i class="fas fa-building"></i> <?php echo htmlspecialchars($item_info['item_no']); ?> - <?php echo htmlspecialchars($item_info['description']); ?></h3>
            <p>Showing companies for this item • Sorted by Company Name</p>
        </div>
        <div class="sort-indicator">
            <i class="fas <?php echo $sort_icon; ?>"></i> 
            <?php echo $sort_label; ?>
        </div>
    </div>
<?php elseif($display_mode == 'price' && $item_info): ?>
    <div class="display-info price-view">
        <div>
            <h3><i class="fas fa-tags"></i> <?php echo htmlspecialchars($item_info['item_no']); ?> - <?php echo htmlspecialchars($item_info['description']); ?></h3>
            <p>Showing ALL sellers • Sorted by Price</p>
            <?php if($items): ?>
                <span class="item-count-badge">
                    <span><?php echo $items->num_rows; ?></span> sellers
                </span>
            <?php endif; ?>
        </div>
        <div class="sort-indicator">
            <i class="fas <?php echo $sort_icon; ?>"></i> 
            <?php echo $sort_label; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Top Bar with Search and Sort Buttons -->
<div class="top-bar">
    <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" id="canvasSearch" placeholder="Search..." onkeyup="searchItems()">
    </div>
    
    <div class="sort-buttons">
        <!-- Sort by Company -->
        <div class="sort-group">
            <span class="sort-group-title"><i class="fas fa-building"></i> Company</span>
            <button class="sort-btn <?php echo ($active_sort == 'company' && $sort_by == 'company_asc') ? 'active' : ''; ?>" onclick="applyCompanySort('company_asc')" title="Company A to Z">
                <i class="fas fa-sort-alpha-down"></i> A-Z
            </button>
            <button class="sort-btn <?php echo ($active_sort == 'company' && $sort_by == 'company_desc') ? 'active' : ''; ?>" onclick="applyCompanySort('company_desc')" title="Company Z to A">
                <i class="fas fa-sort-alpha-up"></i> Z-A
            </button>
        </div>
        
        <!-- Sort by Price - Shows ALL sellers of selected item -->
        <div class="sort-group">
            <span class="sort-group-title"><i class="fas fa-tag"></i> Price</span>
            <button class="sort-btn <?php echo ($active_sort == 'price' && $sort_by == 'price_asc') ? 'price-active active' : ''; ?>" onclick="applyPriceSort('price_asc')" title="Price Low to High (Shows All Sellers)">
                <i class="fas fa-sort-amount-down"></i> Low-High
            </button>
            <button class="sort-btn <?php echo ($active_sort == 'price' && $sort_by == 'price_desc') ? 'price-active active' : ''; ?>" onclick="applyPriceSort('price_desc')" title="Price High to Low (Shows All Sellers)">
                <i class="fas fa-sort-amount-up"></i> High-Low
            </button>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="table-wrapper">
    <table class="products-table" id="canvasTable">
        <thead>
            <tr>
                <th>Item No</th>
                <th>Description</th>
                <th>Company 
                    <?php if($active_sort == 'company'): ?>
                        <i class="fas <?php echo $sort_by == 'company_asc' ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                    <?php endif; ?>
                </th>
                <th>Contact Person</th>
                <th>Contact Number</th>
                <th>Available Qty</th>
                <th>Price 
                    <?php if($active_sort == 'price'): ?>
                        <i class="fas <?php echo $sort_by == 'price_asc' ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                    <?php endif; ?>
                </th>
                <th>Total Price</th>
                <th>Availability</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="canvasTableBody">
            <?php if ($items && $items->num_rows > 0): ?>
                <?php 
                $items->data_seek(0);
                $row_count = 0;
                while($row = $items->fetch_assoc()): 
                    $total_price = $row['available_quantity'] * $row['price'];
                    $row_count++;
                ?>
                    <tr class="item-row" 
                        data-item-no="<?php echo htmlspecialchars($row['item_no']); ?>" 
                        data-description="<?php echo htmlspecialchars($row['description']); ?>"
                        data-company="<?php echo htmlspecialchars($row['company_name']); ?>"
                        data-contact="<?php echo htmlspecialchars($row['contact_person'] ?? ''); ?>"
                        data-contact-number="<?php echo htmlspecialchars($row['contact_number'] ?? ''); ?>"
                        data-price-id="<?php echo $row['price_id']; ?>"
                        data-quantity="<?php echo $row['available_quantity']; ?>"
                        data-price="<?php echo $row['price']; ?>"
                        data-availability="<?php echo $row['availability']; ?>"
                        data-company-color="<?php echo $company_colors[$row['company_id']] ?? '#6c757d'; ?>">
                        
                        <td><strong><?php echo htmlspecialchars($row['item_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                        
                        <!-- Company Name with Color -->
                        <td>
                            <span class="company-badge" style="background: <?php echo $company_colors[$row['company_id']] ?? '#6c757d'; ?>">
                                <?php echo htmlspecialchars($row['company_name']); ?>
                            </span>
                        </td>
                        
                        <!-- Contact Person -->
                        <td>
                            <?php if (!empty($row['contact_person'])): ?>
                                <span class="contact-person">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($row['contact_person']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Contact Number -->
                        <td>
                            <?php if (!empty($row['contact_number'])): ?>
                                <span class="contact-number">
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['contact_number']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Available Quantity -->
                        <td class="quantity-cell" id="qty-<?php echo $row['price_id']; ?>"><?php echo number_format($row['available_quantity']); ?></td>
                        
                        <!-- Price -->
                        <td class="price-cell">₱<?php echo number_format($row['price'], 2); ?></td>
                        
                        <!-- Total Price -->
                        <td>
                            <span class="total-price-cell" id="total-<?php echo $row['price_id']; ?>">
                                ₱<?php echo number_format($total_price, 2); ?>
                            </span>
                        </td>
                        
                        <!-- Availability -->
                        <td>
                            <span class="availability-badge <?php echo $row['availability'] ? 'available' : 'unavailable'; ?>" id="avail-<?php echo $row['price_id']; ?>">
                                <i class="fas <?php echo $row['availability'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> 
                                <?php echo $row['availability'] ? 'In Stock' : 'Out of Stock'; ?>
                            </span>
                        </td>
                        
                        <!-- Actions -->
                        <td>
                            <?php if($row['availability'] && $row['available_quantity'] > 0): ?>
                                <button class="action-btn add-to-cart" onclick="openCartModal(this)" id="btn-<?php echo $row['price_id']; ?>">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                            <?php else: ?>
                                <button class="action-btn add-to-cart" disabled>
                                    <i class="fas fa-cart-plus"></i> Unavailable
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 60px;">
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>No Items Found</h3>
                            <p>No items with prices found in the system.</p>
                            <a href="canvas_items.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Items
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- No Results Message (for search) -->
<div id="noResultsMessage" style="display: none; text-align: center; padding: 40px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; margin-top: 20px;">
    <i class="fas fa-search" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 15px;"></i>
    <h3 style="color: var(--text-primary); margin-bottom: 10px;">No Items Found</h3>
    <p style="color: var(--text-secondary);">No items match your search criteria.</p>
</div>

<!-- Add to Cart Modal - Scrollable -->
<div id="cartModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-shopping-cart"></i> Add to Cart</h2>
            <span class="close-modal" onclick="closeCartModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Item Details -->
            <div class="item-details-grid" id="modalItemDetails">
                <!-- Details will be populated by JavaScript -->
            </div>
            
            <!-- Quantity Selector -->
            <div class="quantity-section">
                <h3><i class="fas fa-cubes"></i> Select Quantity</h3>
                <div class="quantity-control">
                    <div class="quantity-input-group">
                        <button class="quantity-btn" onclick="decreaseQuantity()" id="decreaseQtyBtn">−</button>
                        <input type="number" id="cartQuantity" value="1" min="1" onchange="updateTotalPrice()" onkeyup="updateTotalPrice()">
                        <button class="quantity-btn" onclick="increaseQuantity()" id="increaseQtyBtn">+</button>
                    </div>
                    <div class="available-stock">
                        Available Stock: <span id="availableStock">0</span>
                    </div>
                </div>
                
                <!-- Total Price Display -->
                <div class="total-price-display">
                    <span class="label">Total Price:</span>
                    <span class="amount" id="modalTotalPrice">₱0.00</span>
                </div>
            </div>
            
            <!-- Modal Actions -->
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeCartModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-add-to-cart-modal" onclick="addToCart()" id="addToCartBtn">
                    <i class="fas fa-cart-plus"></i> Add to Cart
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Company Modal -->
<div id="companyModal" class="modal">
    <div class="modal-content company-modal">
        <div class="modal-header">
            <h2><i class="fas fa-building"></i> Select Company</h2>
            <span class="close-modal" onclick="closeCompanyModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Search -->
            <div class="company-search">
                <i class="fas fa-search"></i>
                <input type="text" id="companySearch" placeholder="Search by Item No, Description, Company..." onkeyup="searchCompanies()">
            </div>
            
            <!-- Companies Table -->
            <div class="company-table-container">
                <table class="company-table" id="companyTable">
                    <thead>
                        <tr>
                            <th>Item No</th>
                            <th>Description</th>
                            <th>Company</th>
                            <th>Contact Person</th>
                            <th>Contact Number</th>
                            <th>Available Qty</th>
                            <th>Price</th>
                            <th>Total Price</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="companyTableBody">
                        <?php 
                        // Reset items pointer to beginning
                        if ($items && $items->num_rows > 0) {
                            $items->data_seek(0);
                            while($row = $items->fetch_assoc()): 
                                $total_price = $row['available_quantity'] * $row['price'];
                        ?>
                            <tr class="company-row" 
                                data-item-no="<?php echo htmlspecialchars($row['item_no']); ?>"
                                data-description="<?php echo htmlspecialchars($row['description']); ?>"
                                data-company="<?php echo htmlspecialchars($row['company_name']); ?>"
                                data-contact="<?php echo htmlspecialchars($row['contact_person'] ?? ''); ?>"
                                data-contact-number="<?php echo htmlspecialchars($row['contact_number'] ?? ''); ?>"
                                data-price-id="<?php echo $row['price_id']; ?>"
                                data-quantity="<?php echo $row['available_quantity']; ?>"
                                data-price="<?php echo $row['price']; ?>"
                                data-company-color="<?php echo $company_colors[$row['company_id']] ?? '#6c757d'; ?>">
                                <td><strong><?php echo htmlspecialchars($row['item_no']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                <td>
                                    <span class="company-badge-small" style="background: <?php echo $company_colors[$row['company_id']] ?? '#6c757d'; ?>">
                                        <?php echo htmlspecialchars($row['company_name']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['contact_person'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['contact_number'] ?? '—'); ?></td>
                                <td><?php echo number_format($row['available_quantity']); ?></td>
                                <td>₱<?php echo number_format($row['price'], 2); ?></td>
                                <td>₱<?php echo number_format($total_price, 2); ?></td>
                                <td>
                                    <button class="btn-select-company" onclick="selectCompany(this)">
                                        <i class="fas fa-check"></i> Select
                                    </button>
                                </td>
                            </tr>
                        <?php 
                            endwhile;
                        } else { ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-building" style="font-size: 40px; color: var(--text-secondary); margin-bottom: 10px;"></i>
                                    <p>No companies found</p>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            
            <div style="color: var(--text-secondary); font-size: 12px; text-align: right;">
                <i class="fas fa-info-circle"></i> Click "Select" to add this company's item to cart
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeCompanyModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<script>
// Cart data
let currentCartItem = {
    priceId: null,
    itemNo: '',
    description: '',
    companyName: '',
    contactPerson: '',
    contactNumber: '',
    availableQuantity: 0,
    price: 0,
    companyColor: '',
    rowElement: null
};

// Select item - stores selection
function selectItem(itemId) {
    if (itemId > 0) {
        // Just update URL with selected item, but keep current display
        const url = new URL(window.location.href);
        url.searchParams.set('item', itemId);
        // Keep current active sort and sort by
        const currentActiveSort = '<?php echo $active_sort; ?>';
        const currentSortBy = '<?php echo $sort_by; ?>';
        if (currentActiveSort) {
            url.searchParams.set('active_sort', currentActiveSort);
        }
        if (currentSortBy) {
            url.searchParams.set('sort', currentSortBy);
        }
        window.location.href = url.toString();
    } else {
        // If "Select an Item" is chosen, remove item parameter but keep display
        const url = new URL(window.location.href);
        url.searchParams.delete('item');
        window.location.href = url.toString();
    }
}

// Apply company sort
function applyCompanySort(sortValue) {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', sortValue);
    url.searchParams.set('active_sort', 'company');
    const selectedItem = '<?php echo $selected_item; ?>';
    if (selectedItem > 0) {
        url.searchParams.set('item', selectedItem);
    }
    window.location.href = url.toString();
}

// Apply price sort
function applyPriceSort(sortValue) {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', sortValue);
    url.searchParams.set('active_sort', 'price');
    const selectedItem = '<?php echo $selected_item; ?>';
    if (selectedItem > 0) {
        url.searchParams.set('item', selectedItem);
    }
    window.location.href = url.toString();
}

// Open Cart Modal
function openCartModal(button) {
    const row = button.closest('tr');
    
    // Get data from row attributes
    currentCartItem = {
        priceId: row.getAttribute('data-price-id'),
        itemNo: row.getAttribute('data-item-no'),
        description: row.getAttribute('data-description'),
        companyName: row.getAttribute('data-company'),
        contactPerson: row.getAttribute('data-contact'),
        contactNumber: row.getAttribute('data-contact-number'),
        availableQuantity: parseInt(row.getAttribute('data-quantity')),
        price: parseFloat(row.getAttribute('data-price')),
        companyColor: row.getAttribute('data-company-color'),
        rowElement: row
    };
    
    // Populate modal with item details
    populateModalDetails();
    
    // Reset quantity to 1
    document.getElementById('cartQuantity').value = 1;
    document.getElementById('cartQuantity').max = currentCartItem.availableQuantity;
    
    // Update buttons state
    updateQuantityButtons();
    
    // Calculate and display total price
    updateTotalPrice();
    
    // Show modal
    document.getElementById('cartModal').style.display = 'block';
    
    // Prevent body scrolling when modal is open
    document.body.style.overflow = 'hidden';
}

// Populate modal details
function populateModalDetails() {
    const detailsHtml = `
        <div class="detail-item">
            <span class="detail-label">Item No</span>
            <span class="detail-value">${currentCartItem.itemNo}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Description</span>
            <span class="detail-value">${currentCartItem.description}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Company</span>
            <span class="detail-value company-badge-modal" style="background: ${currentCartItem.companyColor}">${currentCartItem.companyName}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Contact Person</span>
            <span class="detail-value">${currentCartItem.contactPerson || '—'}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Contact Number</span>
            <span class="detail-value">${currentCartItem.contactNumber || '—'}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Available Quantity</span>
            <span class="detail-value">${currentCartItem.availableQuantity.toLocaleString()}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Price per Unit</span>
            <span class="detail-value price-highlight">₱${currentCartItem.price.toFixed(2)}</span>
        </div>
    `;
    
    document.getElementById('modalItemDetails').innerHTML = detailsHtml;
    document.getElementById('availableStock').textContent = currentCartItem.availableQuantity.toLocaleString();
}

// Increase quantity
function increaseQuantity() {
    const input = document.getElementById('cartQuantity');
    let value = parseInt(input.value) || 1;
    if (value < currentCartItem.availableQuantity) {
        input.value = value + 1;
        updateTotalPrice();
        updateQuantityButtons();
    }
}

// Decrease quantity
function decreaseQuantity() {
    const input = document.getElementById('cartQuantity');
    let value = parseInt(input.value) || 1;
    if (value > 1) {
        input.value = value - 1;
        updateTotalPrice();
        updateQuantityButtons();
    }
}

// Update quantity buttons state
function updateQuantityButtons() {
    const input = document.getElementById('cartQuantity');
    const value = parseInt(input.value) || 1;
    
    document.getElementById('decreaseQtyBtn').disabled = (value <= 1);
    document.getElementById('increaseQtyBtn').disabled = (value >= currentCartItem.availableQuantity);
}

// Update total price
function updateTotalPrice() {
    const input = document.getElementById('cartQuantity');
    let quantity = parseInt(input.value) || 1;
    
    // Validate quantity
    if (quantity < 1) quantity = 1;
    if (quantity > currentCartItem.availableQuantity) quantity = currentCartItem.availableQuantity;
    
    input.value = quantity;
    
    const total = quantity * currentCartItem.price;
    document.getElementById('modalTotalPrice').innerHTML = `₱${total.toFixed(2)} <small>(${quantity} x ₱${currentCartItem.price.toFixed(2)})</small>`;
    
    updateQuantityButtons();
}

// Add to cart function - GOES TO PURCHASE.PHP (HINDI SA STOCK_TRACKER)
function addToCart() {
    const quantity = parseInt(document.getElementById('cartQuantity').value);
    
    // Check if quantity is valid
    if (quantity > currentCartItem.availableQuantity) {
        showNotification('Cannot add more than available stock!', 'error');
        return;
    }
    
    // Show loading
    const addToCartBtn = document.getElementById('addToCartBtn');
    const originalText = addToCartBtn.innerHTML;
    addToCartBtn.innerHTML = '<span class="loading-spinner"></span> Adding...';
    addToCartBtn.disabled = true;
    
    // Prepare data for database - PENDING status (HINDI COMPLETED)
    const purchaseData = {
        price_id: currentCartItem.priceId,
        item_no: currentCartItem.itemNo,
        description: currentCartItem.description,
        company_name: currentCartItem.companyName,
        contact_person: currentCartItem.contactPerson,
        contact_number: currentCartItem.contactNumber,
        quantity: quantity,
        price: currentCartItem.price,
        total: quantity * currentCartItem.price,
        company_color: currentCartItem.companyColor
        // WALANG status - default ay 'pending' sa process_purchase.php
    };
    
    console.log('Sending to process_purchase.php:', purchaseData);
    
    // Send to server
    fetch('process_purchase.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(purchaseData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success notification
            showNotification(`
                <strong>✅ Item added to Purchase List!</strong><br>
                Item: ${currentCartItem.itemNo} - ${currentCartItem.description}<br>
                Company: ${currentCartItem.companyName}<br>
                Quantity: ${quantity}<br>
                <small style="color: #75e6da;">✓ Redirecting to purchase.php...</small>
            `, 'success');
            
            // Close modal
            closeCartModal();
            
            // REDIRECT TO PURCHASE.PHP (HINDI STOCK_TRACKER)
            setTimeout(() => {
                window.location.href = 'purchase.php';
            }, 1500);
        } else {
            showNotification('Error: ' + data.message, 'error');
            addToCartBtn.innerHTML = originalText;
            addToCartBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error adding to cart. Check console.', 'error');
        addToCartBtn.innerHTML = originalText;
        addToCartBtn.disabled = false;
    });
}

// Open Company Modal
function openCompanyModal() {
    document.getElementById('companyModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Reset search
    document.getElementById('companySearch').value = '';
    searchCompanies();
}

// Close Company Modal
function closeCompanyModal() {
    document.getElementById('companyModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Select company from modal
function selectCompany(button) {
    const row = button.closest('tr');
    
    // Set current cart item from company row
    currentCartItem = {
        priceId: row.getAttribute('data-price-id'),
        itemNo: row.getAttribute('data-item-no'),
        description: row.getAttribute('data-description'),
        companyName: row.getAttribute('data-company'),
        contactPerson: row.getAttribute('data-contact'),
        contactNumber: row.getAttribute('data-contact-number'),
        availableQuantity: parseInt(row.getAttribute('data-quantity')),
        price: parseFloat(row.getAttribute('data-price')),
        companyColor: row.getAttribute('data-company-color'),
        rowElement: null
    };
    
    // Close company modal
    closeCompanyModal();
    
    // Open cart modal with selected company
    setTimeout(() => {
        // Populate cart modal with company data
        populateModalDetails();
        
        // Reset quantity to 1
        document.getElementById('cartQuantity').value = 1;
        document.getElementById('cartQuantity').max = currentCartItem.availableQuantity;
        
        // Update buttons state
        updateQuantityButtons();
        
        // Calculate and display total price
        updateTotalPrice();
        
        // Show cart modal
        document.getElementById('cartModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }, 300);
}

// Search companies in modal
function searchCompanies() {
    const searchTerm = document.getElementById('companySearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#companyTableBody .company-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const itemNo = row.cells[0]?.textContent.toLowerCase() || '';
        const description = row.cells[1]?.textContent.toLowerCase() || '';
        const company = row.cells[2]?.textContent.toLowerCase() || '';
        const contact = row.cells[3]?.textContent.toLowerCase() || '';
        const contactNumber = row.cells[4]?.textContent.toLowerCase() || '';
        
        const matches = itemNo.includes(searchTerm) || 
                       description.includes(searchTerm) || 
                       company.includes(searchTerm) ||
                       contact.includes(searchTerm) ||
                       contactNumber.includes(searchTerm);
        
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
    
    // Optional: Show "no results" message
    const tableBody = document.getElementById('companyTableBody');
    const noResultsRow = document.getElementById('companyNoResults');
    
    if (visibleCount === 0 && searchTerm !== '') {
        if (!noResultsRow) {
            const newRow = document.createElement('tr');
            newRow.id = 'companyNoResults';
            newRow.innerHTML = '<td colspan="9" style="text-align: center; padding: 40px;"><i class="fas fa-search" style="font-size: 40px; color: var(--text-secondary); margin-bottom: 10px;"></i><p>No companies match your search</p></td>';
            tableBody.appendChild(newRow);
        }
    } else {
        if (noResultsRow) {
            noResultsRow.remove();
        }
    }
}

// Show notification
function showNotification(message, type = 'success') {
    // Remove existing notification
    const existingNotification = document.querySelector('.cart-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create notification
    const notification = document.createElement('div');
    notification.className = `cart-notification ${type}`;
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 5000);
}

// Close modal
function closeCartModal() {
    document.getElementById('cartModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Search function
function searchItems() {
    const searchTerm = document.getElementById('canvasSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#canvasTableBody .item-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const itemNo = row.getAttribute('data-item-no')?.toLowerCase() || '';
        const description = row.getAttribute('data-description')?.toLowerCase() || '';
        const company = row.getAttribute('data-company')?.toLowerCase() || '';
        const contact = row.getAttribute('data-contact')?.toLowerCase() || '';
        const contactNumber = row.getAttribute('data-contact-number')?.toLowerCase() || '';
        
        const matches = itemNo.includes(searchTerm) || 
                       description.includes(searchTerm) || 
                       company.includes(searchTerm) ||
                       contact.includes(searchTerm) ||
                       contactNumber.includes(searchTerm);
        
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

// Close modal when clicking outside
window.onclick = function(event) {
    const cartModal = document.getElementById('cartModal');
    const companyModal = document.getElementById('companyModal');
    
    if (event.target == cartModal) {
        closeCartModal();
    }
    if (event.target == companyModal) {
        closeCompanyModal();
    }
}

// Debounced search
let searchTimeout;
document.getElementById('canvasSearch')?.addEventListener('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchItems, 300);
});

// Auto-hide alerts after 3 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        if (alert.style.display !== 'none') {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }
    });
}, 3000);
</script>

<?php 
$conn->close();
require_once 'include/footer.php'; 
?>