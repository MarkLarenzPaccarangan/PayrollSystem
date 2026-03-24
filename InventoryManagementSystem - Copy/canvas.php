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

// Get parameters for filters
$filter_category = isset($_GET['filter_category']) ? $conn->real_escape_string($_GET['filter_category']) : '';
$filter_company = isset($_GET['filter_company']) ? $conn->real_escape_string($_GET['filter_company']) : '';
$filter_search = isset($_GET['filter_search']) ? $conn->real_escape_string($_GET['filter_search']) : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : '';
$active_sort = isset($_GET['active_sort']) ? $_GET['active_sort'] : '';

// Get all active companies for filter dropdown - FROM THE MAIN TABLE ONLY
$companies_filter_query = "
    SELECT DISTINCT c.id, c.name 
    FROM companies c
    INNER JOIN company_prices cp ON c.id = cp.company_id
    INNER JOIN canvas_items ci ON ci.id = cp.item_id
    WHERE c.status = 'active'
    ORDER BY c.name
";
$companies_filter = $conn->query($companies_filter_query);

// Get all unique categories for filter dropdown - FROM THE MAIN TABLE ONLY
$categories_filter_query = "
    SELECT DISTINCT ci.category 
    FROM canvas_items ci
    INNER JOIN company_prices cp ON ci.id = cp.item_id
    WHERE ci.category != '' AND ci.category IS NOT NULL
    ORDER BY ci.category
";
$categories_filter = $conn->query($categories_filter_query);

// Get all active companies for company colors
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

// Get all companies for dropdown (for add company form)
$companies_list = $conn->query("SELECT id, name, contact_person, contact_number FROM companies WHERE status = 'active' ORDER BY name");
$companies_dropdown = [];
if ($companies_list && $companies_list->num_rows > 0) {
    while($comp = $companies_list->fetch_assoc()) {
        $companies_dropdown[] = $comp;
    }
}

// Build query based on filters and sort - WITH CATEGORY
$query = "
    SELECT ci.*, 
           cp.id as price_id,
           cp.company_id,
           cp.quantity as available_quantity,
           cp.price,
           cp.availability,
           c.name as company_name,
           c.contact_person,
           c.contact_number,
           ci.category
    FROM canvas_items ci
    INNER JOIN company_prices cp ON ci.id = cp.item_id
    INNER JOIN companies c ON cp.company_id = c.id
    WHERE c.status = 'active'
";

// Apply filters
$where_conditions = [];

if (!empty($filter_category)) {
    $where_conditions[] = "ci.category = '$filter_category'";
}

if (!empty($filter_company)) {
    $where_conditions[] = "c.id = '$filter_company'";
}

// MERGED SEARCH - searches in Item No, Description, and Category
if (!empty($filter_search)) {
    $where_conditions[] = "(ci.item_no LIKE '%$filter_search%' OR ci.description LIKE '%$filter_search%' OR ci.category LIKE '%$filter_search%')";
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

// Add sorting
if ($active_sort == 'price') {
    if ($sort_by == 'price_asc') {
        $query .= " ORDER BY cp.price ASC, c.name ASC";
        $sort_label = 'Price Low to High';
        $sort_icon = 'fa-sort-amount-down';
    } else if ($sort_by == 'price_desc') {
        $query .= " ORDER BY cp.price DESC, c.name ASC";
        $sort_label = 'Price High to Low';
        $sort_icon = 'fa-sort-amount-up';
    } else {
        $query .= " ORDER BY ci.item_no ASC, c.name ASC";
    }
} else {
    $query .= " ORDER BY ci.item_no ASC, c.name ASC";
}

$items = $conn->query($query);

// Get statistics
$totalItems = $conn->query("SELECT COUNT(*) as count FROM canvas_items")->fetch_assoc()['count'] ?? 0;
$totalCompanies = $conn->query("SELECT COUNT(*) as count FROM companies WHERE status = 'active'")->fetch_assoc()['count'] ?? 0;

// Handle POST request for adding new company price - WITH CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_company_price') {
    $item_no = $conn->real_escape_string($_POST['item_no']);
    $description = $conn->real_escape_string($_POST['description']);
    $category = $conn->real_escape_string($_POST['category'] ?? '');
    $company_name = $conn->real_escape_string($_POST['company_name']);
    $contact_person = $conn->real_escape_string($_POST['contact_person'] ?? '');
    $contact_number = $conn->real_escape_string($_POST['contact_number'] ?? '');
    $quantity = intval($_POST['quantity']);
    $price = floatval($_POST['price']);
    $company_color = $conn->real_escape_string($_POST['company_color'] ?? '#6c5ce7');
    
    // Check if company exists in companies table
    $check_company = $conn->query("SELECT id FROM companies WHERE name = '$company_name'");
    if ($check_company->num_rows == 0) {
        // Insert new company
        $insert_company = $conn->query("INSERT INTO companies (name, contact_person, contact_number, status) VALUES ('$company_name', '$contact_person', '$contact_number', 'active')");
        $company_id = $conn->insert_id;
    } else {
        $company = $check_company->fetch_assoc();
        $company_id = $company['id'];
        
        // Update contact info if provided
        if (!empty($contact_person) || !empty($contact_number)) {
            $conn->query("UPDATE companies SET contact_person = '$contact_person', contact_number = '$contact_number' WHERE id = $company_id");
        }
    }
    
    // Check if item exists in canvas_items - WITH CATEGORY
    $check_item = $conn->query("SELECT id FROM canvas_items WHERE item_no = '$item_no'");
    if ($check_item->num_rows == 0) {
        // Insert new canvas item with category
        $insert_item = $conn->query("INSERT INTO canvas_items (item_no, description, category) VALUES ('$item_no', '$description', '$category')");
        $item_id = $conn->insert_id;
    } else {
        $item = $check_item->fetch_assoc();
        $item_id = $item['id'];
        
        // Update category if provided
        if (!empty($category)) {
            $conn->query("UPDATE canvas_items SET category = '$category', description = '$description' WHERE id = $item_id");
        }
    }
    
    // Check if company price already exists
    $check_price = $conn->query("SELECT id FROM company_prices WHERE item_id = $item_id AND company_id = $company_id");
    if ($check_price->num_rows > 0) {
        // Update existing
        $conn->query("UPDATE company_prices SET quantity = $quantity, price = $price, availability = 1 WHERE item_id = $item_id AND company_id = $company_id");
    } else {
        // Insert new company price
        $conn->query("INSERT INTO company_prices (item_id, company_id, quantity, price, availability) VALUES ($item_id, $company_id, $quantity, $price, 1)");
    }
    
    // Redirect to refresh the page with success message
    header("Location: canvas.php?success=1");
    exit();
}

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

/* Success message */
.success-message {
    background: linear-gradient(135deg, #00b894, #6c5ce7);
    color: white;
    padding: 15px 25px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
}

.success-message i {
    font-size: 20px;
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

/* Category badge - NEW STYLE */
.category-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: linear-gradient(135deg, #667eea, #764ba2);
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

/* Action button base styles */
.action-btn {
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Add to Cart button */
.action-btn.add-to-cart {
    background: linear-gradient(135deg, #6c5ce7, #75e6da);
    color: white;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
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

/* View button */
.action-btn.view-btn {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
}

.action-btn.view-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(52, 152, 219, 0.3);
}

/* Edit button */
.action-btn.edit-btn {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
}

.action-btn.edit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(243, 156, 18, 0.3);
}

/* Delete button */
.action-btn.delete-btn {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
}

.action-btn.delete-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(231, 76, 60, 0.3);
}

.action-btn i {
    font-size: 14px;
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

/* Action button - View Comparison */
.action-btn.view-comparison {
    background: linear-gradient(135deg, #9b59b6, #6c5ce7);
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
}

.action-btn.view-comparison:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(155, 89, 182, 0.3);
}

/* ===== UPDATED TABLE STYLES WITH CENTERED TEXT ===== */
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
    min-width: 1400px;
}

.products-table th {
    padding: 15px 10px;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}

.products-table td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    font-size: 13px;
    vertical-align: middle;
    text-align: center;
}

.products-table tbody tr:hover {
    background: var(--bg-secondary);
}

/* Center all badges and inline elements */
.products-table td .company-badge,
.products-table td .category-badge,
.products-table td .availability-badge,
.products-table td .contact-person,
.products-table td .contact-number {
    margin: 0 auto;
    display: inline-block;
    text-align: center;
}

/* Center the total price span */
.products-table td .total-price-cell {
    margin: 0 auto;
    display: inline-block;
}

/* Center the action buttons container */
.products-table td div {
    justify-content: center !important;
}

/* Center text in comparison table */
.comparison-table th,
.comparison-table td {
    text-align: center !important;
    vertical-align: middle;
}

.comparison-table td .company-badge,
.comparison-table td .category-badge,
.comparison-table td .availability-badge,
.comparison-table td .total-price-cell {
    margin: 0 auto;
    display: inline-block;
}

/* ===== END OF UPDATED TABLE STYLES ===== */

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

/* FILTERS SECTION */
.filters-section {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
}

.filters-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filters-title i {
    color: #75e6da;
}

.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 15px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Search group with icon */
.search-group {
    position: relative;
    grid-column: 1;
}

.search-group i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
    z-index: 1;
}

.search-group input {
    padding: 10px 15px 10px 45px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.95rem;
    transition: all 0.3s;
    background: var(--bg-secondary);
    color: var(--text-primary);
    width: 100%;
}

.search-group input:focus {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
    outline: none;
}

.search-group input:hover {
    border-color: #75e6da;
}

.search-group input::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}

/* Regular filter controls */
.filter-control {
    padding: 10px 15px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.95rem;
    transition: all 0.3s;
    background: var(--bg-secondary);
    color: var(--text-primary);
    width: 100%;
}

.filter-control:focus {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
    outline: none;
}

.filter-control:hover {
    border-color: #75e6da;
}

.filter-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.filter-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
    white-space: nowrap;
}

