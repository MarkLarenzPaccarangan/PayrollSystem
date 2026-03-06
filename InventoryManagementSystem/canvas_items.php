<?php
// canvas_items.php - Dynamic Companies Version (with Auto Total Price)
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

// ============================================
// HANDLE COMPANY CRUD
// ============================================

// Add new company
if (isset($_POST['add_company'])) {
    $name = trim($_POST['company_name']);
    $description = trim($_POST['company_description']);
    $contact_person = trim($_POST['contact_person']);
    
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO companies (name, description, contact_person) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $description, $contact_person);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Company added successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error adding company: " . $conn->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header("Location: canvas_items.php");
    exit();
}

// Delete company
if (isset($_GET['delete_company'])) {
    $company_id = intval($_GET['delete_company']);
    
    // Check if company has prices
    $check = $conn->query("SELECT COUNT(*) as count FROM company_prices WHERE company_id = $company_id");
    $count = $check->fetch_assoc()['count'];
    
    if ($count > 0) {
        $_SESSION['message'] = "Cannot delete company with existing items. Delete the items first.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Company deleted successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error deleting company: " . $conn->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header("Location: canvas_items.php");
    exit();
}

// ============================================
// HANDLE ITEM CRUD
// ============================================

// Add new item
if (isset($_POST['add_item'])) {
    $item_no = trim($_POST['item_no']);
    $description = trim($_POST['description']);
    
    $stmt = $conn->prepare("INSERT INTO canvas_items (item_no, description) VALUES (?, ?)");
    $stmt->bind_param("ss", $item_no, $description);
    
    if ($stmt->execute()) {
        $item_id = $stmt->insert_id;
        
        // Add company prices - from dynamic fields
        if (isset($_POST['companies']) && is_array($_POST['companies'])) {
            foreach ($_POST['companies'] as $index => $data) {
                if (!empty($data['company_name']) && (!empty($data['quantity']) || !empty($data['price']))) {
                    // First, check if company exists or create new
                    $company_name = trim($data['company_name']);
                    $contact_person = trim($data['contact_person'] ?? '');
                    $company_id = null;
                    
                    // Check if company exists
                    $check_company = $conn->prepare("SELECT id FROM companies WHERE name = ?");
                    $check_company->bind_param("s", $company_name);
                    $check_company->execute();
                    $result = $check_company->get_result();
                    
                    if ($result->num_rows > 0) {
                        $company = $result->fetch_assoc();
                        $company_id = $company['id'];
                        
                        // Update contact person if provided
                        if (!empty($contact_person)) {
                            $update_contact = $conn->prepare("UPDATE companies SET contact_person = ? WHERE id = ?");
                            $update_contact->bind_param("si", $contact_person, $company_id);
                            $update_contact->execute();
                            $update_contact->close();
                        }
                    } else {
                        // Create new company with contact person
                        $insert_company = $conn->prepare("INSERT INTO companies (name, contact_person) VALUES (?, ?)");
                        $insert_company->bind_param("ss", $company_name, $contact_person);
                        if ($insert_company->execute()) {
                            $company_id = $insert_company->insert_id;
                        }
                        $insert_company->close();
                    }
                    $check_company->close();
                    
                    if ($company_id) {
                        $quantity = intval($data['quantity']);
                        $price = floatval($data['price']);
                        $availability = isset($data['availability']) ? 1 : 0;
                        
                        $price_stmt = $conn->prepare("INSERT INTO company_prices (item_id, company_id, quantity, price, availability) VALUES (?, ?, ?, ?, ?)");
                        $price_stmt->bind_param("iiddi", $item_id, $company_id, $quantity, $price, $availability);
                        $price_stmt->execute();
                        $price_stmt->close();
                    }
                }
            }
        }
        
        $_SESSION['message'] = "Item added successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error adding item: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header("Location: canvas_items.php");
    exit();
}

// Edit item
if (isset($_POST['edit_item'])) {
    $item_id = intval($_POST['item_id']);
    $item_no = trim($_POST['item_no']);
    $description = trim($_POST['description']);
    
    $stmt = $conn->prepare("UPDATE canvas_items SET item_no = ?, description = ? WHERE id = ?");
    $stmt->bind_param("ssi", $item_no, $description, $item_id);
    
    if ($stmt->execute()) {
        // Delete existing prices
        $conn->query("DELETE FROM company_prices WHERE item_id = $item_id");
        
        // Add new prices - from dynamic fields
        if (isset($_POST['companies']) && is_array($_POST['companies'])) {
            foreach ($_POST['companies'] as $index => $data) {
                if (!empty($data['company_name']) && (!empty($data['quantity']) || !empty($data['price']))) {
                    // First, check if company exists or create new
                    $company_name = trim($data['company_name']);
                    $contact_person = trim($data['contact_person'] ?? '');
                    $company_id = null;
                    
                    // Check if company exists
                    $check_company = $conn->prepare("SELECT id FROM companies WHERE name = ?");
                    $check_company->bind_param("s", $company_name);
                    $check_company->execute();
                    $result = $check_company->get_result();
                    
                    if ($result->num_rows > 0) {
                        $company = $result->fetch_assoc();
                        $company_id = $company['id'];
                        
                        // Update contact person if provided
                        if (!empty($contact_person)) {
                            $update_contact = $conn->prepare("UPDATE companies SET contact_person = ? WHERE id = ?");
                            $update_contact->bind_param("si", $contact_person, $company_id);
                            $update_contact->execute();
                            $update_contact->close();
                        }
                    } else {
                        // Create new company with contact person
                        $insert_company = $conn->prepare("INSERT INTO companies (name, contact_person) VALUES (?, ?)");
                        $insert_company->bind_param("ss", $company_name, $contact_person);
                        if ($insert_company->execute()) {
                            $company_id = $insert_company->insert_id;
                        }
                        $insert_company->close();
                    }
                    $check_company->close();
                    
                    if ($company_id) {
                        $quantity = intval($data['quantity']);
                        $price = floatval($data['price']);
                        $availability = isset($data['availability']) ? 1 : 0;
                        
                        $price_stmt = $conn->prepare("INSERT INTO company_prices (item_id, company_id, quantity, price, availability) VALUES (?, ?, ?, ?, ?)");
                        $price_stmt->bind_param("iiddi", $item_id, $company_id, $quantity, $price, $availability);
                        $price_stmt->execute();
                        $price_stmt->close();
                    }
                }
            }
        }
        
        $_SESSION['message'] = "Item updated successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error updating item: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header("Location: canvas_items.php");
    exit();
}

// Delete item
if (isset($_GET['delete_item'])) {
    $item_id = intval($_GET['delete_item']);
    
    $stmt = $conn->prepare("DELETE FROM canvas_items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Item deleted successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting item: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header("Location: canvas_items.php");
    exit();
}

// ============================================
// FETCH DATA
// ============================================

// Get all companies (including contact_person)
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
    $companies->data_seek(0);
}

// Get all items with their company prices
$items = $conn->query("
    SELECT ci.*, 
           GROUP_CONCAT(CONCAT(cp.company_id, ':', cp.quantity, ':', cp.price, ':', cp.availability) SEPARATOR '|') as prices
    FROM canvas_items ci
    LEFT JOIN company_prices cp ON ci.id = cp.item_id
    GROUP BY ci.id
    ORDER BY ci.item_no
");

require_once 'include/header.php';
?>

<style>
/* Canvas Items Page Styles */

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

.action-btn.edit {
    background: linear-gradient(135deg, #75e6da, #6c5ce7);
    color: white;
}

.action-btn.delete {
    background: linear-gradient(135deg, #e84393, #d63031);
    color: white;
}

.action-btn.add {
    background: linear-gradient(135deg, #00b894, #75e6da);
    color: white;
    width: auto;
    padding: 0 15px;
}

.action-btn.back {
    background: linear-gradient(135deg, #6c757d, #495057);
    color: white;
    width: auto;
    padding: 0 15px;
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

/* Table styles */
.table-wrapper {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    overflow-x: auto;
    margin-top: 20px;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.products-table th {
    padding: 15px 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
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
}

.modal-content {
    position: relative;
    background: var(--bg-primary);
    margin: 50px auto;
    width: 90%;
    max-width: 1000px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    border: 1px solid var(--border-color);
}

.modal-sm {
    max-width: 400px;
}

.modal-lg {
    max-width: 1100px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #75e6da, #5fd9d0, #4ab9b0);
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
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #75e6da;
    outline: none;
}

.input-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.input-group-text {
    padding: 10px 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    margin-top: 8px;
}

/* Dynamic company row - with total price display */
.company-row {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    position: relative;
}

.remove-company {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #d63031;
    color: white;
    border: none;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.remove-company:hover {
    background: #b22222;
}

.company-fields {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto; /* Added column for total display */
    gap: 10px;
    align-items: center;
}

.company-total {
    font-weight: 700;
    color: #6c5ce7;
    background: rgba(108, 92, 231, 0.1);
    padding: 8px;
    border-radius: 8px;
    text-align: center;
    font-size: 13px;
    white-space: nowrap;
}

.add-company-btn {
    background: #75e6da;
    color: #1a1c3c;
    border: none;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    margin-top: 10px;
    width: 100%;
}

.add-company-btn:hover {
    background: #5bc8be;
}

/* Live total display */
.live-total {
    font-size: 12px;
    color: #6c5ce7;
    margin-left: 5px;
}

/* Comparison table in view modal */
.comparison-table {
    width: 100%;
    border-collapse: collapse;
}

.comparison-table th {
    background: var(--bg-secondary);
    padding: 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
}

.comparison-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.print-btn {
    background: white;
    color: #4e73df;
    border: 1px solid #4e73df;
    padding: 8px 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.print-btn:hover {
    background: #4e73df;
    color: white;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}
</style>

<div class="welcome-section">
    <div class="welcome-text">
        <h1>Canvas Items Management</h1>
        <p>Manage items and companies</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <!-- BACK button -->
        <a href="canvas.php" class="action-btn back">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <!-- Add Item button -->
        <button class="action-btn add" onclick="openAddItemModal()">
            <i class="fas fa-plus"></i> Add Item
        </button>
        <!-- View Comparison button -->
        <button class="action-btn add" onclick="openViewComparisonModal()" style="background: linear-gradient(135deg, #6c5ce7, #4e73df);">
            <i class="fas fa-chart-line"></i> View Comparison
        </button>
    </div>
</div>

<!-- Alert Message -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?>" style="margin-bottom: 20px; padding: 15px; border-radius: 8px; background: <?php echo $_SESSION['msg_type'] == 'success' ? '#75e6da' : '#d63031'; ?>; color: white;">
        <i class="fas <?php echo $_SESSION['msg_type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> 
        <?php 
            echo $_SESSION['message']; 
            unset($_SESSION['message']);
            unset($_SESSION['msg_type']);
        ?>
    </div>
<?php endif; ?>

<!-- Scrollable Table Wrapper -->
<div class="table-wrapper">
    <table class="products-table" id="canvasItemsTable">
        <thead>
            <tr>
                <th>Item No</th>
                <th>Description</th>
                <th>Company</th>
                <th>Contact Person</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Total Price</th>
                <th>Availability</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="canvasItemsBody">
            <?php if ($items && $items->num_rows > 0): ?>
                <?php 
                $items->data_seek(0);
                $has_data = false;
                while($item = $items->fetch_assoc()): 
                    // Parse prices
                    $item_prices = [];
                    if ($item['prices']) {
                        $price_parts = explode('|', $item['prices']);
                        foreach ($price_parts as $part) {
                            if (!empty($part)) {
                                list($company_id, $quantity, $price, $availability) = explode(':', $part);
                                if (isset($companies_array[$company_id])) {
                                    $item_prices[] = [
                                        'company_id' => $company_id,
                                        'company_name' => $companies_array[$company_id]['name'],
                                        'company_color' => $company_colors[$company_id],
                                        'contact_person' => $companies_array[$company_id]['contact_person'] ?? '',
                                        'quantity' => intval($quantity),
                                        'price' => floatval($price),
                                        'availability' => intval($availability)
                                    ];
                                    $has_data = true;
                                }
                            }
                        }
                    }
                    
                    if (!empty($item_prices)):
                        foreach($item_prices as $price_data):
                            // Calculate total price (Quantity × Price)
                            $total_price = $price_data['quantity'] * $price_data['price'];
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($item['item_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td>
                            <span class="company-badge" style="background: <?php echo $price_data['company_color']; ?>">
                                <?php echo htmlspecialchars($price_data['company_name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($price_data['contact_person'])): ?>
                                <span class="contact-person">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($price_data['contact_person']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $price_data['quantity']; ?></td>
                        <td class="price-cell">₱<?php echo number_format($price_data['price'], 2); ?></td>
                        <td>
                            <span class="total-price-cell">
                                ₱<?php echo number_format($total_price, 2); ?>
                            </span>
                        </td>
                        <td>
                            <?php if($price_data['availability']): ?>
                                <span class="availability-badge available"><i class="fas fa-check-circle"></i> In Stock</span>
                            <?php else: ?>
                                <span class="availability-badge unavailable"><i class="fas fa-times-circle"></i> Out</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions-cell">
                            <button class="action-btn edit" onclick="editItem(<?php echo $item['id']; ?>)" title="Edit Item">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn delete" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_no']); ?>')" title="Delete Item">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endwhile; ?>
                
                <?php if (!$has_data): ?>
                    <tr>
                        <td colspan="9" class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>No price data available. Add prices to items.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>No canvas items found. Click "Add Item" to get started.</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ADD ITEM MODAL (with auto total price computation) -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Add New Item</h2>
            <button class="close-btn" onclick="closeAddItemModal()">&times;</button>
        </div>
        <form method="POST" id="addItemForm">
            <div class="modal-body">
                <div class="form-group">
                    <label>Item No.</label>
                    <input type="text" name="item_no" required placeholder="e.g., CAN-001">
                </div>
                
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" rows="2" placeholder="Enter item description"></textarea>
                </div>
                
                <h3 style="color: var(--text-primary); margin: 20px 0 15px;">Company Prices</h3>
                
                <div id="dynamicCompanyFields">
                    <!-- Dynamic company fields will be added here -->
                    <div class="company-row" id="company-row-0">
                        <button type="button" class="remove-company" onclick="removeCompanyRow(0)" style="display: none;">×</button>
                        <div class="company-fields">
                            <input type="text" name="companies[0][company_name]" placeholder="Company Name" required>
                            <input type="text" name="companies[0][contact_person]" placeholder="Contact Person" id="contact_0" onkeyup="updateTotal(0)">
                            <input type="number" name="companies[0][quantity]" placeholder="Quantity" value="0" min="0" id="qty_0" onkeyup="updateTotal(0)" onchange="updateTotal(0)">
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" name="companies[0][price]" placeholder="Price" value="0.00" min="0" id="price_0" onkeyup="updateTotal(0)" onchange="updateTotal(0)">
                            </div>
                            <div class="company-total" id="total_0">₱0.00</div>
                            <label class="checkbox-label">
                                <input type="checkbox" name="companies[0][availability]" checked> Available
                            </label>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="add-company-btn" onclick="addCompanyRow()">
                    <i class="fas fa-plus"></i> Add Another Company
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddItemModal()">Cancel</button>
                <button type="submit" name="add_item" class="btn btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW COMPARISON MODAL -->
<div id="viewComparisonModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-chart-line"></i> Price Comparison</h2>
            <button class="close-btn" onclick="closeViewComparisonModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="text-align: right; margin-bottom: 15px;">
                <button class="print-btn" onclick="printComparison()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
            
            <table class="comparison-table" id="comparisonTable">
                <thead>
                    <tr>
                        <th>Item No</th>
                        <th>Description</th>
                        <th>Company</th>
                        <th>Contact Person</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Total Price</th>
                        <th>Availability</th>
                    </tr>
                </thead>
                <tbody id="comparisonTableBody">
                    <!-- Will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- EDIT ITEM MODAL (with auto total price computation) -->
<div id="editItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Item</h2>
            <button class="close-btn" onclick="closeEditItemModal()">&times;</button>
        </div>
        <form method="POST" id="editItemForm">
            <div class="modal-body">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div class="form-group">
                    <label>Item No.</label>
                    <input type="text" name="item_no" id="edit_item_no" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" rows="2"></textarea>
                </div>
                
                <h3 style="color: var(--text-primary); margin: 20px 0 15px;">Company Prices</h3>
                
                <div id="editDynamicCompanyFields">
                    <!-- Will be populated by JavaScript -->
                </div>
                
                <button type="button" class="add-company-btn" onclick="addEditCompanyRow()">
                    <i class="fas fa-plus"></i> Add Another Company
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditItemModal()">Cancel</button>
                <button type="submit" name="edit_item" class="btn btn-primary">Update Item</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE ITEM MODAL -->
<div id="deleteItemModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-trash"></i> Delete Item</h2>
            <button class="close-btn" onclick="closeDeleteItemModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color: var(--text-primary); margin-bottom: 20px;">Are you sure you want to delete this item?</p>
            <p style="color: var(--text-secondary); margin-bottom: 10px;">Item: <strong id="delete_item_number"></strong></p>
            <p style="color: #d63031; font-size: 14px;"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteItemModal()">Cancel</button>
            <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete Item</a>
        </div>
    </div>
</div>

<!-- DELETE COMPANY MODAL -->
<div id="deleteCompanyModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-trash"></i> Delete Company</h2>
            <button class="close-btn" onclick="closeDeleteCompanyModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color: var(--text-primary); margin-bottom: 20px;">Are you sure you want to delete this company?</p>
            <p style="color: var(--text-secondary); margin-bottom: 10px;">Company: <strong id="delete_company_name"></strong></p>
            <p style="color: #d63031; font-size: 14px;"><i class="fas fa-exclamation-triangle"></i> This will only work if no items are using this company.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteCompanyModal()">Cancel</button>
            <a href="#" id="confirmDeleteCompanyBtn" class="btn btn-danger">Delete Company</a>
        </div>
    </div>
</div>

<script>
let companyRowCount = 1;
let editCompanyRowCount = 0;

// Function to update total price
function updateTotal(rowId) {
    const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
    const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
    const total = qty * price;
    document.getElementById(`total_${rowId}`).innerHTML = `₱${total.toFixed(2)}`;
}

// Add Item Modal functions
function openAddItemModal() {
    document.getElementById('addItemModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeAddItemModal() {
    document.getElementById('addItemModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    // Reset form
    document.getElementById('addItemForm').reset();
    // Reset dynamic fields to just one row
    document.getElementById('dynamicCompanyFields').innerHTML = `
        <div class="company-row" id="company-row-0">
            <button type="button" class="remove-company" onclick="removeCompanyRow(0)" style="display: none;">×</button>
            <div class="company-fields">
                <input type="text" name="companies[0][company_name]" placeholder="Company Name" required>
                <input type="text" name="companies[0][contact_person]" placeholder="Contact Person" id="contact_0" onkeyup="updateTotal(0)">
                <input type="number" name="companies[0][quantity]" placeholder="Quantity" value="0" min="0" id="qty_0" onkeyup="updateTotal(0)" onchange="updateTotal(0)">
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" step="0.01" name="companies[0][price]" placeholder="Price" value="0.00" min="0" id="price_0" onkeyup="updateTotal(0)" onchange="updateTotal(0)">
                </div>
                <div class="company-total" id="total_0">₱0.00</div>
                <label class="checkbox-label">
                    <input type="checkbox" name="companies[0][availability]" checked> Available
                </label>
            </div>
        </div>
    `;
    companyRowCount = 1;
    updateTotal(0);
}

function addCompanyRow() {
    const container = document.getElementById('dynamicCompanyFields');
    const newRow = document.createElement('div');
    newRow.className = 'company-row';
    newRow.id = `company-row-${companyRowCount}`;
    newRow.innerHTML = `
        <button type="button" class="remove-company" onclick="removeCompanyRow(${companyRowCount})">×</button>
        <div class="company-fields">
            <input type="text" name="companies[${companyRowCount}][company_name]" placeholder="Company Name" required>
            <input type="text" name="companies[${companyRowCount}][contact_person]" placeholder="Contact Person" id="contact_${companyRowCount}" onkeyup="updateTotal(${companyRowCount})">
            <input type="number" name="companies[${companyRowCount}][quantity]" placeholder="Quantity" value="0" min="0" id="qty_${companyRowCount}" onkeyup="updateTotal(${companyRowCount})" onchange="updateTotal(${companyRowCount})">
            <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" step="0.01" name="companies[${companyRowCount}][price]" placeholder="Price" value="0.00" min="0" id="price_${companyRowCount}" onkeyup="updateTotal(${companyRowCount})" onchange="updateTotal(${companyRowCount})">
            </div>
            <div class="company-total" id="total_${companyRowCount}">₱0.00</div>
            <label class="checkbox-label">
                <input type="checkbox" name="companies[${companyRowCount}][availability]" checked> Available
            </label>
        </div>
    `;
    container.appendChild(newRow);
    companyRowCount++;
    
    // Show remove button on first row if hidden
    const firstRowRemoveBtn = document.querySelector('#company-row-0 .remove-company');
    if (firstRowRemoveBtn) {
        firstRowRemoveBtn.style.display = 'block';
    }
}

function removeCompanyRow(index) {
    const row = document.getElementById(`company-row-${index}`);
    if (row) {
        row.remove();
    }
    
    // If only one row left, hide its remove button
    const rows = document.querySelectorAll('#dynamicCompanyFields .company-row');
    if (rows.length === 1) {
        const removeBtn = rows[0].querySelector('.remove-company');
        if (removeBtn) {
            removeBtn.style.display = 'none';
        }
    }
}

// View Comparison Modal functions
function openViewComparisonModal() {
    // Fetch all data via AJAX
    fetch('get_all_comparison.php')
        .then(response => response.json())
        .then(data => {
            let html = '';
            data.forEach(item => {
                item.prices.forEach(price => {
                    const availabilityClass = price.availability ? 'available' : 'unavailable';
                    const availabilityText = price.availability ? 'In Stock' : 'Out of Stock';
                    const totalPrice = price.quantity * price.price;
                    const contactPerson = price.contact_person || '—';
                    
                    html += `
                        <tr>
                            <td>${item.item_no}</td>
                            <td>${item.description}</td>
                            <td><span class="company-badge" style="background: ${price.company_color}">${price.company_name}</span></td>
                            <td>${contactPerson}</td>
                            <td>${price.quantity}</td>
                            <td class="price-cell">₱${parseFloat(price.price).toFixed(2)}</td>
                            <td><span class="total-price-cell">₱${totalPrice.toFixed(2)}</span></td>
                            <td><span class="availability-badge ${availabilityClass}"><i class="fas ${price.availability ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${availabilityText}</span></td>
                        </tr>
                    `;
                });
            });
            
            document.getElementById('comparisonTableBody').innerHTML = html || '<tr><td colspan="8" class="empty-state">No data available</td></tr>';
            document.getElementById('viewComparisonModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        })
        .catch(error => {
            alert('Error loading comparison data');
        });
}

function closeViewComparisonModal() {
    document.getElementById('viewComparisonModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function printComparison() {
    const printWindow = window.open('', '_blank');
    const tableContent = document.getElementById('comparisonTable').outerHTML;
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Canvas Price Comparison Report</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #4e73df; color: white; padding: 10px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                .price-cell { font-weight: bold; color: #1cc88a; }
                .total-price-cell { font-weight: bold; color: #6c5ce7; }
                .available { color: #1cc88a; }
                .unavailable { color: #e74a3b; }
            </style>
        </head>
        <body>
            <h1>Canvas Price Comparison Report</h1>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            ${tableContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// Edit Item functions
function editItem(id) {
    fetch('get_item_details.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('edit_item_id').value = data.item.id;
            document.getElementById('edit_item_no').value = data.item.item_no;
            document.getElementById('edit_description').value = data.item.description || '';
            
            // Build dynamic company fields
            let html = '';
            editCompanyRowCount = 0;
            
            if (data.prices && Object.keys(data.prices).length > 0) {
                Object.keys(data.prices).forEach(companyId => {
                    const priceData = data.prices[companyId];
                    // Find company name and contact person
                    const company = data.companies.find(c => c.id == companyId);
                    const companyName = company ? company.name : '';
                    const contactPerson = company ? (company.contact_person || '') : '';
                    const total = priceData.quantity * priceData.price;
                    
                    html += `
                        <div class="company-row" id="edit-company-row-${editCompanyRowCount}">
                            <button type="button" class="remove-company" onclick="removeEditCompanyRow(${editCompanyRowCount})">×</button>
                            <div class="company-fields">
                                <input type="text" name="companies[${editCompanyRowCount}][company_name]" value="${companyName}" placeholder="Company Name" required>
                                <input type="text" name="companies[${editCompanyRowCount}][contact_person]" value="${contactPerson}" placeholder="Contact Person" id="edit_contact_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})">
                                <input type="number" name="companies[${editCompanyRowCount}][quantity]" value="${priceData.quantity}" min="0" placeholder="Quantity" id="edit_qty_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})" onchange="updateEditTotal(${editCompanyRowCount})">
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" name="companies[${editCompanyRowCount}][price]" value="${priceData.price}" min="0" placeholder="Price" id="edit_price_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})" onchange="updateEditTotal(${editCompanyRowCount})">
                                </div>
                                <div class="company-total" id="edit_total_${editCompanyRowCount}">₱${total.toFixed(2)}</div>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="companies[${editCompanyRowCount}][availability]" ${priceData.availability == 1 ? 'checked' : ''}> Available
                                </label>
                            </div>
                        </div>
                    `;
                    editCompanyRowCount++;
                });
            } else {
                // Add one empty row
                html = `
                    <div class="company-row" id="edit-company-row-0">
                        <button type="button" class="remove-company" onclick="removeEditCompanyRow(0)" style="display: none;">×</button>
                        <div class="company-fields">
                            <input type="text" name="companies[0][company_name]" placeholder="Company Name" required>
                            <input type="text" name="companies[0][contact_person]" placeholder="Contact Person" id="edit_contact_0" onkeyup="updateEditTotal(0)">
                            <input type="number" name="companies[0][quantity]" placeholder="Quantity" value="0" min="0" id="edit_qty_0" onkeyup="updateEditTotal(0)" onchange="updateEditTotal(0)">
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" name="companies[0][price]" placeholder="Price" value="0.00" min="0" id="edit_price_0" onkeyup="updateEditTotal(0)" onchange="updateEditTotal(0)">
                            </div>
                            <div class="company-total" id="edit_total_0">₱0.00</div>
                            <label class="checkbox-label">
                                <input type="checkbox" name="companies[0][availability]" checked> Available
                            </label>
                        </div>
                    </div>
                `;
                editCompanyRowCount = 1;
            }
            
            document.getElementById('editDynamicCompanyFields').innerHTML = html;
            document.getElementById('editItemModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        })
        .catch(error => {
            alert('Error fetching item data');
        });
}

function updateEditTotal(rowId) {
    const qty = parseFloat(document.getElementById(`edit_qty_${rowId}`).value) || 0;
    const price = parseFloat(document.getElementById(`edit_price_${rowId}`).value) || 0;
    const total = qty * price;
    document.getElementById(`edit_total_${rowId}`).innerHTML = `₱${total.toFixed(2)}`;
}

function addEditCompanyRow() {
    const container = document.getElementById('editDynamicCompanyFields');
    const newRow = document.createElement('div');
    newRow.className = 'company-row';
    newRow.id = `edit-company-row-${editCompanyRowCount}`;
    newRow.innerHTML = `
        <button type="button" class="remove-company" onclick="removeEditCompanyRow(${editCompanyRowCount})">×</button>
        <div class="company-fields">
            <input type="text" name="companies[${editCompanyRowCount}][company_name]" placeholder="Company Name" required>
            <input type="text" name="companies[${editCompanyRowCount}][contact_person]" placeholder="Contact Person" id="edit_contact_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})">
            <input type="number" name="companies[${editCompanyRowCount}][quantity]" placeholder="Quantity" value="0" min="0" id="edit_qty_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})" onchange="updateEditTotal(${editCompanyRowCount})">
            <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" step="0.01" name="companies[${editCompanyRowCount}][price]" placeholder="Price" value="0.00" min="0" id="edit_price_${editCompanyRowCount}" onkeyup="updateEditTotal(${editCompanyRowCount})" onchange="updateEditTotal(${editCompanyRowCount})">
            </div>
            <div class="company-total" id="edit_total_${editCompanyRowCount}">₱0.00</div>
            <label class="checkbox-label">
                <input type="checkbox" name="companies[${editCompanyRowCount}][availability]" checked> Available
            </label>
        </div>
    `;
    container.appendChild(newRow);
    editCompanyRowCount++;
    
    // Show remove button on first row if hidden
    const firstRowRemoveBtn = document.querySelector('#edit-company-row-0 .remove-company');
    if (firstRowRemoveBtn) {
        firstRowRemoveBtn.style.display = 'block';
    }
}

function removeEditCompanyRow(index) {
    const row = document.getElementById(`edit-company-row-${index}`);
    if (row) {
        row.remove();
    }
    
    // If only one row left, hide its remove button
    const rows = document.querySelectorAll('#editDynamicCompanyFields .company-row');
    if (rows.length === 1) {
        const removeBtn = rows[0].querySelector('.remove-company');
        if (removeBtn) {
            removeBtn.style.display = 'none';
        }
    }
}

function closeEditItemModal() {
    document.getElementById('editItemModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Delete functions
function deleteItem(id, itemNo) {
    document.getElementById('delete_item_number').textContent = itemNo;
    document.getElementById('confirmDeleteBtn').href = 'canvas_items.php?delete_item=' + id;
    document.getElementById('deleteItemModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeDeleteItemModal() {
    document.getElementById('deleteItemModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function deleteCompany(id, name) {
    document.getElementById('delete_company_name').textContent = name;
    document.getElementById('confirmDeleteCompanyBtn').href = 'canvas_items.php?delete_company=' + id;
    document.getElementById('deleteCompanyModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeDeleteCompanyModal() {
    document.getElementById('deleteCompanyModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['addItemModal', 'viewComparisonModal', 'editItemModal', 'deleteItemModal', 'deleteCompanyModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target == modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    });
}

// Initialize totals on page load
document.addEventListener('DOMContentLoaded', function() {
    updateTotal(0);
});
</script>

<?php require_once 'include/footer.php'; ?>