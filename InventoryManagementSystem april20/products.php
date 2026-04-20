<?php
// Start output buffering
ob_start();

require_once 'config.php';

// Require login
requireLogin();

// Get products data
$products = $conn->query("SELECT * FROM products ORDER BY id DESC");

// Get statistics for products page
$totalProducts = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] ?? 0;
$categoryCount = $conn->query("SELECT COUNT(DISTINCT category) as cat_count FROM products WHERE category IS NOT NULL AND category != ''")->fetch_assoc()['cat_count'] ?? 0;
$avgPrice = number_format($conn->query("SELECT AVG(price) as avg_price FROM products")->fetch_assoc()['avg_price'] ?? 0, 2);
$outOfStock = $conn->query("SELECT COUNT(*) as out_count FROM products WHERE quantity = 0")->fetch_assoc()['out_count'] ?? 0;
$lowStock = $conn->query("SELECT COUNT(*) as low_count FROM products WHERE quantity > 0 AND quantity <= 20")->fetch_assoc()['low_count'] ?? 0;

// Get categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");

// Get category distribution
$catDistribution = $conn->query("SELECT category, COUNT(*) as count FROM products WHERE category IS NOT NULL AND category != '' GROUP BY category");

// Get unique units for the dropdown (from existing products)
$existingUnits = $conn->query("SELECT DISTINCT unit FROM products WHERE unit IS NOT NULL AND unit != '' ORDER BY unit");

$current_user = getCurrentUser();

// Include header
require_once 'include/header.php';
?>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Products Management</h1>
        <p>Manage your product inventory</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()">
        <i class="fas fa-plus"></i>
        Add New Product
    </button>
</div>

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
            <i class="fas fa-peso-sign"></i>
        </div>
        <div class="stat-details">
            <h3>AVG PRICE</h3>
            <p class="stat-value">₱<?php echo $avgPrice; ?></p>
            <span class="stat-trend positive">Per item</span>
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

<div class="filters-section" style="justify-content: space-between; flex-wrap: wrap;">
    <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary);">Category Distribution</h3>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <?php if ($catDistribution && $catDistribution->num_rows > 0): ?>
            <?php while($cat = $catDistribution->fetch_assoc()): ?>
                <span class="category-badge" style="background: linear-gradient(135deg, #75e6da, #6c5ce7); color: white; padding: 8px 15px; border: none;">
                    <?php echo htmlspecialchars($cat['category']); ?>: <?php echo $cat['count']; ?>
                </span>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Top Bar with Search - Left aligned -->
<div class="top-bar" style="justify-content: flex-start; animation: fadeInUp 0.5s ease 0.55s both;">
    <div class="search-wrapper" style="width: 300px; animation: fadeInUp 0.5s ease 0.55s both;">
        <i class="fas fa-search"></i>
        <input type="text" id="productSearch" placeholder="Search products..." onkeyup="searchProducts()">
    </div>
</div>

<!-- Scrollable Table Wrapper -->
<div class="table-wrapper" style="max-height: 400px; overflow-y: auto; overflow-x: auto;">
    <table class="products-table" id="productsTable">
        <thead style="position: sticky; top: 0; background: var(--bg-secondary); z-index: 10;">
            <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th>Product Description</th>
                <th>Price</th>
                <th>Quantity Stock</th>
                <th>Unit</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="productsPageBody">
            <?php if ($products && $products->num_rows > 0): ?>
                <?php while($row = $products->fetch_assoc()): 
                    $quantity = $row['quantity'];
                    $unit = htmlspecialchars($row['unit'] ?? 'pcs');
                    $description = htmlspecialchars($row['description'] ?? '');
                    
                    // Calculate stock percentage for visual indicator (max 100%)
                    $stockPercentage = min(($quantity / 50) * 100, 100);
                    $stockColor = $quantity > 20 ? '#75e6da' : ($quantity > 0 ? '#f39c12' : '#d63031');
                ?>
                    <tr data-product-id="<?php echo $row['id']; ?>" class="product-row">
                        <td class="product-cell">
                            <div class="product-info">
                                <div class="product-icon"><i class="fas fa-box"></i></div>
                                <div class="product-details">
                                    <h4><?php echo htmlspecialchars($row['name']); ?></h4>
                                </div>
                            </div>
                        </td>
                        <td><span class="category-badge"><?php echo htmlspecialchars($row['category'] ?? 'Accessories'); ?></span></td>
                        <td><?php echo $description; ?></td>
                        <td class="price-cell">₱<?php echo number_format($row['price'], 2); ?></td>
                        <td class="stock-cell">
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 12px; padding: 2px 8px; border-radius: 12px; background-color: <?php echo $stockColor; ?>20; color: <?php echo $stockColor; ?>; font-weight: 500;">
                                        <?php 
                                        if($quantity == 0) echo 'Sold Out';
                                        else if($quantity <= 5) echo 'Critical';
                                        else if($quantity <= 20) echo 'Low Stock';
                                        else echo 'In Stock';
                                        ?>
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 8px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                        <div style="width: <?php echo $stockPercentage; ?>%; height: 100%; background-color: <?php echo $stockColor; ?>; border-radius: 4px;"></div>
                                    </div>
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $stockColor; ?>;">
                                        <?php echo $quantity; ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="unit-cell">
                            <span class="category-badge" style="background: linear-gradient(135deg, #75e6da, #6c5ce7);"><?php echo $unit; ?></span>
                        </td>
                        <td class="actions-cell">
                            <button class="action-btn edit" onclick='openEditModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['name']); ?>, <?php echo json_encode($row['description'] ?? ''); ?>, <?php echo $row['price']; ?>, <?php echo $row['quantity']; ?>, <?php echo json_encode($row['category'] ?? ''); ?>, <?php echo json_encode($unit); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="action-btn delete" onclick='openDeleteModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['name']); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                            <button class="action-btn view" onclick='openViewModal(<?php echo json_encode($row); ?>)' title="View"><i class="fas fa-eye"></i></button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- No Results Message -->
