<?php
// Start output buffering
ob_start();

require_once 'config.php';

// Require login
requireLogin();

// Get sort parameter - default to name_asc
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';

// Build ORDER BY clause based on sort
switch($sort) {
    case 'name_asc':
        $order_by = "ORDER BY name ASC";
        $current_sort_label = 'Product Name (A-Z)';
        break;
    case 'name_desc':
        $order_by = "ORDER BY name DESC";
        $current_sort_label = 'Product Name (Z-A)';
        break;
    case 'category_asc':
        $order_by = "ORDER BY category ASC, name ASC";
        $current_sort_label = 'Category (A-Z)';
        break;
    case 'category_desc':
        $order_by = "ORDER BY category DESC, name ASC";
        $current_sort_label = 'Category (Z-A)';
        break;
    default:
        $order_by = "ORDER BY name ASC";
        $current_sort_label = 'Product Name (A-Z)';
}

// Get products data with sorting
$products = $conn->query("SELECT * FROM products $order_by");

// Get statistics for products page
$totalProducts = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] ?? 0;
$categoryCount = $conn->query("SELECT COUNT(DISTINCT category) as cat_count FROM products WHERE category IS NOT NULL AND category != ''")->fetch_assoc()['cat_count'] ?? 0;
$outOfStock = $conn->query("SELECT COUNT(*) as out_count FROM products WHERE quantity = 0")->fetch_assoc()['out_count'] ?? 0;

// Get categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");

$current_user = getCurrentUser();

// Include header
require_once 'include/header.php';
?>

<style>
/* Sort Dropdown Styles */
.sort-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sort-label {
    color: var(--text-secondary);
    font-size: 14px;
}

.sort-dropdown {
    position: relative;
    display: inline-block;
}

.sort-button {
    padding: 10px 20px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    min-width: 200px;
    justify-content: space-between;
}