.filter-btn.primary {
    background: linear-gradient(135deg, #75e6da, #62d4c8);
    color: white;
    box-shadow: 0 2px 8px rgba(117, 230, 218, 0.3);
}

.filter-btn.primary:hover {
    background: linear-gradient(135deg, #62d4c8, #4fb3aa);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(117, 230, 218, 0.4);
}

.filter-btn.secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.filter-btn.secondary:hover {
    background: var(--border-color);
    transform: translateY(-2px);
}

/* Price sort section with text container - UPDATED */
.price-sort-section {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 15px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
}

.sort-text-container {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-secondary);
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.sort-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.sort-count {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: rgba(117, 230, 218, 0.1);
    padding: 2px 8px;
    border-radius: 20px;
}

.price-sort-buttons {
    display: flex;
    gap: 10px;
    align-items: center;
}

.price-sort-btn {
    padding: 10px 20px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.price-sort-btn:hover {
    background: var(--bg-primary);
    border-color: #75e6da;
    color: #75e6da;
}

.price-sort-btn.active {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    border-color: transparent;
    color: white;
}

.price-sort-btn i {
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

/* Add Company Form Modal Styles */
.company-modal {
    max-width: 800px !important;
}

.company-modal .modal-header {
    background: linear-gradient(135deg, #00b894, #6c5ce7);
}

/* View Modal Styles */
.view-modal {
    max-width: 650px !important;
}

.view-modal .modal-header {
    background: linear-gradient(135deg, #3498db, #2980b9);
}

.view-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: 12px;
    margin-bottom: 20px;
}

.view-detail-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.view-detail-item.full-width {
    grid-column: span 2;
}

.view-detail-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.view-detail-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    padding: 8px 12px;
    background: var(--bg-primary);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.view-detail-value.company-badge-view {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 20px;
    color: white;
    font-weight: 600;
}

.view-detail-value.category-badge-view {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 20px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-weight: 600;
}

/* Edit Modal Styles */
.edit-modal {
    max-width: 800px !important;
}

.edit-modal .modal-header {
    background: linear-gradient(135deg, #f39c12, #e67e22);
}

/* Delete Modal Enhanced Styles */
.delete-modal {
    max-width: 500px !important;
}

.delete-modal .modal-header {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    position: relative;
    overflow: hidden;
}

.delete-modal .modal-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.05); opacity: 0.8; }
    100% { transform: scale(1); opacity: 0.5; }
}

.delete-icon-container {
    text-align: center;
    padding: 20px 0 10px;
    position: relative;
}

.delete-icon {
    font-size: 80px;
    color: #e74c3c;
    filter: drop-shadow(0 10px 15px rgba(231, 76, 60, 0.4));
    animation: shake 0.5s ease-in-out infinite;
}

@keyframes shake {
    0%, 100% { transform: rotate(0deg); }
    25% { transform: rotate(5deg); }
    75% { transform: rotate(-5deg); }
}

.delete-title {
    text-align: center;
    font-size: 28px;
    font-weight: 700;
    color: #e74c3c;
    margin-bottom: 10px;
    text-shadow: 0 2px 10px rgba(231, 76, 60, 0.3);
}

.delete-message {
    text-align: center;
    color: var(--text-secondary);
    margin-bottom: 25px;
    font-size: 15px;
}

.delete-details-card {
    background: linear-gradient(135deg, rgba(231, 76, 60, 0.1), rgba(192, 57, 43, 0.15));
    border-radius: 16px;
    padding: 20px;
    margin: 0 0 25px 0;
    border: 2px dashed #e74c3c;
    position: relative;
    overflow: hidden;
}

.delete-details-card::before {
    content: '⚠️';
    position: absolute;
    bottom: -10px;
    right: -10px;
    font-size: 60px;
    opacity: 0.1;
    transform: rotate(15deg);
}

.delete-detail-row {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    background: var(--bg-primary);
    border-radius: 12px;
    margin-bottom: 10px;
    border-left: 5px solid #e74c3c;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.delete-detail-row:last-child {
    margin-bottom: 0;
}

.delete-detail-icon {
    width: 40px;
    height: 40px;
    background: rgba(231, 76, 60, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: #e74c3c;
    font-size: 18px;
}

.delete-detail-content {
    flex: 1;
}

.delete-detail-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.delete-detail-value {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
}

.delete-warning {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(231, 76, 60, 0.15);
    border-radius: 10px;
    padding: 12px 15px;
    margin: 20px 0;
    border: 1px solid rgba(231, 76, 60, 0.3);
}

.delete-warning i {
    color: #e74c3c;
    font-size: 20px;
}

.delete-warning span {
    color: var(--text-primary);
    font-size: 13px;
    font-weight: 500;
}

.delete-warning strong {
    color: #e74c3c;
}

.delete-modal-actions {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid var(--border-color);
}

.btn-delete-cancel {
    padding: 14px 30px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    color: var(--text-primary);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    justify-content: center;
}

.btn-delete-cancel:hover {
    background: var(--border-color);
    border-color: var(--text-secondary);
    transform: translateY(-2px);
}

.btn-delete-confirm {
    padding: 14px 30px;
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    border: none;
    border-radius: 12px;
    color: white;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
    flex: 1;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.btn-delete-confirm::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s ease;
}

.btn-delete-confirm:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.5);
}

.btn-delete-confirm:hover::before {
    left: 100%;
}

.btn-delete-confirm i {
    font-size: 16px;
}

/* ===== UPDATED COMPARISON MODAL STYLES - SINGLE SCROLLABLE ===== */
.comparison-modal {
    max-width: 1400px !important;
    width: 95% !important;
}

.comparison-modal .modal-header {
    background: linear-gradient(135deg, #9b59b6, #6c5ce7);
    flex-shrink: 0; /* Prevent header from shrinking */
}

.comparison-modal .modal-body {
    overflow-y: auto !important; /* Force scrollable body */
    max-height: calc(90vh - 130px) !important; /* Calculate height (viewport height minus header) */
    padding: 20px;
}

.comparison-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    flex-shrink: 0; /* Prevent header from shrinking */
}

.comparison-title h3 {
    color: var(--text-primary);
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.comparison-title h3 i {
    color: #9b59b6;
}

.comparison-actions {
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.comparison-sort {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.comparison-sort-btn {
    padding: 8px 15px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-primary);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.comparison-sort-btn:hover {
    background: var(--bg-primary);
    border-color: #9b59b6;
    color: #9b59b6;
}

.comparison-sort-btn.active {
    background: linear-gradient(135deg, #9b59b6, #6c5ce7);
    border-color: transparent;
    color: white;
}

.comparison-sort-btn i {
    font-size: 11px;
}

/* Export button group */
.comparison-export {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Excel button */
.btn-excel {
    padding: 8px 15px;
    background: linear-gradient(135deg, #27ae60, #219653);
    border: none;
    border-radius: 6px;
    color: white;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.btn-excel:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
    background: linear-gradient(135deg, #2ecc71, #27ae60);
}

.btn-excel i {
    font-size: 14px;
}

.btn-print {
    padding: 8px 15px;
    background: linear-gradient(135deg, #e74c3c, #e67e22);
    border: none;
    border-radius: 6px;
    color: white;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.btn-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(231, 76, 60, 0.3);
}

.comparison-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.comparison-table th {
    position: sticky;
    top: 0;
    background: var(--bg-primary);
    padding: 12px 10px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    z-index: 10;
    text-align: center;
}

.comparison-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
    font-size: 13px;
    color: var(--text-primary);
    text-align: center;
    vertical-align: middle;
}

.comparison-table tbody tr:hover {
    background: var(--bg-secondary);
}

/* Center badges in comparison table */
.comparison-table td .company-badge,
.comparison-table td .category-badge,
.comparison-table td .availability-badge,
.comparison-table td .total-price-cell {
    margin: 0 auto;
    display: inline-block;
}

/* Search box sa comparison modal */
.comparison-search {
    flex: 1;
    min-width: 300px;
    position: relative;
}

.comparison-search i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.comparison-search input {
    width: 100%;
    padding: 10px 15px 10px 45px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.comparison-search input:focus {
    border-color: #9b59b6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(155, 89, 182, 0.2);
}

.comparison-search input::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}

/* Print styles */
@media print {
    body * {
        visibility: hidden;
    }
    #comparisonModal .modal-content,
    #comparisonModal .modal-content * {
        visibility: visible;
    }
    #comparisonModal .modal-content {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        background: white;
    }
    #comparisonModal .modal-header,
    #comparisonModal .comparison-actions,
    #comparisonModal .close-modal {
        display: none !important;
    }
}
/* ===== END OF UPDATED COMPARISON MODAL STYLES ===== */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.form-label i {
    color: #75e6da;
    font-size: 14px;
}

.form-control {
    padding: 12px 15px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
    width: 100%;
}

.form-control:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

.form-control[type="number"] {
    -moz-appearance: textfield;
    appearance: textfield;
}
.form-control[type="number"]::-webkit-outer-spin-button,
.form-control[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.form-row {
    display: flex;
    gap: 15px;
    align-items: center;
}

.price-display {
    background: linear-gradient(135deg, rgba(117, 230, 218, 0.1), rgba(108, 92, 231, 0.1));
    border-radius: 12px;
    padding: 20px;
    margin: 20px 0;
    border: 2px dashed #75e6da;
}

.price-display .label {
    font-size: 12px;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 5px;
}

.price-display .amount {
    font-size: 32px;
    font-weight: 700;
    color: #6c5ce7;
    line-height: 1.2;
}

.price-display .amount small {
    font-size: 14px;
    font-weight: 400;
    color: var(--text-secondary);
}

.form-hint {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.form-hint i {
    color: #75e6da;
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
    background: linear-gradient(135deg, #75e6da, #75e6da);
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

.detail-value.category-badge-modal {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 20px;
    background: linear-gradient(135deg, #667eea, #764ba2);
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

.btn-save-company {
    padding: 12px 35px;
    background: linear-gradient(135deg, #00b894, #6c5ce7);
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

.btn-save-company:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 184, 148, 0.4);
}

.btn-save-edit {
    padding: 12px 35px;
    background: linear-gradient(135deg, #f39c12, #e67e22);
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
    box-shadow: 0 4px 10px rgba(243, 156, 18, 0.3);
}

.btn-save-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4);
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

/* New item highlight */
@keyframes highlight {
    0% { background-color: rgba(117, 230, 218, 0.3); }
    100% { background-color: transparent; }
}

.highlight-new {
    animation: highlight 2s ease;
}
</style>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Canvas Price Comparison</h1>
        <p>Compare prices across different suppliers</p>
    </div>
    <div class="welcome-actions">
        <button class="action-btn view-comparison" onclick="openComparisonModal()">
            <i class="fas fa-chart-bar"></i>
            View Comparison
        </button>
       
        <button class="action-btn add-company" onclick="openCompanyFormModal()">
            <i class="fas fa-building"></i>
            Add Item
        </button>
    </div>
</div>

<!-- Success Message -->
<?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
<div class="success-message" id="successMessage">
    <i class="fas fa-check-circle"></i>
    <span>Company price added successfully! The new entry has been added to the table.</span>
</div>
<?php endif; ?>

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

<!-- FILTERS SECTION -->
<div class="filters-section">
    <div class="filters-title">
        <i class="fas fa-filter"></i>
        Filter Canvas Items
    </div>
    <form method="GET" action="canvas.php" id="filterForm">
        <div class="filter-grid">
            <!-- Search on left side with icon -->
            <div class="filter-group search-group">
                <label for="filter_search">Search (Item No, Description, Category)</label>
                <i class="fas fa-search"></i>
                <input type="text" name="filter_search" id="filter_search" placeholder="Search by Item No, Description, or Category..." value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
            
            <div class="filter-group">
                <label for="filter_category">Category</label>
                <select name="filter_category" id="filter_category" class="filter-control">
                    <option value="">All Categories</option>
                    <?php if ($categories_filter && $categories_filter->num_rows > 0): ?>
                        <?php while($cat = $categories_filter->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $filter_category == $cat['category'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="filter_company">Company</label>
                <select name="filter_company" id="filter_company" class="filter-control">
                    <option value="">All Companies</option>
                    <?php if ($companies_filter && $companies_filter->num_rows > 0): ?>
                        <?php while($comp = $companies_filter->fetch_assoc()): ?>
                            <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($comp['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="filter-btn primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button type="button" class="filter-btn secondary" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
        </div>
        
        <!-- Preserve sort parameters when filtering -->
        <?php if (!empty($sort_by)): ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
        <?php endif; ?>
        <?php if (!empty($active_sort)): ?>
            <input type="hidden" name="active_sort" value="<?php echo htmlspecialchars($active_sort); ?>">
        <?php endif; ?>
    </form>
</div>

<!-- Price Sort Section - UPDATED with separate text container -->
<div class="price-sort-section">
    <div class="sort-text-container">
        <span class="sort-label">All Items</span>
        <span class="sort-count">(<?php echo $items ? $items->num_rows : 0; ?> entries)</span>
    </div>
    <div class="price-sort-buttons">
        <button class="price-sort-btn <?php echo ($active_sort == 'price') ? 'active' : ''; ?>" onclick="togglePriceSort()" id="priceSortBtn">
            <i class="fas <?php echo ($sort_by == 'price_desc') ? 'fa-sort-amount-up' : 'fa-sort-amount-down'; ?>" id="sortIcon"></i> 
            <span id="sortText"><?php echo ($sort_by == 'price_desc') ? 'Price High to Low' : 'Price Low to High'; ?></span>
        </button>
        
        <?php if (!empty($filter_category) || !empty($filter_company) || !empty($filter_search) || $active_sort == 'price'): ?>
        <button class="price-sort-btn" onclick="clearAllFilters()">
            <i class="fas fa-times"></i> Clear All Filters
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Display Info - KEPT ONLY for filter results -->
<?php 
$has_filters = !empty($filter_category) || !empty($filter_company) || !empty($filter_search);
if ($has_filters): 
    $filter_descriptions = [];
    if (!empty($filter_category)) $filter_descriptions[] = "Category: \"$filter_category\"";
    if (!empty($filter_company)) {
        // Get company name for display
        $company_name = '';
        if ($companies_filter && $companies_filter->num_rows > 0) {
            $companies_filter->data_seek(0);
            while($comp = $companies_filter->fetch_assoc()) {
                if ($comp['id'] == $filter_company) {
                    $company_name = $comp['name'];
                    break;
                }
            }
        }
        $filter_descriptions[] = "Company: \"$company_name\"";
    }
    if (!empty($filter_search)) $filter_descriptions[] = "Search: \"$filter_search\"";
?>
    <div class="display-info">
        <div>
            <h3><i class="fas fa-search"></i> Filter Results</h3>
            <p>Showing items with: <?php echo implode(' • ', $filter_descriptions); ?></p>
            <?php if($items): ?>
                <span class="item-count-badge">
                    <span><?php echo $items->num_rows; ?></span> price entries found
                </span>
            <?php endif; ?>
        </div>
        <?php if($active_sort == 'price'): ?>
        <div class="sort-indicator">
            <i class="fas <?php echo $sort_icon; ?>"></i> 
            Sorted by: <?php echo $sort_label; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Main Table - WITH CATEGORY COLUMN - ALL TEXT CENTERED -->
<div class="table-wrapper">
    <table class="products-table" id="canvasTable">
        <thead>
            <tr>
                <th>Item No</th>
                <th>Description</th>
                <th>Category</th>
                <th>Company</th>
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
                while($row = $items->fetch_assoc()): 
                    $total_price = $row['available_quantity'] * $row['price'];
                ?>
                    <tr class="item-row" 
                        data-price-id="<?php echo $row['price_id']; ?>"
                        data-item-no="<?php echo htmlspecialchars($row['item_no']); ?>" 
                        data-description="<?php echo htmlspecialchars($row['description']); ?>"
                        data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                        data-company="<?php echo htmlspecialchars($row['company_name']); ?>"
                        data-company-id="<?php echo $row['company_id']; ?>"
                        data-contact="<?php echo htmlspecialchars($row['contact_person'] ?? ''); ?>"
                        data-contact-number="<?php echo htmlspecialchars($row['contact_number'] ?? ''); ?>"
                        data-quantity="<?php echo $row['available_quantity']; ?>"
                        data-price="<?php echo $row['price']; ?>"
                        data-availability="<?php echo $row['availability']; ?>"
                        data-company-color="<?php echo $company_colors[$row['company_id']] ?? '#6c757d'; ?>">
                        
                        <td><strong><?php echo htmlspecialchars($row['item_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                        
                        <!-- Category Column -->
                        <td>
                            <?php if (!empty($row['category'])): ?>
                                <span class="category-badge">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['category']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        
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
                        <td class="quantity-cell"><?php echo number_format($row['available_quantity']); ?></td>
                        
                        <!-- Price -->
                        <td class="price-cell">₱<?php echo number_format($row['price'], 2); ?></td>
                        
                        <!-- Total Price -->
                        <td>
                            <span class="total-price-cell">
                                ₱<?php echo number_format($total_price, 2); ?>
                            </span>
                        </td>
                        
                        <!-- Availability -->
                        <td>
                            <span class="availability-badge <?php echo $row['availability'] ? 'available' : 'unavailable'; ?>">
                                <i class="fas <?php echo $row['availability'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> 
                                <?php echo $row['availability'] ? 'In Stock' : 'Out of Stock'; ?>
                            </span>
                        </td>
                        
                        <!-- Actions -->
                        <td>
                            <div style="display: flex; gap: 5px; justify-content: center;">
                                <button class="action-btn view-btn" onclick="viewCompanyPrice(<?php echo $row['price_id']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-btn edit-btn" onclick="editCompanyPrice(<?php echo $row['price_id']; ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn delete-btn" onclick="deleteCompanyPrice(<?php echo $row['price_id']; ?>, '<?php echo htmlspecialchars($row['company_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['item_no'], ENT_QUOTES); ?>')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php if($row['availability'] && $row['available_quantity'] > 0): ?>
                                    <button class="action-btn add-to-cart" onclick="openCartModal(this)" title="Add to Cart">
                                        <i class="fas fa-cart-plus"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="action-btn add-to-cart" disabled title="Unavailable">
                                        <i class="fas fa-cart-plus"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 60px;">
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>No Items Found</h3>
                            <p>No items match your filter criteria.</p>
                            <button class="action-btn add-company" onclick="openCompanyFormModal()">
                                <i class="fas fa-building"></i> Add Company Item
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- No Results Message -->
<div id="noResultsMessage" style="display: none; text-align: center; padding: 40px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; margin-top: 20px;">
    <i class="fas fa-search" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 15px;"></i>
    <h3 style="color: var(--text-primary); margin-bottom: 10px;">No Items Found</h3>
    <p style="color: var(--text-secondary);">No items match your filter criteria.</p>
</div>

<!-- Add to Cart Modal -->
<div id="cartModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-shopping-cart"></i> Add to Cart</h2>
            <span class="close-modal" onclick="closeCartModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="item-details-grid" id="modalItemDetails"></div>
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
                <div class="total-price-display">
                    <span class="label">Total Price:</span>
                    <span class="amount" id="modalTotalPrice">₱0.00</span>
                </div>
            </div>
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

<!-- Add Company Form Modal - WITH CATEGORY FIELD -->
<div id="companyFormModal" class="modal">
    <div class="modal-content company-modal">
        <div class="modal-header">
            <h2><i class="fas fa-building"></i> Add Company Price</h2>
            <span class="close-modal" onclick="closeCompanyFormModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="canvas.php" id="companyForm">
                <input type="hidden" name="action" value="add_company_price">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-hashtag"></i> Item No *</label>
                        <input type="text" class="form-control" name="item_no" id="itemNo" placeholder="e.g., ITEM-001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-align-left"></i> Description *</label>
                        <input type="text" class="form-control" name="description" id="description" placeholder="Item description" required>
                    </div>
                    <!-- NEW CATEGORY FIELD -->
                    <div class="form-group full-width">
                        <label class="form-label"><i class="fas fa-tags"></i> Category</label>
                        <input type="text" class="form-control" name="category" id="category" placeholder="e.g., Electronics, Office Supplies, Furniture">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Optional: Add a category to organize your items</div>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label"><i class="fas fa-building"></i> Company Name *</label>
                        <input type="text" class="form-control" name="company_name" id="companyName" placeholder="Enter company name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" id="contactPerson" placeholder="Contact person name">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-phone"></i> Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="contactNumber" placeholder="Contact number">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-cubes"></i> Available Qty *</label>
                        <input type="number" class="form-control" name="quantity" id="availableQty" min="1" value="1" required onchange="calculateTotalPrice()" onkeyup="calculateTotalPrice()">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-tag"></i> Price *</label>
                        <div class="form-row">
                            <span style="color: var(--text-primary); font-weight: 600;">₱</span>
                            <input type="number" class="form-control" name="price" id="price" step="0.01" min="0" value="0.00" required onchange="calculateTotalPrice()" onkeyup="calculateTotalPrice()">
                        </div>
                    </div>
                    <input type="hidden" name="company_color" id="companyColor" value="#6c5ce7">
                </div>
                <div class="price-display">
                    <div class="label">TOTAL PRICE</div>
                    <div class="amount" id="totalPriceDisplay">₱0.00</div>
                    <div class="form-hint"><i class="fas fa-info-circle"></i> Total = Quantity × Price</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeCompanyFormModal()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn-save-company" id="saveCompanyBtn"><i class="fas fa-save"></i> Save Company Price</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal - WITH CATEGORY -->
<div id="viewModal" class="modal">
    <div class="modal-content view-modal">
        <div class="modal-header">
            <h2><i class="fas fa-eye"></i> Price Details</h2>
            <span class="close-modal" onclick="closeViewModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="view-details-grid" id="viewDetails"></div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Price Modal - WITH CATEGORY FIELD (FIXED) -->
<div id="editModal" class="modal">
    <div class="modal-content edit-modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Price</h2>
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="editForm" method="POST" action="update_price.php">
                <input type="hidden" name="price_id" id="editPriceId">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-hashtag"></i> Item No *</label>
                        <input type="text" class="form-control" name="item_no" id="editItemNo" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-align-left"></i> Description *</label>
                        <input type="text" class="form-control" name="description" id="editDescription" required>
                    </div>
                    <!-- NEW CATEGORY FIELD IN EDIT -->
                    <div class="form-group full-width">
                        <label class="form-label"><i class="fas fa-tags"></i> Category</label>
                        <input type="text" class="form-control" name="category" id="editCategory" placeholder="e.g., Electronics, Office Supplies, Furniture">
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label"><i class="fas fa-building"></i> Company Name *</label>
                        <input type="text" class="form-control" name="company_name" id="editCompanyName" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" id="editContactPerson">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-phone"></i> Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="editContactNumber">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-cubes"></i> Available Qty *</label>
                        <input type="number" class="form-control" name="quantity" id="editQuantity" min="1" required onchange="calculateEditTotal()" onkeyup="calculateEditTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-tag"></i> Price *</label>
                        <div class="form-row">
                            <span style="color: var(--text-primary); font-weight: 600;">₱</span>
                            <input type="number" class="form-control" name="price" id="editPrice" step="0.01" min="0" required onchange="calculateEditTotal()" onkeyup="calculateEditTotal()">
                        </div>
                    </div>
                </div>
                <div class="price-display">
                    <div class="label">TOTAL PRICE</div>
                    <div class="amount" id="editTotalPrice">₱0.00</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn-save-edit" id="saveEditBtn"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="modal">
    <div class="modal-content delete-modal">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle"></i> Delete Confirmation</h2>
            <span class="close-modal" onclick="closeDeleteConfirmModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="delete-icon-container"><i class="fas fa-trash-alt delete-icon"></i></div>
            <div class="delete-title">Delete Price?</div>
            <div class="delete-message">You are about to permanently delete this Company. This action cannot be undone.</div>
            <div class="delete-details-card">
                <div class="delete-detail-row">
                    <div class="delete-detail-icon"><i class="fas fa-building"></i></div>
                    <div class="delete-detail-content">
                        <div class="delete-detail-label">Company</div>
                        <div class="delete-detail-value" id="deleteCompanyName"></div>
                    </div>
                </div>
                <div class="delete-detail-row">
                    <div class="delete-detail-icon"><i class="fas fa-hashtag"></i></div>
                    <div class="delete-detail-content">
                        <div class="delete-detail-label">Item No</div>
                        <div class="delete-detail-value" id="deleteItemNo"></div>
                    </div>
                </div>
            </div>
            <div class="delete-warning">
                <i class="fas fa-exclamation-circle"></i>
                <span><strong>Warning:</strong> This will also remove any pending purchases associated with this price.</span>
            </div>
            <div class="delete-modal-actions">
                <button class="btn-delete-cancel" onclick="closeDeleteConfirmModal()"><i class="fas fa-times"></i> Cancel</button>
                <button class="btn-delete-confirm" onclick="deleteNow()"><i class="fas fa-trash"></i> Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Comparison Modal - WITH SINGLE SCROLLABLE -->
<div id="comparisonModal" class="modal">
    <div class="modal-content comparison-modal">
        <div class="modal-header">
            <h2><i class="fas fa-chart-bar"></i> Price Comparison</h2>
            <span class="close-modal" onclick="closeComparisonModal()">&times;</span>
        </div>
        <div class="modal-body" style="overflow-y: auto; max-height: calc(90vh - 130px);">
            <div class="comparison-header">
                <div class="comparison-title">
                    <h3><i class="fas fa-store"></i> All Suppliers Comparison</h3>
                </div>
                <div class="comparison-actions">
                    <div class="comparison-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="comparisonSearch" placeholder="Search by Item No, Description, Category, or Company..." onkeyup="filterComparisonTable()">
                    </div>
                    <div class="comparison-sort">
                        <button class="comparison-sort-btn" onclick="toggleComparisonSort()" id="togglePriceSortBtn">
                            <i class="fas fa-sort-amount-down-alt"></i> 
                            <span id="priceSortText">Price: Low to High</span>
                        </button>
                    </div>
                    <div class="comparison-export">
                        <button class="btn-excel" onclick="exportToExcel()" title="Export to Excel">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="btn-print" onclick="printComparison()" title="Print">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Direct table without separate container - will scroll with modal body -->
            <table class="comparison-table" id="comparisonTable">
                <thead>
                    <tr>
                        <th>Item No</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Company</th>
                        <th>Contact Person</th>
                        <th>Contact Number</th>
                        <th>Available Qty</th>
                        <th>Price</th>
                        <th>Total Price</th>
                        <th>Availability</th>
                    </tr>
                </thead>
                <tbody id="comparisonTableBody">
                    <!-- Will be populated by JavaScript -->
                </tbody>
            </table>
            
            <div style="color: var(--text-secondary); font-size: 12px; text-align: right; margin-top: 15px;">
                <i class="fas fa-info-circle"></i> Total entries: <span id="comparisonTotalCount">0</span>
            </div>
        </div>
    </div>
</div>

<script>
// Global variable to store current sort preference
let currentPriceSort = '<?php echo ($active_sort == 'price' && $sort_by == 'price_desc') ? 'price_desc' : 'price_asc'; ?>';

// Cart data
let currentCartItem = {
    priceId: null,
    itemNo: '',
    description: '',
    category: '',
    companyName: '',
    contactPerson: '',
    contactNumber: '',
    availableQuantity: 0,
    price: 0,
    companyColor: '',
    rowElement: null
};

// Store all items data for comparison
let allItemsData = [];

// Delete confirmation variables
let pendingDeleteId = null;
let pendingDeleteCompany = null;
let pendingDeleteItem = null;

// ==================== MAIN PAGE FUNCTIONS ====================

// Toggle Price Sort on Main Page
function togglePriceSort() {
    const url = new URL(window.location.href);
    const currentSort = url.searchParams.get('sort');
    const currentActiveSort = url.searchParams.get('active_sort');
    let nextSort = 'price_asc';
    
    if (currentActiveSort === 'price') {
        if (currentSort === 'price_asc') nextSort = 'price_desc';
        else if (currentSort === 'price_desc') nextSort = 'price_asc';
    }
    
    url.searchParams.set('sort', nextSort);
    url.searchParams.set('active_sort', 'price');
    
    // Preserve filter parameters
    const filterCategory = document.getElementById('filter_category')?.value;
    const filterCompany = document.getElementById('filter_company')?.value;
    const filterSearch = document.getElementById('filter_search')?.value.trim();
    
    if (filterCategory) url.searchParams.set('filter_category', filterCategory);
    if (filterCompany) url.searchParams.set('filter_company', filterCompany);
    if (filterSearch) url.searchParams.set('filter_search', filterSearch);
    
    window.location.href = url.toString();
}

// Reset all filters
function resetFilters() {
    document.getElementById('filter_category').value = '';
    document.getElementById('filter_company').value = '';
    document.getElementById('filter_search').value = '';
    document.getElementById('filterForm').submit();
}

// Clear all filters and sort
function clearAllFilters() {
    const url = new URL(window.location.href);
    url.searchParams.delete('filter_category');
    url.searchParams.delete('filter_company');
    url.searchParams.delete('filter_search');
    url.searchParams.delete('sort');
    url.searchParams.delete('active_sort');
    window.location.href = url.toString();
}

// ==================== COMPARISON MODAL FUNCTIONS ====================

// Open Comparison Modal
function openComparisonModal() {
    console.log('Opening comparison modal');
    collectAllItemsData();
    
    if (allItemsData.length === 0) {
        showNotification('No items to compare', 'error');
        return;
    }
    
    // Apply current sort
    sortComparisonItems();
    
    // Render the table
    renderComparisonTable();
    
    // Update sort button text
    updateComparisonSortButton();
    
    // Show modal
    document.getElementById('comparisonModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Clear search input
    document.getElementById('comparisonSearch').value = '';
}

// Close Comparison Modal
function closeComparisonModal() {
    document.getElementById('comparisonModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Collect all items data from the table
function collectAllItemsData() {
    const rows = document.querySelectorAll('#canvasTableBody .item-row');
    allItemsData = [];
    
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            allItemsData.push({
                priceId: row.getAttribute('data-price-id'),
                itemNo: row.getAttribute('data-item-no') || '',
                description: row.getAttribute('data-description') || '',
                category: row.getAttribute('data-category') || '',
                company: row.getAttribute('data-company') || '',
                contactPerson: row.getAttribute('data-contact') || '',
                contactNumber: row.getAttribute('data-contact-number') || '',
                quantity: parseInt(row.getAttribute('data-quantity')) || 0,
                price: parseFloat(row.getAttribute('data-price')) || 0,
                availability: row.getAttribute('data-availability') === '1' ? 'In Stock' : 'Out of Stock',
                companyColor: row.getAttribute('data-company-color') || '#6c5ce7'
            });
        }
    });
    
    return allItemsData;
}

// Toggle sort in comparison modal
function toggleComparisonSort() {
    console.log('Toggling comparison sort. Current:', currentPriceSort);
    
    // Toggle sort
    if (currentPriceSort === 'price_asc') {
        currentPriceSort = 'price_desc';
    } else {
        currentPriceSort = 'price_asc';
    }
    
    console.log('New sort:', currentPriceSort);
    
    // Re-sort the items
    sortComparisonItems();
    
    // Re-render the table
    renderComparisonTable();
    
    // Update button text
    updateComparisonSortButton();
    
    // Re-apply search filter
    filterComparisonTable();
}

// Sort comparison items based on currentPriceSort
function sortComparisonItems() {
    if (currentPriceSort === 'price_desc') {
        allItemsData.sort((a, b) => b.price - a.price);
    } else {
        allItemsData.sort((a, b) => a.price - b.price);
    }
}

// Update comparison sort button text
function updateComparisonSortButton() {
    const sortBtn = document.getElementById('togglePriceSortBtn');
    const sortText = document.getElementById('priceSortText');
    
    if (sortBtn && sortText) {
        if (currentPriceSort === 'price_desc') {
            sortText.textContent = 'Price: High to Low';
            sortBtn.innerHTML = '<i class="fas fa-sort-amount-up-alt"></i> <span id="priceSortText">Price: High to Low</span>';
        } else {
            sortText.textContent = 'Price: Low to High';
            sortBtn.innerHTML = '<i class="fas fa-sort-amount-down-alt"></i> <span id="priceSortText">Price: Low to High</span>';
        }
    }
}

// Render comparison table
function renderComparisonTable() {
    const tbody = document.getElementById('comparisonTableBody');
    const totalCount = document.getElementById('comparisonTotalCount');
    
    if (!tbody) return;
    
    let html = '';
    allItemsData.forEach(item => {
        const total = item.quantity * item.price;
        html += `
            <tr>
                <td><strong>${item.itemNo}</strong></td>
                <td>${item.description}</td>
                <td>
                    ${item.category ? 
                        `<span class="category-badge" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-tag"></i> ${item.category}
                        </span>` : 
                        '<span class="text-muted">—</span>'
                    }
                </td>
                <td>
                    <span class="company-badge" style="background: ${item.companyColor}">
                        ${item.company}
                    </span>
                </td>
                <td>${item.contactPerson || '—'}</td>
                <td>${item.contactNumber || '—'}</td>
                <td>${item.quantity.toLocaleString()}</td>
                <td class="price-cell">₱${item.price.toFixed(2)}</td>
                <td>
                    <span class="total-price-cell">
                        ₱${total.toFixed(2)}
                    </span>
                </td>
                <td>
                    <span class="availability-badge ${item.availability === 'In Stock' ? 'available' : 'unavailable'}">
                        <i class="fas ${item.availability === 'In Stock' ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                        ${item.availability}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    if (totalCount) {
        totalCount.textContent = allItemsData.length;
    }
}

// Filter comparison table by search term - SEARCH IN MULTIPLE COLUMNS
function filterComparisonTable() {
    const searchTerm = document.getElementById('comparisonSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#comparisonTable tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        // Get cell contents from multiple columns
        const itemNo = row.cells[0]?.textContent.trim().toLowerCase() || '';
        const description = row.cells[1]?.textContent.trim().toLowerCase() || '';
        const category = row.cells[2]?.textContent.trim().toLowerCase() || '';
        const company = row.cells[3]?.textContent.trim().toLowerCase() || '';
        
        // If search is empty, show all rows
        if (searchTerm === '') {
            row.style.display = '';
            visibleCount++;
        } 
        // If search has value, check for matches in multiple columns
        else {
            if (itemNo.includes(searchTerm) || 
                description.includes(searchTerm) || 
                category.includes(searchTerm) || 
                company.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    // Update the total count display
    const totalCountSpan = document.getElementById('comparisonTotalCount');
    if (totalCountSpan) {
        totalCountSpan.textContent = visibleCount;
    }
}

// ==================== EXPORT FUNCTIONS ====================

// Export to Excel function - OPTIMIZED FOR A4 LANDSCAPE PRINTING
function exportToExcel() {
    const items = collectAllItemsData();
    
    if (items.length === 0) {
        showNotification('No data to export', 'error');
        return;
    }
    
    // Get current search term from comparison modal
    const searchTerm = document.getElementById('comparisonSearch').value.trim();
    let filteredItems = items;
    
    if (searchTerm !== '') {
        // Search in multiple columns
        const searchTermLower = searchTerm.toLowerCase();
        filteredItems = items.filter(item => {
            return (item.itemNo && item.itemNo.toLowerCase().includes(searchTermLower)) ||
                   (item.company && item.company.toLowerCase().includes(searchTermLower)) ||
                   (item.description && item.description.toLowerCase().includes(searchTermLower)) ||
                   (item.category && item.category.toLowerCase().includes(searchTermLower));
        });
    }
    
    if (filteredItems.length === 0) {
        showNotification('No items match your search', 'error');
        return;
    }
    
    // GROUP BY ITEM NO AND DESCRIPTION
    const groupedItems = {};
    filteredItems.forEach(item => {
        const key = `${item.itemNo}|${item.description}`;
        if (!groupedItems[key]) {
            groupedItems[key] = {
                itemNo: item.itemNo,
                description: item.description,
                category: item.category,
                companies: []
            };
        }
        groupedItems[key].companies.push(item);
    });
    
    // Convert to array at i-sort
    let itemsArray = Object.values(groupedItems);
    itemsArray.sort((a, b) => a.itemNo.localeCompare(b.itemNo, undefined, { numeric: true }));
    
    // Get UNIQUE companies
    const allCompanies = [...new Set(filteredItems.map(item => item.company))];
    allCompanies.sort();
    
    // MAXIMUM 8 COMPANIES PER ROW (optimized for A4 Landscape)
    const MAX_COMPANIES_PER_ROW = 8;
    
    // Calculate totals per company
    const companyTotals = {};
    allCompanies.forEach(company => {
        let total = 0;
        itemsArray.forEach(item => {
            const companyItem = item.companies.find(c => c.company === company);
            if (companyItem) {
                total += companyItem.quantity * companyItem.price;
            }
        });
        companyTotals[company] = total;
    });
    
    // Calculate lowest price per item
    itemsArray.forEach(item => {
        if (item.companies.length > 0) {
            const lowestPriceItem = item.companies.reduce((prev, curr) => 
                (curr.price < prev.price) ? curr : prev, item.companies[0]);
            item.lowestTotal = lowestPriceItem.quantity * lowestPriceItem.price;
        } else {
            item.lowestTotal = 0;
        }
    });
    
    // Grand total
    const grandTotal = itemsArray.reduce((sum, item) => sum + (item.lowestTotal || 0), 0);
    
    // Current date
    const today = new Date();
    const dateStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
    const dateTimeStr = `${dateStr} 00:00:00`;
    
    // Create CSV content
    let csvContent = "";
    
    // ===== HEADER SECTION =====
    csvContent += "JLC BEST CONSTRUCTION OPC,,,,,,,,,,,,,,,,Ref. No.,202511-002\n";
    csvContent += "\n";
    csvContent += "CANVASS FORM,,,,,,,,,,,,,,,,\n";
    csvContent += "\n";
    
    // Customer and Date row
    csvContent += "Customer:,,,,,,,,,,,,,,Date:," + dateTimeStr + "\n";
    
    // Project Name and Date Needed row
    csvContent += "Project Name:,,,,,,,,,,,,,,Date Needed:,\n";
    csvContent += "\n";
    
    // Split companies into rows of 8
    for (let rowIndex = 0; rowIndex < allCompanies.length; rowIndex += MAX_COMPANIES_PER_ROW) {
        const rowCompanies = allCompanies.slice(rowIndex, rowIndex + MAX_COMPANIES_PER_ROW);
        const isLastRow = (rowIndex + MAX_COMPANIES_PER_ROW >= allCompanies.length);
        
        // ===== TABLE HEADER =====
        // Row 1: Main headers
        let headerRow1 = "Item No,Description,Category,,,,,Qty,Unit";
        
        // Add company headers for this row
        rowCompanies.forEach(company => {
            // Truncate long company names for better fit
            let displayName = company.length > 15 ? company.substring(0, 12) + '...' : company;
            headerRow1 += ",," + displayName;
        });
        
        // Add Lowest Total column only for LAST row
        if (isLastRow) {
            headerRow1 += ",Lowest Total";
        }
        csvContent += headerRow1 + "\n";
        
        // Row 2: Sub headers (Price and Total)
        let headerRow2 = ",,,,,,,,,Price,Total";
        
        // Add Price/Total subheaders for each company
        rowCompanies.forEach(() => {
            headerRow2 += ",Price,Total";
        });
        
        if (isLastRow) {
            headerRow2 += ",";
        }
        csvContent += headerRow2 + "\n";
        
        // ===== DATA ROWS =====
        let rowNumber = 1;
        itemsArray.forEach(item => {
            const qty = item.companies[0]?.quantity || 0;
            
            let dataRow = `${rowNumber},${item.description},${item.category || ''}`;
            dataRow += ",,,,,,";
            dataRow += `${qty},pcs`;
            
            // Add prices for companies in this row
            rowCompanies.forEach(company => {
                const companyItem = item.companies.find(c => c.company === company);
                if (companyItem) {
                    const unitPrice = companyItem.price.toFixed(2);
                    const total = (companyItem.quantity * companyItem.price).toFixed(2);
                    dataRow += `,${unitPrice},${total}`;
                } else {
                    dataRow += ",,";
                }
            });
            
            // Add lowest total for LAST row only with formula format
            if (isLastRow) {
                dataRow += `,=${item.lowestTotal.toFixed(2)}`;
            }
            
            csvContent += dataRow + "\n";
            rowNumber++;
        });
        
        // ===== EMPTY ROWS (for consistent layout) =====
        const emptyRowsNeeded = Math.max(0, 20 - itemsArray.length);
        for (let i = 0; i < emptyRowsNeeded; i++) {
            let emptyRow = `${itemsArray.length + i + 1},,,,,,,,,,`;
            
            rowCompanies.forEach(() => {
                emptyRow += ",";
            });
            
            if (isLastRow) {
                emptyRow += ",";
            }
            
            csvContent += emptyRow + "\n";
        }
        
        // ===== TOTALS ROW =====
        let totalRow = "TOTAL,,,,,,,,,,";
        
        rowCompanies.forEach(company => {
            const total = companyTotals[company] || 0;
            totalRow += `,=${total.toFixed(2)}`;
        });
        
        if (isLastRow) {
            totalRow += `,=${grandTotal.toFixed(2)}`;
        }
        
        csvContent += totalRow + "\n";
        
        // Add separator between company rows (kung hindi last row)
        if (!isLastRow) {
            csvContent += "\n\n";
        }
    }
    
    // ===== FOOTER SECTION =====
    csvContent += "\n";
    csvContent += "*NOTE:,Project,Stocks\n";
    csvContent += "\n";
    csvContent += "\n";
    csvContent += "Canvass By:,ENGR. CM GALLOS\n";
    csvContent += "Date:,\n";
    csvContent += "\n";
    csvContent += "Noted By:,Engr. Louisito De Guzman\n";
    csvContent += "Date:," + dateTimeStr + "\n";
    
    // Add page break indicators for multi-page support
    if (allCompanies.length > MAX_COMPANIES_PER_ROW) {
        csvContent += "\n\n\n";
        csvContent += "=== Page 2 ===\n";
    }
    
    // Create and download file
    const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    // Generate filename with date and search term
    const searchSuffix = searchTerm ? `_${searchTerm}` : '';
    const filename = `CANVASS_FORM${searchSuffix}_${dateStr}.csv`;
    
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showNotification(`✅ Exported to ${filename} (A4 Landscape optimized)`, 'success');
}

// Print Comparison function - GROUP BY ITEM NUMBER - WITH CENTERED TEXT
function printComparison() {
    const items = collectAllItemsData();
    
    if (items.length === 0) {
        showNotification('No data to print', 'error');
        return;
    }
    
    // Get current search term
    const searchTerm = document.getElementById('comparisonSearch').value.trim().toLowerCase();
    let filteredItems = items;
    
    if (searchTerm !== '') {
        filteredItems = items.filter(item => {
            return (item.itemNo && item.itemNo.toLowerCase().includes(searchTerm)) ||
                   (item.company && item.company.toLowerCase().includes(searchTerm)) ||
                   (item.description && item.description.toLowerCase().includes(searchTerm)) ||
                   (item.category && item.category.toLowerCase().includes(searchTerm));
        });
    }
    
    if (filteredItems.length === 0) {
        showNotification('No items match your search', 'error');
        return;
    }
    
    // GROUP BY ITEM NUMBER
    const itemsByItemNo = {};
    filteredItems.forEach(item => {
        if (!itemsByItemNo[item.itemNo]) {
            itemsByItemNo[item.itemNo] = [];
        }
        itemsByItemNo[item.itemNo].push(item);
    });
    
    // Group by description
    const groupedItems = [];
    const itemNumbers = Object.keys(itemsByItemNo).sort((a, b) => 
        a.localeCompare(b, undefined, { numeric: true })
    );
    
    itemNumbers.forEach(itemNo => {
        const itemsWithSameNo = itemsByItemNo[itemNo];
        const byDescription = {};
        
        itemsWithSameNo.forEach(item => {
            const key = `${itemNo}|${item.description}`;
            if (!byDescription[key]) {
                byDescription[key] = {
                    itemNo: item.itemNo,
                    description: item.description,
                    category: item.category,
                    companies: []
                };
            }
            byDescription[key].companies.push(item);
        });
        
        Object.values(byDescription).forEach(group => {
            groupedItems.push(group);
        });
    });
    
    // Get UNIQUE companies lang
    const allCompanies = [...new Set(filteredItems.map(item => item.company))];
    allCompanies.sort();
    
    // MAXIMUM 10 COMPANIES PER TABLE
    const MAX_COMPANIES_PER_TABLE = 10;
    const companyTables = [];
    
    // Hatiin ang companies sa tables (maximum 10 per table)
    for (let i = 0; i < allCompanies.length; i += MAX_COMPANIES_PER_TABLE) {
        companyTables.push({
            name: `Supplier Group ${Math.floor(i/MAX_COMPANIES_PER_TABLE) + 1}`,
            companies: allCompanies.slice(i, i + MAX_COMPANIES_PER_TABLE)
        });
    }
    
    // Kung walang companies, mag-error
    if (companyTables.length === 0) {
        showNotification('No companies to display', 'error');
        return;
    }
    
    // Calculate totals per company
    const companyTotals = {};
    allCompanies.forEach(company => {
        let total = 0;
        groupedItems.forEach(group => {
            const companyItem = group.companies.find(c => c.company === company);
            if (companyItem) {
                total += companyItem.quantity * companyItem.price;
            }
        });
        companyTotals[company] = total;
    });
    
    // Calculate lowest price totals
    groupedItems.forEach(group => {
        if (group.companies.length > 0) {
            const lowestPriceItem = group.companies.reduce((prev, curr) => 
                (curr.price < prev.price) ? curr : prev, group.companies[0]);
            group.lowestTotal = lowestPriceItem.quantity * lowestPriceItem.price;
        } else {
            group.lowestTotal = 0;
        }
    });
    
    const grandTotal = groupedItems.reduce((sum, g) => sum + (g.lowestTotal || 0), 0);
    
    // Rows per page
    const ROWS_PER_PAGE = 15;
    const pages = [];
    for (let i = 0; i < groupedItems.length; i += ROWS_PER_PAGE) {
        pages.push(groupedItems.slice(i, i + ROWS_PER_PAGE));
    }
    
    if (pages.length === 0) pages.push([]);
    
    // Current date
    const today = new Date();
    const dateStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')} 00:00:00`;
    
    const getProjectName = (pageIndex) => {
        return pageIndex === 0 ? '6th flr' : '4TH & 5TH FLOOR';
    };
    
    // Create print window
    const printWindow = window.open('', '_blank');
    
    // Start HTML - WITH CENTERED TEXT STYLES
    let html = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>CANVASS FORM</title>
        <style>
            body { 
                font-family: 'Calibri', 'Arial', sans-serif; 
                margin: 0.75in 0.5in; 
                padding: 0; 
                background: white; 
                font-size: 10pt;
            }
            .page { 
                page-break-after: always; 
                page-break-inside: avoid; 
                position: relative;
            }
            .page:last-child { 
                page-break-after: auto; 
            }
            .company-header { 
                font-size: 14pt; 
                font-weight: bold; 
                text-align: left; 
                margin-bottom: 0; 
            }
            .ref-no { 
                position: absolute; 
                top: 0; 
                right: 0; 
                font-size: 10pt; 
            }
            .canvas-form { 
                font-size: 16pt; 
                font-weight: bold; 
                margin: 8px 0 10px 0; 
                text-align: left; 
                text-transform: uppercase;
            }
            .info-row { 
                display: flex; 
                margin: 6px 0; 
                font-size: 11pt;
                align-items: center;
            }
            .info-label { 
                width: 80px; 
                font-weight: 600; 
            }
            .info-value { 
                flex: 1; 
                border-bottom: 1px solid #000; 
                margin-left: 5px; 
            }
            .date-label {
                font-weight: 600;
                margin-left: 40px;
                margin-right: 5px;
            }
            .date-value {
                border-bottom: 1px solid #000;
                width: 180px;
            }
            .project-row { 
                display: flex; 
                margin: 6px 0; 
                font-size: 11pt;
                align-items: center;
            }
            .project-label { 
                width: 90px; 
                font-weight: 600; 
            }
            .project-value { 
                width: 250px; 
                border-bottom: 1px solid #000; 
                margin-left: 5px; 
            }
            .date-needed-label { 
                font-weight: 600; 
                margin-left: 40px; 
                margin-right: 5px;
            }
            .date-needed-value { 
                width: 180px; 
                border-bottom: 1px solid #000; 
            }
            table { 
                border-collapse: collapse; 
                width: 100%; 
                margin: 10px 0; 
                font-size: 9pt; 
                border: 1px solid #000;
            }
            th, td { 
                border: 1px solid #000; 
                padding: 4px 3px; 
                vertical-align: middle;
            }
            th { 
                background-color: #f0f0f0; 
                font-weight: 600; 
                text-align: center; 
            }
            td { 
                text-align: center; /* CENTER ALL TEXT IN ROWS */
            }
            .item-no-cell { 
                text-align: center; 
                font-weight: 600; 
            }
            .qty-cell, .unit-cell { 
                text-align: center; 
            }
            .price-cell { 
                text-align: center; /* Changed from right to center */
                padding-right: 0;
            }
            .formula-cell { 
                text-align: center; /* Changed from right to center */
                padding-right: 0;
                color: #006100;
                font-family: 'Courier New', monospace;
            }
            .total-row { 
                font-weight: 600; 
                background-color: #e8f0f8;
            }
            .company-section {
                margin-top: 15px;
                border-top: 2px solid #000;
                padding-top: 10px;
            }
            .company-section-title {
                font-weight: bold;
                font-size: 10pt;
                margin-bottom: 5px;
            }
            .signature-section { 
                margin-top: 30px; 
                font-size: 11pt; 
            }
            .signature-row { 
                display: flex; 
                margin: 8px 0; 
                align-items: center;
            }
            .signature-label { 
                width: 100px; 
                font-weight: 600; 
            }
            .signature-value { 
                flex: 1; 
                border-bottom: 1px solid #000; 
                margin-left: 10px; 
                padding-bottom: 2px;
            }
            .note { 
                font-size: 9pt; 
                margin-top: 15px; 
                font-style: italic; 
                text-align: left;
            }
            .note-bold { 
                font-weight: 700; 
                font-style: normal;
            }
            .page-number { 
                text-align: center; 
                font-size: 8pt; 
                color: #666; 
                margin-top: 10px; 
            }
        </style>
    </head>
    <body>`;
    
    // Generate each page
    pages.forEach((pageGroups, pageIndex) => {
        const isLastPage = pageIndex === pages.length - 1;
        const startRow = pageIndex * ROWS_PER_PAGE + 1;
        const projectName = getProjectName(pageIndex);
        
        html += `
        <div class="page">
            <div style="position: relative;">
                <div class="company-header">JLC BEST CONSTRUCTION OPC</div>
                <div class="ref-no">Ref. No. 202511-002</div>
            </div>
            
            <div class="canvas-form">CANVASS FORM</div>
            
            <div class="info-row">
                <span class="info-label">Customer:</span>
                <span class="info-value"></span>
                <span class="date-label">Date:</span>
                <span class="date-value">${dateStr}</span>
            </div>
            
            <div class="project-row">
                <span class="project-label">Project Name:</span>
                <span class="project-value">${projectName}</span>
                <span class="date-needed-label">Date Needed:</span>
                <span class="date-needed-value"></span>
            </div>`;
        
        // Create tables for EACH company group
        companyTables.forEach((table, tableIndex) => {
            const companiesInTable = table.companies;
            const isLastTable = tableIndex === companyTables.length - 1;
            
            html += `
            <div class="company-section">
                <div class="company-section-title">${table.name} (${companiesInTable.length} companies)</div>
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2" width="5%">Item No</th>
                            <th rowspan="2" width="20%">Description</th>
                            <th rowspan="2" width="10%">Category</th>
                            <th rowspan="2" width="5%">Qty.</th>
                            <th rowspan="2" width="5%">Unit</th>`;
            
            // Add headers for companies ONLY
            companiesInTable.forEach(company => {
                html += `<th colspan="2" width="${Math.floor(60 / companiesInTable.length)}%">${company}</th>`;
            });
            
            // Add lowest total column ONLY for last table
            if (isLastTable) {
                html += `<th rowspan="2" width="8%">Lowest<br>Total</th>`;
            }
            
            html += `</tr>
                    <tr>`;
            
            // Add price/total subheaders for companies ONLY
            companiesInTable.forEach(() => {
                html += `<th width="5%">Price</th><th width="5%">Total</th>`;
            });
            
            html += `</tr>
                </thead>
                <tbody>`;
            
            // Add data rows
            pageGroups.forEach((group, index) => {
                const rowNumber = startRow + index;
                const qty = group.companies[0]?.quantity || 0;
                
                html += `<tr>
                    <td class="item-no-cell">${group.itemNo}</td>
                    <td>${group.description}</td>
                    <td>${group.category || ''}</td>
                    <td class="qty-cell">${qty.toLocaleString()}</td>
                    <td class="unit-cell">pcs</td>`;
                
                // Add prices for companies ONLY
                companiesInTable.forEach(company => {
                    const companyItem = group.companies.find(c => c.company === company);
                    if (companyItem) {
                        const unitPrice = companyItem.price.toFixed(2);
                        const total = (companyItem.quantity * companyItem.price).toFixed(2);
                        html += `<td class="price-cell">${unitPrice}</td>
                                 <td class="formula-cell">=${total}</td>`;
                    } else {
                        html += `<td class="price-cell"></td>
                                 <td class="formula-cell"></td>`;
                    }
                });
                
                // Add lowest total for LAST table
                if (isLastTable) {
                    if (group.companies.length > 0) {
                        const lowestPriceItem = group.companies.reduce((prev, curr) => 
                            (curr.price < prev.price) ? curr : prev, group.companies[0]);
                        const lowestTotal = (lowestPriceItem.quantity * lowestPriceItem.price).toFixed(2);
                        html += `<td class="formula-cell">=${lowestTotal}</td>`;
                    } else {
                        html += `<td class="formula-cell"></td>`;
                    }
                }
                
                html += `</tr>`;
            });
            
            // Add empty rows
            const remainingRows = ROWS_PER_PAGE - pageGroups.length;
            for (let i = 0; i < remainingRows; i++) {
                const emptyRowNumber = startRow + pageGroups.length + i;
                html += `<tr>
                    <td class="item-no-cell">${emptyRowNumber}</td>
                    <td></td>
                    <td></td>
                    <td class="qty-cell"></td>
                    <td class="unit-cell"></td>`;
                
                companiesInTable.forEach(() => {
                    html += `<td class="price-cell"></td>
                             <td class="formula-cell"></td>`;
                });
                
                if (isLastTable) {
                    html += `<td class="formula-cell"></td>`;
                }
                
                html += `</tr>`;
            }
            
            // Add totals row
            html += `<tr class="total-row">
                <td colspan="5" style="text-align: right;"><strong>TOTAL:</strong></td>`;
            
            companiesInTable.forEach(company => {
                const total = companyTotals[company] || 0;
                html += `<td class="price-cell"></td>
                         <td class="formula-cell"><strong>=${total.toFixed(2)}</strong></td>`;
            });
            
            if (isLastTable) {
                html += `<td class="formula-cell"><strong>=${grandTotal.toFixed(2)}</strong></td>`;
            }
            
            html += `</tr>
                    </tbody>
                </table>
            </div>`;
        });
        
        // Add note and signature
        html += `
            <div class="note">
                <span class="note-bold">*NOTE:</span> Project Stocks
            </div>`;
        
        if (isLastPage) {
            html += `
            <div class="signature-section">
                <div class="signature-row">
                    <span class="signature-label">Canvass By:</span>
                    <span class="signature-value">ENGR. CM GALLOS</span>
                </div>
                <div class="signature-row">
                    <span class="signature-label">Date:</span>
                    <span class="signature-value"></span>
                </div>
                
                <div style="height: 15px;"></div>
                
                <div class="signature-row">
                    <span class="signature-label">Noted By:</span>
                    <span class="signature-value">Engr. Louisito De Guzman</span>
                </div>
                <div class="signature-row">
                    <span class="signature-label">Date:</span>
                    <span class="signature-value">${dateStr}</span>
                </div>
            </div>`;
        }
        
        html += `<div class="page-number">Page ${pageIndex + 1} of ${pages.length}</div>`;
        html += `</div>`;
    });
    
    html += `
        <script>
            window.onload = function() { 
                setTimeout(function() { 
                    window.print();
                }, 500);
            }
        <\/script>
    </body>
    </html>`;
    
    printWindow.document.write(html);
    printWindow.document.close();
}

// Helper function to get current date
function getCurrentDate() {
    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day} 00:00:00`;
}

// ==================== CART MODAL FUNCTIONS ====================

// Open Cart Modal
function openCartModal(button) {
    const row = button.closest('tr');
    
    currentCartItem = {
        priceId: row.getAttribute('data-price-id'),
        itemNo: row.getAttribute('data-item-no'),
        description: row.getAttribute('data-description'),
        category: row.getAttribute('data-category') || '',
        companyName: row.getAttribute('data-company'),
        contactPerson: row.getAttribute('data-contact'),
        contactNumber: row.getAttribute('data-contact-number'),
        availableQuantity: parseInt(row.getAttribute('data-quantity')),
        price: parseFloat(row.getAttribute('data-price')),
        companyColor: row.getAttribute('data-company-color'),
        rowElement: row
    };
    
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
            <span class="detail-label">Category</span>
            <span class="detail-value ${currentCartItem.category ? 'category-badge-modal' : ''}" ${currentCartItem.category ? 'style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;"' : ''}>
                ${currentCartItem.category || '—'}
            </span>
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
    document.getElementById('cartQuantity').value = 1;
    document.getElementById('cartQuantity').max = currentCartItem.availableQuantity;
    
    updateQuantityButtons();
    updateTotalPrice();
    
    document.getElementById('cartModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Cart Modal
function closeCartModal() {
    document.getElementById('cartModal').style.display = 'none';
    document.body.style.overflow = 'auto';
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

// Update quantity buttons
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
    
    if (quantity < 1) quantity = 1;
    if (quantity > currentCartItem.availableQuantity) quantity = currentCartItem.availableQuantity;
    
    input.value = quantity;
    
    const total = quantity * currentCartItem.price;
    document.getElementById('modalTotalPrice').innerHTML = `₱${total.toFixed(2)} <small>(${quantity} x ₱${currentCartItem.price.toFixed(2)})</small>`;
    
    updateQuantityButtons();
}

// Add to cart
function addToCart() {
    const quantity = parseInt(document.getElementById('cartQuantity').value);
    
    if (quantity > currentCartItem.availableQuantity) {
        showNotification('Cannot add more than available stock!', 'error');
        return;
    }
    
    const addToCartBtn = document.getElementById('addToCartBtn');
    const originalText = addToCartBtn.innerHTML;
    addToCartBtn.innerHTML = '<span class="loading-spinner"></span> Adding...';
    addToCartBtn.disabled = true;
    
    const purchaseData = {
        price_id: currentCartItem.priceId,
        item_no: currentCartItem.itemNo,
        description: currentCartItem.description,
        category: currentCartItem.category,
        company_name: currentCartItem.companyName,
        contact_person: currentCartItem.contactPerson,
        contact_number: currentCartItem.contactNumber,
        quantity: quantity,
        price: currentCartItem.price,
        total: quantity * currentCartItem.price,
        company_color: currentCartItem.companyColor
    };
    
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
            showNotification(`
                <strong>✅ Item added to Purchase List!</strong><br>
                Item: ${currentCartItem.itemNo} - ${currentCartItem.description}<br>
                Company: ${currentCartItem.companyName}<br>
                Quantity: ${quantity}<br>
                <small style="color: #75e6da;">✓ Redirecting to purchase.php...</small>
            `, 'success');
            
            closeCartModal();
            
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

// ==================== COMPANY FORM MODAL FUNCTIONS ====================

// Open Company Form Modal
function openCompanyFormModal() {
    document.getElementById('companyForm').reset();
    document.getElementById('totalPriceDisplay').innerHTML = '₱0.00';
    document.getElementById('companyFormModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Company Form Modal
function closeCompanyFormModal() {
    document.getElementById('companyFormModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Calculate total price in form
function calculateTotalPrice() {
    const quantity = parseInt(document.getElementById('availableQty').value) || 0;
    const price = parseFloat(document.getElementById('price').value) || 0;
    const total = quantity * price;
    
    document.getElementById('totalPriceDisplay').innerHTML = `₱${total.toFixed(2)} <small>(${quantity} × ₱${price.toFixed(2)})</small>`;
}

// ==================== VIEW MODAL FUNCTIONS ====================

// View Company Price
function viewCompanyPrice(priceId) {
    const row = document.querySelector(`.item-row[data-price-id="${priceId}"]`);
    
    if (!row) {
        showNotification('Price not found', 'error');
        return;
    }
    
    const itemNo = row.getAttribute('data-item-no');
    const description = row.getAttribute('data-description');
    const category = row.getAttribute('data-category') || '';
    const company = row.getAttribute('data-company');
    const contactPerson = row.getAttribute('data-contact');
    const contactNumber = row.getAttribute('data-contact-number');
    const quantity = parseInt(row.getAttribute('data-quantity'));
    const price = parseFloat(row.getAttribute('data-price'));
    const companyColor = row.getAttribute('data-company-color');
    const total = quantity * price;
    
    const viewHtml = `
        <div class="view-detail-item">
            <span class="view-detail-label">Item No</span>
            <span class="view-detail-value">${itemNo}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Description</span>
            <span class="view-detail-value">${description}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Category</span>
            <span class="view-detail-value ${category ? 'category-badge-view' : ''}" ${category ? 'style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;"' : ''}>${category || '—'}</span>
        </div>
        <div class="view-detail-item full-width">
            <span class="view-detail-label">Company</span>
            <span class="view-detail-value company-badge-view" style="background: ${companyColor}">${company}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Contact Person</span>
            <span class="view-detail-value">${contactPerson || '—'}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Contact Number</span>
            <span class="view-detail-value">${contactNumber || '—'}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Available Quantity</span>
            <span class="view-detail-value">${quantity.toLocaleString()}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Price per Unit</span>
            <span class="view-detail-value price-highlight">₱${price.toFixed(2)}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Total Price</span>
            <span class="view-detail-value" style="color: #6c5ce7; font-weight: 700;">₱${total.toFixed(2)}</span>
        </div>
        <div class="view-detail-item">
            <span class="view-detail-label">Availability</span>
            <span class="view-detail-value">
                <span class="availability-badge ${parseInt(row.getAttribute('data-availability')) ? 'available' : 'unavailable'}">
                    <i class="fas ${parseInt(row.getAttribute('data-availability')) ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                    ${parseInt(row.getAttribute('data-availability')) ? 'In Stock' : 'Out of Stock'}
                </span>
            </span>
        </div>
    `;
    
    document.getElementById('viewDetails').innerHTML = viewHtml;
    document.getElementById('viewModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close View Modal
function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ==================== EDIT MODAL FUNCTIONS ====================

// Edit Company Price
function editCompanyPrice(priceId) {
    const row = document.querySelector(`.item-row[data-price-id="${priceId}"]`);
    
    if (!row) {
        showNotification('Price not found', 'error');
        return;
    }
    
    const itemNo = row.getAttribute('data-item-no');
    const description = row.getAttribute('data-description');
    const category = row.getAttribute('data-category') || '';
    const company = row.getAttribute('data-company');
    const contactPerson = row.getAttribute('data-contact');
    const contactNumber = row.getAttribute('data-contact-number');
    const quantity = parseInt(row.getAttribute('data-quantity'));
    const price = parseFloat(row.getAttribute('data-price'));
    
    document.getElementById('editPriceId').value = priceId;
    document.getElementById('editItemNo').value = itemNo;
    document.getElementById('editDescription').value = description;
    document.getElementById('editCategory').value = category;
    document.getElementById('editCompanyName').value = company;
    document.getElementById('editContactPerson').value = contactPerson || '';
    document.getElementById('editContactNumber').value = contactNumber || '';
    document.getElementById('editQuantity').value = quantity;
    document.getElementById('editPrice').value = price.toFixed(2);
    
    calculateEditTotal();
    
    document.getElementById('editModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Edit Modal
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Calculate edit total
function calculateEditTotal() {
    const quantity = parseInt(document.getElementById('editQuantity').value) || 0;
    const price = parseFloat(document.getElementById('editPrice').value) || 0;
    const total = quantity * price;
    
    document.getElementById('editTotalPrice').innerHTML = `₱${total.toFixed(2)} <small>(${quantity} × ₱${price.toFixed(2)})</small>`;
}

// Handle edit form submit
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(editForm);
            
            const saveBtn = document.getElementById('saveEditBtn');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
            saveBtn.disabled = true;
            
            fetch('update_price.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Price updated successfully!', 'success');
                    closeEditModal();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification('Error: ' + data.message, 'error');
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error updating price', 'error');
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            });
        });
    }
});

// ==================== DELETE FUNCTIONS ====================

// Delete Company Price
function deleteCompanyPrice(priceId, companyName, itemNo) {
    pendingDeleteId = priceId;
    pendingDeleteCompany = companyName;
    pendingDeleteItem = itemNo;
    
    document.getElementById('deleteCompanyName').textContent = companyName || 'Unknown Company';
    document.getElementById('deleteItemNo').textContent = itemNo || 'Unknown Item';
    
    document.getElementById('deleteConfirmModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Delete Now
function deleteNow() {
    if (!pendingDeleteId) {
        showNotification('No item selected', 'error');
        closeDeleteConfirmModal();
        return;
    }
    
    closeDeleteConfirmModal();
    showNotification('Deleting...', 'info');
    
    const formData = new FormData();
    formData.append('price_id', pendingDeleteId);
    
    fetch('delete_price.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ Price deleted successfully!', 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification('❌ Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('❌ Error: ' + error, 'error');
    });
}

// Close Delete Confirm Modal
function closeDeleteConfirmModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ==================== NOTIFICATION FUNCTIONS ====================

// Show notification
function showNotification(message, type = 'success') {
    const existingNotification = document.querySelector('.cart-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    const notification = document.createElement('div');
    notification.className = `cart-notification ${type}`;
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 5000);
}

// ==================== MODAL CLICK HANDLERS ====================

// Close modal when clicking outside
window.onclick = function(event) {
    const cartModal = document.getElementById('cartModal');
    const companyFormModal = document.getElementById('companyFormModal');
    const viewModal = document.getElementById('viewModal');
    const editModal = document.getElementById('editModal');
    const comparisonModal = document.getElementById('comparisonModal');
    const deleteModal = document.getElementById('deleteConfirmModal');
    
    if (event.target == cartModal) closeCartModal();
    if (event.target == companyFormModal) closeCompanyFormModal();
    if (event.target == viewModal) closeViewModal();
    if (event.target == editModal) closeEditModal();
    if (event.target == comparisonModal) closeComparisonModal();
    if (event.target == deleteModal) closeDeleteConfirmModal();
}

// ==================== INITIALIZATION ====================

// Auto-hide success message
setTimeout(function() {
    const successMsg = document.getElementById('successMessage');
    if (successMsg) {
        successMsg.style.opacity = '0';
        setTimeout(() => {
            successMsg.style.display = 'none';
        }, 300);
    }
}, 5000);

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, initializing...');
    
    // Set initial search values from URL
    const urlParams = new URLSearchParams(window.location.search);
    const filterCategory = urlParams.get('filter_category');
    const filterCompany = urlParams.get('filter_company');
    const filterSearch = urlParams.get('filter_search');
    
    if (filterCategory) document.getElementById('filter_category').value = filterCategory;
    if (filterCompany) document.getElementById('filter_company').value = filterCompany;
    if (filterSearch) document.getElementById('filter_search').value = filterSearch;
    
    // Initialize current price sort from PHP
    <?php if ($active_sort == 'price' && $sort_by == 'price_desc'): ?>
    currentPriceSort = 'price_desc';
    <?php else: ?>
    currentPriceSort = 'price_asc';
    <?php endif; ?>
    
    console.log('Current price sort:', currentPriceSort);
    
    // Update sort icon and text on main page
    const sortIcon = document.getElementById('sortIcon');
    const sortText = document.getElementById('sortText');
    const priceSortBtn = document.getElementById('priceSortBtn');
    
    if (sortIcon && sortText && priceSortBtn) {
        <?php if ($active_sort == 'price'): ?>
            <?php if ($sort_by == 'price_desc'): ?>
            sortIcon.className = 'fas fa-sort-amount-up';
            sortText.textContent = 'Price High to Low';
            priceSortBtn.classList.add('active');
            <?php else: ?>
            sortIcon.className = 'fas fa-sort-amount-down';
            sortText.textContent = 'Price Low to High';
            priceSortBtn.classList.add('active');
            <?php endif; ?>
        <?php else: ?>
            sortIcon.className = 'fas fa-sort-amount-down';
            sortText.textContent = 'Price Low to High';
            priceSortBtn.classList.remove('active');
        <?php endif; ?>
    }
    
    // Attach event listener to price sort button
    if (priceSortBtn) {
        priceSortBtn.onclick = function(e) {
            e.preventDefault();
            togglePriceSort();
            return false;
        };
    }
    
    // Attach event listener to comparison sort button
    const toggleSortBtn = document.getElementById('togglePriceSortBtn');
    if (toggleSortBtn) {
        toggleSortBtn.onclick = function(e) {
            e.preventDefault();
            toggleComparisonSort();
            return false;
        };
    }
    
    // Attach event listener to comparison search
    const comparisonSearch = document.getElementById('comparisonSearch');
    if (comparisonSearch) {
        comparisonSearch.addEventListener('keyup', filterComparisonTable);
    }
});
</script>

<?php 
$conn->close();
require_once 'include/footer.php'; 
?>