<div id="noResultsMessage" style="display: none; text-align: center; padding: 40px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; margin-top: 20px;">
    <i class="fas fa-search" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 15px;"></i>
    <h3 style="color: var(--text-primary); margin-bottom: 10px;">No Products Found</h3>
    <p style="color: var(--text-secondary);">No products match your search criteria. Try different keywords.</p>
</div>

<!-- Add/Edit Modal -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="background: linear-gradient(135deg, #75e6da, #5fd9d0, #4ab9b0);">
            <h2 id="modalTitle">Add New Product</h2>
            <!-- Close button removed - using Cancel button only -->
        </div>
        <form id="productForm" method="POST" action="products_process.php">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="product_id" id="productId" value="">
            
            <div class="form-group">
                <label for="productName">Product Name <span class="required">*</span></label>
                <input type="text" id="productName" name="name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="productCategory">Category <span class="required">*</span></label>
                <select id="productCategory" name="category" class="form-control" required>
                    <option value="">Select Category</option>
                    <option value="Accessories">Accessories</option>
                    <option value="Electronics">Electronics</option>
                    <option value="Hardware">Hardware</option>
                    <?php 
                    // Reset categories pointer and add any existing categories from database
                    $categories->data_seek(0);
                    $existing_categories = [];
                    while($cat = $categories->fetch_assoc()): 
                        $cat_name = $cat['category'];
                        // Only add if not already in the hardcoded list
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
            
            <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
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
                        
                        <!-- Accessories Units -->
                        <optgroup label="Accessories Units">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="pair">Pair (pair)</option>
                            <option value="set">Set (set)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="box">Box (box)</option>
                            <option value="dozen">Dozen (dozen)</option>
                            <option value="roll">Roll (roll)</option>
                            <option value="bundle">Bundle (bundle)</option>
                            <option value="strip">Strip (strip)</option>
                            <option value="card">Card (card)</option>
                        </optgroup>
                        
                        <!-- Electronics Units -->
                        <optgroup label="Electronics Units">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="unit">Unit (unit)</option>
                            <option value="set">Set (set)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="box">Box (box)</option>
                            <option value="carton">Carton (carton)</option>
                            <option value="piece">Piece (piece)</option>
                            <option value="kit">Kit (kit)</option>
                            <option value="system">System (system)</option>
                            <option value="module">Module (module)</option>
                        </optgroup>
                        
                        <!-- Hardware Units -->
                        <optgroup label="Hardware Units">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="set">Set (set)</option>
                            <option value="box">Box (box)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="bundle">Bundle (bundle)</option>
                            <option value="roll">Roll (roll)</option>
                            <option value="meter">Meter (m)</option>
                            <option value="feet">Feet (ft)</option>
                            <option value="inch">Inch (in)</option>
                            <option value="kilogram">Kilogram (kg)</option>
                            <option value="pound">Pound (lb)</option>
                            <option value="gallon">Gallon (gal)</option>
                            <option value="liter">Liter (l)</option>
                            <option value="sheet">Sheet (sheet)</option>
                            <option value="board">Board (board)</option>
                            <option value="length">Length (len)</option>
                        </optgroup>
                        
                        <!-- Common Units (appears in multiple categories) -->
                        <optgroup label="Common Units">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="box">Box (box)</option>
                            <option value="pack">Pack (pack)</option>
                            <option value="set">Set (set)</option>
                        </optgroup>
                    </select>
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
        <div class="modal-header" style="background: linear-gradient(135deg, #75e6da, #5fd9d0, #4ab9b0);">
            <h2>Confirm Delete</h2>
            <!-- Close button removed - using Cancel button only -->
        </div>
        <div class="modal-body" style="padding: 20px;">
            <p>Are you sure you want to delete <strong id="deleteProductName"></strong>?</p>
            <p style="color: #d63031; font-size: 14px; margin-top: 10px;">This action cannot be undone.</p>
        </div>
        <div class="modal-footer" style="padding: 20px; border-top: 1px solid var(--border-color);">
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
    <div class="alert alert-success" style="position: fixed; top: 20px; right: 20px; z-index: 9999; padding: 15px 20px; background: #75e6da; color: #1a1c3c; border-radius: 8px; box-shadow: 0 4px 6px rgba(117, 230, 218, 0.3);">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="position: fixed; top: 20px; right: 20px; z-index: 9999; padding: 15px 20px; background: #d63031; color: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(214, 48, 49, 0.3);">
        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<style>

