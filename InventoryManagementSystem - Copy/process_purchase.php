<?php
// process_purchase.php - From Canvas (Sets status to PENDING only - NO STOCK UPDATE)
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get current user
$current_user = getCurrentUser();
$user_id = $current_user['id'] ?? 3;

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit();
}

// Validate required fields
$required = ['price_id', 'item_no', 'description', 'company_name', 'quantity', 'price', 'total'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
        exit();
    }
}

// Sanitize inputs
$price_id = intval($input['price_id']);
$quantity = intval($input['quantity']);
$price = floatval($input['price']);
$total = floatval($input['total']);
$item_no = $conn->real_escape_string($input['item_no']);
$description = $conn->real_escape_string($input['description']);
$company_name = $conn->real_escape_string($input['company_name']);
$contact_person = $conn->real_escape_string($input['contact_person'] ?? '');
$contact_number = $conn->real_escape_string($input['contact_number'] ?? '');
$company_color = $conn->real_escape_string($input['company_color'] ?? '#6c5ce7');
$customer_name = $conn->real_escape_string($input['customer_name'] ?? 'Walk-in Customer');
$payment_method = $conn->real_escape_string($input['payment_method'] ?? 'cash');
$payment_status = $conn->real_escape_string($input['payment_status'] ?? 'pending'); // Change to 'pending'

// Generate unique purchase number
$purchase_number = 'PUR-' . date('YmdHis') . '-' . rand(1000, 9999);

// IMPORTANT: No transaction needed for pending items
// We just insert a PENDING record without affecting stock

try {
    // Get current stock info but DON'T update it yet
    $stock_query = "SELECT cp.quantity, cp.item_id, ci.id as canvas_item_id, ci.unit 
                   FROM company_prices cp 
                   LEFT JOIN canvas_items ci ON cp.item_id = ci.id 
                   WHERE cp.id = ?";
    $stock_stmt = $conn->prepare($stock_query);
    $stock_stmt->bind_param("i", $price_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    
    if ($stock_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => "Price record not found"]);
        exit();
    }
    
    $stock_data = $stock_result->fetch_assoc();
    $current_stock = $stock_data['quantity'];
    $item_id = $stock_data['item_id'];
    $unit = $stock_data['unit'] ?? 'pcs';
    
    // Check if enough stock (validation only - no update yet)
    if ($current_stock < $quantity) {
        echo json_encode(['success' => false, 'message' => "Insufficient stock. Available: $current_stock"]);
        exit();
    }
    
    // Check if product exists in products table (but don't update yet)
    $check_product_sql = "SELECT id FROM products WHERE item_no = ? OR description LIKE ? LIMIT 1";
    $check_stmt = $conn->prepare($check_product_sql);
    $search_desc = "%$description%";
    $check_stmt->bind_param("ss", $item_no, $search_desc);
    $check_stmt->execute();
    $product_result = $check_stmt->get_result();
    
    $product_id = null;
    
    if ($product_result->num_rows > 0) {
        $product = $product_result->fetch_assoc();
        $product_id = $product['id'];
        // DON'T update product stock yet - this will happen when order is confirmed
    }
    
    // REMOVED: Stock update, product stock update, stock movement recording
    // These will happen when user clicks "Order Now" in purchase.php
    
    // Insert purchase with PENDING status only - NO stock updates yet
    $purchase_sql = "INSERT INTO purchases (
        purchase_number, customer_name, item_no, description, 
        company_name, contact_person, contact_number,
        price_id, product_id, quantity_purchased, price_per_unit, total_amount,
        available_before, available_after, company_color,
        payment_method, payment_status, status, purchase_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
    // REMOVED: stock_movement_id because no movement yet
    
    $purchase_stmt = $conn->prepare($purchase_sql);
    $purchase_stmt->bind_param(
        "ssssssssiisddssss",
        $purchase_number,
        $customer_name,
        $item_no,
        $description,
        $company_name,
        $contact_person,
        $contact_number,
        $price_id,
        $product_id,
        $quantity,
        $price,
        $total,
        $current_stock,    // available_before
        $current_stock,    // available_after (SAME because stock not deducted yet)
        $company_color,
        $payment_method,
        $payment_status
    );
    
    if (!$purchase_stmt->execute()) {
        echo json_encode(['success' => false, 'message' => "Failed to create purchase: " . $conn->error]);
        exit();
    }
    
    $purchase_id = $conn->insert_id;
    
    // Success response - redirect to purchase.php (pending list)
    echo json_encode([
        'success' => true,
        'message' => 'Item added to pending purchase list!',
        'purchase_number' => $purchase_number,
        'purchase_id' => $purchase_id,
        'item_no' => $item_no,
        'description' => $description,
        'unit' => $unit,
        'date' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        'stock_not_deducted' => true, // Important: stock not deducted yet
        'redirect' => 'purchase.php' // Redirect to purchase.php (pending list)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>