.sort-button:hover {
    border-color: #75e6da;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

.sort-button i {
    color: #75e6da;
    font-size: 14px;
}

.sort-button .arrow {
    color: #75e6da;
    font-size: 12px;
}

.sort-dropdown-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    margin-top: 5px;
    background: var(--bg-primary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    z-index: 1000;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    overflow: hidden;
}

.sort-dropdown-content.show {
    display: block;
}

.sort-option {
    padding: 12px 15px;
    cursor: pointer;
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sort-option:last-child {
    border-bottom: none;
}

.sort-option:hover {
    background: rgba(117, 230, 218, 0.1);
    color: #75e6da;
}

.sort-option.active {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.sort-option i {
    margin-right: 10px;
    width: 16px;
    color: #75e6da;
}

.sort-option.active i {
    color: white;
}

/* Category badge */
.category-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

/* Action buttons */
.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    margin: 0 3px;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.action-btn.edit {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.action-btn.delete {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
}

.action-btn.view {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
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
}

.stat-card:hover {
    transform: translateY(-5px);
    border-color: #75e6da;
    box-shadow: 0 10px 25px rgba(117, 230, 218, 0.2);
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

.stat-trend.positive {
    color: #75e6da;
}

.stat-trend.negative {
    color: #d63031;
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

/* Search wrapper */
.search-wrapper {
    width: 300px;
    position: relative;
}

.search-wrapper i {
    position: absolute;
    left: 30px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.search-wrapper input {
    width: 100%;
    padding: 12px 12px 12px 40px;
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

/* Top bar */
.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

/* ===== CONTAINER DESIGN GAYA NG PURCHASE.PHP ===== */
.page-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 0;
    overflow-x: auto;
    overflow-y: visible;
    min-height: 300px;
    display: flex;
    flex-direction: column;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
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

.price-cell {
    font-weight: 600;
    color: #75e6da;
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

/* Product icon */
.product-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-details h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

/* ===== EMPTY STATE - GAYA NG PURCHASE.PHP ===== */
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
    opacity: 0.5;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 24px;
    color: var(--text-primary);
    margin-bottom: 10px;
    font-weight: 600;
}

.empty-state p {
    color: var(--text-primary); /* SAME COLOR AS H3 */
    font-size: 16px;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 20px;
    font-weight: 500; /* Para medyo bold din */
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
    overflow-y: auto;
}

.modal-content {
    background: var(--bg-primary);
    margin: 50px auto;
    width: 90%;
    max-width: 600px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.modal-header {
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

.modal-body {
    padding: 20px;
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
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 14px;
}

.form-group .required {
    color: #75e6da;
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #75e6da;
    outline: none;
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
}

.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
}

/* Button styles */
.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
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
    box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
}

/* Alert styles */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    padding: 15px 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: #75e6da;
    color: #1a1c3c;
}

.alert-danger {
    background: #d63031;
    color: white;
}

@keyframes slideIn {
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
        <h1>Products Management</h1>
        <p>Manage your product inventory</p>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-cubes"></i>
        </div>
        <div class="stat-details">
            <h3>TOTAL PRODUCTS</h3>
            <p class="stat-value"><?php echo $totalProducts; ?></p>
            <span class="stat-trend positive">Active items</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-tags"></i>
        </div>
        <div class="stat-details">
            <h3>CATEGORIES</h3>
            <p class="stat-value"><?php echo $categoryCount; ?></p>
            <span class="stat-trend positive">Categories</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-details">
            <h3>OUT OF STOCK</h3>
            <p class="stat-value"><?php echo $outOfStock; ?></p>
            <span class="stat-trend negative">Need restock</span>
        </div>
    </div>
</div>

<!-- Top Bar with Search and Sort Dropdown -->
<div class="top-bar">
    <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" id="productSearch" placeholder="Search products..." onkeyup="searchProducts()">
    </div>
    
    <!-- Single Button with Dropdown -->
    <div class="sort-container">
        <span class="sort-label">Sort by:</span>
        <div class="sort-dropdown" id="sortDropdown">
            <button class="sort-button" onclick="toggleDropdown()">
                <span><i class="fas fa-sort"></i> <?php echo $current_sort_label; ?></span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="sort-dropdown-content" id="sortDropdownContent">
                <div class="sort-option <?php echo strpos($sort, 'name') === 0 ? 'active' : ''; ?>" onclick="applySort('name')">
                    <span><i class="fas fa-font"></i> Product Name</span>
                    <?php if(strpos($sort, 'name') === 0): ?>
                        <i class="fas fa-arrow-<?php echo $sort == 'name_asc' ? 'up' : 'down'; ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="sort-option <?php echo strpos($sort, 'category') === 0 ? 'active' : ''; ?>" onclick="applySort('category')">
                    <span><i class="fas fa-tags"></i> Category</span>
                    <?php if(strpos($sort, 'category') === 0): ?>
                        <i class="fas fa-arrow-<?php echo $sort == 'category_asc' ? 'up' : 'down'; ?>"></i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== PRODUCTS CONTAINER - MAY EMPTY STATE KUNG WALANG ITEMS ===== -->
<div class="page-container" id="tableContainer">
    <?php if ($products && $products->num_rows > 0): ?>
        <!-- Products Table (kapag may laman) -->
        <table class="products-table" id="productsTable">
            <thead>
                <tr>
                    <th>Item No</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Quantity Stock</th>
                    <th>Unit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="productsPageBody">
                <?php while($row = $products->fetch_assoc()): 
                    $quantity = $row['quantity'];
                    $unit = htmlspecialchars($row['unit'] ?? 'pcs');
                    
                    // I-extract ang item_no at description mula sa name
                    $full_name = $row['name'];
                    $item_no = '';
                    $item_description = htmlspecialchars($row['description'] ?? '');
                    
                    // Check kung may " - " sa name
                    if (strpos($full_name, ' - ') !== false) {
                        $parts = explode(' - ', $full_name, 2);
                        $item_no = trim($parts[0]);
                        // Kung walang laman ang description sa database, gamitin ang pangalawang part
                        if (empty($item_description)) {
                            $item_description = trim($parts[1]);
                        }
                    } else {
                        // Kung walang " - ", gamitin ang buong name bilang item_no
                        $item_no = $full_name;
                    }
                    
                    // Calculate stock percentage
                    $stockPercentage = min(($quantity / 50) * 100, 100);
                    
                    // Determine status and colors
                    if($quantity == 0) {
                        $status = 'Out of Stock';
                        $statusColor = '#a00c0c';
                        $bgColor = '#d6303120';
                    } else if($quantity <= 5) {
                        $status = 'Critical';
                        $statusColor = '#ff0000';
                        $bgColor = '#d6303120';
                    } else if($quantity <= 20) {
                        $status = 'Low Stock';
                        $statusColor = '#e2e60c';
                        $bgColor = '#f39c1220';
                    } else {
                        $status = 'In Stock';
                        $statusColor = '#00ff4c';
                        $bgColor = '#00b89420';
                    }
                ?>
                    <tr data-product-id="<?php echo $row['id']; ?>" class="product-row">
                        <td>
                            <div class="product-info">
                                <div class="product-icon">
                                    <i class="fas fa-box"></i>
                                </div>
                                <div class="product-details">
                                    <h4><?php echo htmlspecialchars($item_no); ?></h4>
                                </div>
                            </div>
                        </td>
                        <td><span class="category-badge"><?php echo htmlspecialchars($row['category'] ?? 'Accessories'); ?></span></td>
                        <td><?php echo htmlspecialchars($item_description); ?></td>
                        <td class="price-cell">₱<?php echo number_format($row['price'], 2); ?></td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 12px; padding: 4px 12px; border-radius: 20px; background-color: <?php echo $bgColor; ?>; color: <?php echo $statusColor; ?>; font-weight: 600;">
                                        <?php echo $status; ?>
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 8px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                        <div style="width: <?php echo $stockPercentage; ?>%; height: 100%; background-color: <?php echo $statusColor; ?>; border-radius: 4px;"></div>
                                    </div>
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $statusColor; ?>;">
                                        <?php echo $quantity; ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td><span class="category-badge"><?php echo $unit; ?></span></td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="action-btn edit" onclick='openEditModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['name']); ?>, <?php echo json_encode($row['description'] ?? ''); ?>, <?php echo $row['price']; ?>, <?php echo $row['quantity']; ?>, <?php echo json_encode($row['category'] ?? ''); ?>, <?php echo json_encode($unit); ?>)' title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn delete" onclick='openDeleteModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['name']); ?>)' title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <button class="action-btn view" onclick='openViewModal(<?php echo json_encode($row); ?>)' title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <!-- ===== EMPTY STATE - GAYA NG PURCHASE.PHP ===== -->
        <div class="empty-state">
            <i class="fas fa-store"></i>
            <h3>No Items and products Found</h3>
            <p>You need to purchased</p>
        </div>
    <?php endif; ?>
</div>

<!-- No Results Message (for search) - nasa labas ng container -->
<div id="noResultsMessage" class="no-results" style="display: none;">
    <i class="fas fa-search"></i>
    <h3>No Products Found</h3>
    <p>No products match your search criteria. Try different keywords.</p>
</div>

<!-- Add/Edit Modal -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Product</h2>
        </div>
        <form id="productForm" method="POST" action="products_process.php">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="product_id" id="productId" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="productName">Product Name <span class="required">*</span></label>
                    <input type="text" id="productName" name="name" class="form-control" required>
                    <small style="color: var(--text-secondary);">Format: Item No - Description (e.g., 0123 - graba)</small>
                </div>
                
                <div class="form-group">
                    <label for="productCategory">Category <span class="required">*</span></label>
                    <select id="productCategory" name="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Hardware">Hardware</option>
                        <?php 
                        $categories->data_seek(0);
                        $existing_categories = [];
                        while($cat = $categories->fetch_assoc()): 
                            $cat_name = $cat['category'];
                            $hardcoded = ['Accessories', 'Electronics', 'Hardware'];
                            if (!in_array($cat_name, $hardcoded) && !in_array($cat_name, $existing_categories)) {
                                $existing_categories[] = $cat_name;
                                echo '<option value="' . htmlspecialchars($cat_name) . '">' . htmlspecialchars($cat_name) . '</option>';
                            }
                        endwhile; 
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="productDescription">Product Description</label>
                    <textarea id="productDescription" name="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="productPrice">Price (₱) <span class="required">*</span></label>
                        <input type="number" id="productPrice" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group" style="flex: 1;">
                        <label for="productQuantity">Quantity <span class="required">*</span></label>
                        <input type="number" id="productQuantity" name="quantity" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group" style="flex: 1;">
                        <label for="productUnit">Unit <span class="required">*</span></label>
                        <select id="productUnit" name="unit" class="form-control" required>
                            <option value="">Select Unit</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="pair">Pair (pair)</option>
                            <option value="set">Set (set)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="box">Box (box)</option>
                            <option value="dozen">Dozen (dozen)</option>
                            <option value="roll">Roll (roll)</option>
                            <option value="bundle">Bundle (bundle)</option>
                            <option value="meter">Meter (m)</option>
                            <option value="feet">Feet (ft)</option>
                            <option value="kilogram">Kilogram (kg)</option>
                            <option value="liter">Liter (l)</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">
                    <i class="fas fa-save"></i> <span id="submitBtnText">Save Product</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2>Confirm Delete</h2>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteProductName"></strong>?</p>
            <p style="color: #d63031; font-size: 14px; margin-top: 10px;">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" action="products_process.php" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="product_id" id="deleteProductId" value="">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Delete Product
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Success/Error Message Display -->
<?php if(isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<script>
// EXACT MATCH SEARCH FUNCTION
function searchProducts() {
    const searchTerm = document.getElementById('productSearch').value.trim();
    const rows = document.querySelectorAll('#productsPageBody .product-row');
    let visibleCount = 0;
    
    const noResultsMsg = document.getElementById('noResultsMessage');
    
    rows.forEach(row => {
        // Kunin ang item_no mula sa product-details h4
        const itemNo = row.querySelector('.product-details h4')?.textContent || '';
        
        // Kunin ang description mula sa description cell (index 2)
        const description = row.cells[2]?.textContent || '';
        
        // Kunin ang category
        const category = row.querySelector('.category-badge')?.textContent || '';
        
        // EXACT MATCH: check kung ang search term ay exactly match
        let matches = false;
        
        if (searchTerm === '') {
            matches = true;
        } else {
            // Check exact match sa item number
            if (itemNo === searchTerm) {
                matches = true;
            }
            // Check exact match sa description
            else if (description === searchTerm) {
                matches = true;
            }
            // Check exact match sa category
            else if (category === searchTerm) {
                matches = true;
            }
        }
        
        if (matches) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Show/hide no results message
    if (visibleCount === 0 && searchTerm !== '') {
        noResultsMsg.style.display = 'block';
    } else {
        noResultsMsg.style.display = 'none';
    }
    
    console.log('Search completed. Visible rows:', visibleCount);
}

// Toggle dropdown
function toggleDropdown() {
    const dropdown = document.getElementById('sortDropdownContent');
    dropdown.classList.toggle('show');
}

// Apply sort
function applySort(type) {
    const currentSort = '<?php echo $sort; ?>';
    let newSort;
    
    if (currentSort.startsWith(type)) {
        // Toggle between asc and desc
        newSort = currentSort === type + '_asc' ? type + '_desc' : type + '_asc';
    } else {
        // Start with asc
        newSort = type + '_asc';
    }
    
    const url = new URL(window.location.href);
    url.searchParams.set('sort', newSort);
    window.location.href = url.toString();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('sortDropdownContent');
    const button = document.querySelector('.sort-button');
    
    if (button && dropdown) {
        if (!button.contains(event.target) && !dropdown.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    }
});

// Modal functions
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New Product';
    document.getElementById('formAction').value = 'add';
    document.getElementById('productId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('productCategory').value = '';
    document.getElementById('productDescription').value = '';
    document.getElementById('productPrice').value = '';
    document.getElementById('productQuantity').value = '';
    document.getElementById('productUnit').value = 'pcs';
    document.getElementById('submitBtnText').textContent = 'Add Product';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-plus"></i> <span id="submitBtnText">Add Product</span>';
    document.getElementById('productModal').style.display = 'block';
}

function openEditModal(id, name, description, price, quantity, category, unit) {
    document.getElementById('modalTitle').textContent = 'Edit Product';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('productId').value = id;
    document.getElementById('productName').value = name;
    document.getElementById('productCategory').value = category;
    document.getElementById('productDescription').value = description;
    document.getElementById('productPrice').value = price;
    document.getElementById('productQuantity').value = quantity;
    document.getElementById('productUnit').value = unit || 'pcs';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-save"></i> <span id="submitBtnText">Save Product</span>';
    document.getElementById('productModal').style.display = 'block';
}

function openDeleteModal(id, name) {
    document.getElementById('deleteProductId').value = id;
    document.getElementById('deleteProductName').textContent = name;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    document.getElementById('deleteProductId').value = '';
    document.getElementById('deleteProductName').textContent = '';
}

function openViewModal(product) {
    if (typeof product === 'string') {
        product = JSON.parse(product);
    }
    
    alert(`Product Details:
Item No: ${product.name.split(' - ')[0] || product.name}
Category: ${product.category || 'N/A'}
Description: ${product.description || product.name.split(' - ')[1] || ''}
Price: ₱${parseFloat(product.price).toFixed(2)}
Quantity: ${product.quantity}
Unit: ${product.unit || 'pcs'}`);
}

function closeModal() {
    document.getElementById('productModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('productModal');
    const deleteModal = document.getElementById('deleteModal');
    if (event.target == modal) {
        closeModal();
    }
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
}

// Auto-hide alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.style.display = 'none', 300);
    });
}, 3000);

// Debounced search
let searchTimeout;
document.getElementById('productSearch').addEventListener('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchProducts, 300);
});

// Initial search
document.addEventListener('DOMContentLoaded', function() {
    searchProducts();
});
</script>

<?php
// Include footer
require_once 'include/footer.php';
?>