/* Color Palette Variables - Matching home.css */
:root {
    --teal-primary: #75e6da;
    --teal-secondary: #5fd9d0;
    --teal-tertiary: #4ab9b0;
    --purple-accent: #6c5ce7;
    --pink-accent: #e84393;
    --orange-accent: #f39c12;
    --green-accent: #00b894;
    --red-accent: #d63031;
    --navy-dark: #1a1c3c;
    --navy-light: #242858;
    --border-navy: #2f3366;
}

/* Enhanced button styles with teal palette */
.modal-footer .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    font-weight: 500;
    transition: all 0.3s ease;
    border-radius: 8px;
}

.modal-footer .btn i {
    font-size: 14px;
}

.modal-footer .btn-primary {
    background: linear-gradient(135deg, var(--teal-primary), var(--purple-accent));
    border: none;
    box-shadow: 0 4px 15px rgba(117, 230, 218, 0.4);
    color: white;
}

.modal-footer .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(117, 230, 218, 0.6);
}

.modal-footer .btn-secondary {
    background: var(--navy-light);
    border: 1px solid var(--border-navy);
    color: var(--teal-primary);
}

.modal-footer .btn-secondary:hover {
    background: var(--navy-dark);
    transform: translateY(-2px);
    border-color: var(--teal-primary);
    box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3);
}

.modal-footer .btn-danger {
    background: linear-gradient(135deg, var(--pink-accent), var(--red-accent));
    border: none;
    box-shadow: 0 4px 15px rgba(232, 67, 147, 0.4);
    color: white;
}

.modal-footer .btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(232, 67, 147, 0.6);
}

/* Alert enhancements with teal */
.alert {
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: var(--teal-primary) !important;
    color: var(--navy-dark) !important;
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

/* Action buttons enhancement with teal accents */
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

.action-btn.edit {
    background: linear-gradient(135deg, var(--teal-primary), var(--purple-accent));
    color: white;
    box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
}

.action-btn.delete {
    background: linear-gradient(135deg, var(--pink-accent), var(--red-accent));
    color: white;
    box-shadow: 0 4px 10px rgba(232, 67, 147, 0.3);
}

.action-btn.view {
    background: linear-gradient(135deg, var(--green-accent), var(--teal-primary));
    color: white;
    box-shadow: 0 4px 10px rgba(0, 184, 148, 0.3);
}

/* Category badge enhancement with teal */
.category-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    background: linear-gradient(135deg, var(--teal-primary), var(--purple-accent));
    color: white;
    box-shadow: 0 2px 8px rgba(117, 230, 218, 0.3);
    transition: all 0.3s ease;
}

.category-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(117, 230, 218, 0.4);
}

/* Modal header with teal gradient */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, var(--teal-primary), var(--teal-secondary), var(--teal-tertiary)) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.modal-header h2 {
    margin: 0;
    color: white;
    font-size: 18px;
    font-weight: 600;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Price cell with teal */
.price-cell {
    font-weight: 600;
    color: var(--teal-primary);
    transition: color 0.3s ease;
}

/* Stat trend positive with teal */
.stat-trend.positive {
    color: var(--teal-primary);
}

/* Form focus states with teal */
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--teal-primary);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

.form-group input:hover,
.form-group select:hover,
.form-group textarea:hover {
    border-color: var(--teal-primary);
}

/* Checkbox accent color */
.checkbox-label input[type="checkbox"] {
    accent-color: var(--teal-primary);
}

/* Status badges with teal */
.status-instock {
    background: rgba(117, 230, 218, 0.15);
    color: var(--teal-primary);
}

/* Search wrapper hover with teal */
.search-wrapper:hover {
    border-color: var(--teal-primary);
    box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
}

.search-wrapper:hover i {
    color: var(--teal-primary);
}

/* Notification button hover with teal */
.notification-btn:hover {
    color: var(--teal-primary);
    border-color: var(--teal-primary);
    box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
}

/* Theme toggle hover with teal */
.theme-toggle:hover {
    color: var(--teal-primary);
    border-color: var(--teal-primary);
    box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
}

/* Footer icons with teal */
.footer-info i {
    color: var(--teal-primary);
}

/* Stat card hover with teal */
.stat-card:hover {
    border-color: var(--teal-primary);
    box-shadow: 0 10px 25px rgba(117, 230, 218, 0.2);
}

/* Filter select hover with teal */
.filter-select:hover {
    border-color: var(--teal-primary);
}

/* Required field indicator */
.required {
    color: var(--teal-primary);
    font-size: 14px;
    margin-left: 2px;
}

/* Toast notification with teal */
.toast {
    border-left: 3px solid var(--teal-primary);
}

.toast i {
    color: var(--teal-primary);
}

/* Product icon with teal gradient */
.product-icon {
    background: linear-gradient(135deg, var(--teal-primary), var(--purple-accent)) !important;
}

/* Light theme specific overrides */
body.light-theme .btn-secondary {
    background: #f0f9f8;
    color: #2c3e50;
}

body.light-theme .btn-secondary:hover {
    background: #e0f2ef;
}

/* Style for optgroup in select */
select optgroup {
    font-weight: 600;
    color: var(--teal-primary);
    background-color: var(--navy-dark);
}

select optgroup option {
    font-weight: normal;
    color: var(--text-primary);
    padding: 8px;
}

select optgroup option:hover {
    background-color: var(--teal-primary);
}

/* Sticky header for table */
.products-table thead th {
    position: sticky;
    top: 0;
    background: var(--bg-secondary);
    z-index: 10;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Ensure thead stays above table content */
.products-table thead tr {
    position: relative;
    z-index: 10;
}
</style>

<script>
// Real-time search function - triggers on every keystroke
function searchProducts() {
    const searchTerm = document.getElementById('productSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#productsPageBody .product-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const productName = row.querySelector('.product-details h4')?.textContent.toLowerCase() || '';
        const category = row.querySelector('.category-badge')?.textContent.toLowerCase() || '';
        const description = row.cells[2]?.textContent.toLowerCase() || '';
        const price = row.querySelector('.price-cell')?.textContent.toLowerCase() || '';
        const unit = row.querySelector('.unit-cell .category-badge')?.textContent.toLowerCase() || '';
        const stockStatus = row.querySelector('.stock-cell span:first-child')?.textContent.toLowerCase() || '';
        
        // Check if search term matches any field
        const matches = productName.includes(searchTerm) || 
                       category.includes(searchTerm) || 
                       description.includes(searchTerm) || 
                       price.includes(searchTerm) || 
                       unit.includes(searchTerm) || 
                       stockStatus.includes(searchTerm);
        
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
    
    // Show/hide no results message
    const noResultsMsg = document.getElementById('noResultsMessage');
    if (visibleCount === 0 && searchTerm !== '') {
        noResultsMsg.style.display = 'block';
    } else {
        noResultsMsg.style.display = 'none';
    }
}

// Debounced search for better performance
let searchTimeout;
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchProducts, 100);
}

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
    // Clear the values
    document.getElementById('deleteProductId').value = '';
    document.getElementById('deleteProductName').textContent = '';
}

function openViewModal(product) {
    // Parse product if it's a string
    if (typeof product === 'string') {
        product = JSON.parse(product);
    }
    
    alert(`Product Details:
Name: ${product.name}
Category: ${product.category || 'N/A'}
Description: ${product.description || 'N/A'}
Price: ₱${parseFloat(product.price).toFixed(2)}
Quantity: ${product.quantity}
Unit: ${product.unit || 'pcs'}`);
}

function closeModal() {
    document.getElementById('productModal').style.display = 'none';
}

// Close modal when clicking outside
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

// Add keyboard support
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

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

// Add search on keyup with debounce for better performance
document.getElementById('productSearch').addEventListener('keyup', debouncedSearch);
</script>

<?php
// Include footer
require_once 'include/footer.php';
?>