<?php
// Start session and output buffering
session_start();
ob_start();

// Include database configuration
require_once 'config.php';

// Require login - using the function from config.php

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: products.php');
    exit();
}

// Get the action type
$action = $_POST['action'] ?? '';

// Process based on action
switch ($action) {
    case 'add':
        // Add new product
        addProduct($conn);
        break;
        
    case 'edit':
        // Edit existing product
        editProduct($conn);
        break;
        
    case 'delete':
        // Delete product
        deleteProduct($conn);
        break;
        
    default:
        // Invalid action
        $_SESSION['error'] = 'Invalid action specified.';
        header('Location: products.php');
        exit();
}

/**
 * Add a new product to the database
 */
function addProduct($conn) {
    // Get and sanitize form data
    $name = sanitizeInput($conn, $_POST['name'] ?? '');
    $category = sanitizeInput($conn, $_POST['category'] ?? '');
    $description = sanitizeInput($conn, $_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    $unit = sanitizeInput($conn, $_POST['unit'] ?? 'pcs');
    
    // Parse item_no from name (format: "ITEM_NO - Description")
    $item_no = '';
    if (strpos($name, ' - ') !== false) {
        $parts = explode(' - ', $name, 2);
        $item_no = trim($parts[0]);
        if (empty($description)) {
            $description = trim($parts[1]);
        }
    } else {
        $item_no = $name;
    }
    
    // Validate required fields
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Product name is required.';
    }
    
    if (empty($category)) {
        $errors[] = 'Category is required.';
    }
    
    if ($price <= 0) {
        $errors[] = 'Price must be greater than zero.';
    }
    
    if ($quantity < 0) {
        $errors[] = 'Quantity cannot be negative.';
    }
    
    // If there are errors, redirect back with error messages
    if (!empty($errors)) {
        $_SESSION['error'] = implode(' ', $errors);
        header('Location: products.php');
        exit();
    }
    
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    try {
        // Prepare SQL statement for products table
        $sql = "INSERT INTO products (item_no, name, description, price, quantity, category, unit, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdisi", $item_no, $name, $description, $price, $quantity, $category, $unit);
        
        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Error adding product: ' . $conn->error);
        }
        
        $stmt->close();
        
        // Sync with canvas_items table
        $check_canvas = $conn->prepare("SELECT id FROM canvas_items WHERE item_no = ?");
        $check_canvas->bind_param("s", $item_no);
        $check_canvas->execute();
        $canvas_result = $check_canvas->get_result();
        
        if ($canvas_result->num_rows > 0) {
            // Update existing canvas_item with category and unit
            $canvas = $canvas_result->fetch_assoc();
            $update_canvas = $conn->prepare("UPDATE canvas_items SET category = ?, unit = ?, description = ? WHERE id = ?");
            $update_canvas->bind_param("sssi", $category, $unit, $description, $canvas['id']);
            $update_canvas->execute();
            $update_canvas->close();
        } else {
            // Create new canvas_item
            $insert_canvas = $conn->prepare("INSERT INTO canvas_items (item_no, description, category, unit) VALUES (?, ?, ?, ?)");
            $insert_canvas->bind_param("ssss", $item_no, $description, $category, $unit);
            $insert_canvas->execute();
            $insert_canvas->close();
        }
        
        $check_canvas->close();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = 'Product added successfully!';
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: products.php');
    exit();
}

/**
 * Edit an existing product
 */
function editProduct($conn) {
    // Get and sanitize form data
    $product_id = intval($_POST['product_id'] ?? 0);
    $name = sanitizeInput($conn, $_POST['name'] ?? '');
    $category = sanitizeInput($conn, $_POST['category'] ?? '');
    $description = sanitizeInput($conn, $_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    $unit = sanitizeInput($conn, $_POST['unit'] ?? 'pcs');
    
    // Parse item_no from name
    $item_no = '';
    if (strpos($name, ' - ') !== false) {
        $parts = explode(' - ', $name, 2);
        $item_no = trim($parts[0]);
        if (empty($description)) {
            $description = trim($parts[1]);
        }
    } else {
        $item_no = $name;
    }
    
    // Validate required fields
    $errors = [];
    
    if ($product_id <= 0) {
        $errors[] = 'Invalid product ID.';
    }
    
    if (empty($name)) {
        $errors[] = 'Product name is required.';
    }
    
    if (empty($category)) {
        $errors[] = 'Category is required.';
    }
    
    if ($price <= 0) {
        $errors[] = 'Price must be greater than zero.';
    }
    
    if ($quantity < 0) {
        $errors[] = 'Quantity cannot be negative.';
    }
    
    // If there are errors, redirect back with error messages
    if (!empty($errors)) {
        $_SESSION['error'] = implode(' ', $errors);
        header('Location: products.php');
        exit();
    }
    
    // Check if product exists
    $check_sql = "SELECT id, item_no FROM products WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $product_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: products.php');
        exit();
    }
    
    $existing_product = $check_result->fetch_assoc();
    $old_item_no = $existing_product['item_no'];
    $check_stmt->close();
    
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    try {
        // Prepare SQL statement for products table
        $sql = "UPDATE products 
                SET name = ?, description = ?, price = ?, quantity = ?, category = ?, unit = ?, item_no = ?, updated_at = NOW() 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdissi", $name, $description, $price, $quantity, $category, $unit, $item_no, $product_id);
        
        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Error updating product: ' . $conn->error);
        }
        
        $stmt->close();
        
        // Sync with canvas_items table
        $check_canvas = $conn->prepare("SELECT id FROM canvas_items WHERE item_no = ?");
        $check_canvas->bind_param("s", $item_no);
        $check_canvas->execute();
        $canvas_result = $check_canvas->get_result();
        
        if ($canvas_result->num_rows > 0) {
            // Update existing canvas_item with category and unit
            $canvas = $canvas_result->fetch_assoc();
            $update_canvas = $conn->prepare("UPDATE canvas_items SET category = ?, unit = ?, description = ? WHERE id = ?");
            $update_canvas->bind_param("sssi", $category, $unit, $description, $canvas['id']);
            $update_canvas->execute();
            $update_canvas->close();
        } else {
            // Create new canvas_item if it doesn't exist
            $insert_canvas = $conn->prepare("INSERT INTO canvas_items (item_no, description, category, unit) VALUES (?, ?, ?, ?)");
            $insert_canvas->bind_param("ssss", $item_no, $description, $category, $unit);
            $insert_canvas->execute();
            $insert_canvas->close();
        }
        
        $check_canvas->close();
        
        // If item_no changed, also update any existing company_prices references
        if ($old_item_no !== $item_no) {
            // Find the canvas_item with the new item_no
            $find_new = $conn->prepare("SELECT id FROM canvas_items WHERE item_no = ?");
            $find_new->bind_param("s", $item_no);
            $find_new->execute();
            $new_canvas = $find_new->get_result()->fetch_assoc();
            $find_new->close();
            
            if ($new_canvas) {
                // Find the old canvas_item
                $find_old = $conn->prepare("SELECT id FROM canvas_items WHERE item_no = ?");
                $find_old->bind_param("s", $old_item_no);
                $find_old->execute();
                $old_canvas = $find_old->get_result()->fetch_assoc();
                $find_old->close();
                
                if ($old_canvas && $old_canvas['id'] != $new_canvas['id']) {
                    // Update company_prices to use the new canvas_item
                    $update_prices = $conn->prepare("UPDATE company_prices SET item_id = ? WHERE item_id = ?");
                    $update_prices->bind_param("ii", $new_canvas['id'], $old_canvas['id']);
                    $update_prices->execute();
                    $update_prices->close();
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = 'Product updated successfully!';
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: products.php');
    exit();
}

/**
 * Delete a product
 */
function deleteProduct($conn) {
    // Get product ID
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    // Validate product ID
    if ($product_id <= 0) {
        $_SESSION['error'] = 'Invalid product ID. Please try again.';
        header('Location: products.php');
        exit();
    }
    
    // Check if product exists and get its item_no
    $check_sql = "SELECT name, item_no FROM products WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $product_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['error'] = 'Product not found. It may have been already deleted.';
        header('Location: products.php');
        exit();
    }
    
    $product = $check_result->fetch_assoc();
    $product_name = $product['name'];
    $item_no = $product['item_no'];
    $check_stmt->close();
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if this item exists in company_prices (through canvas_items)
        $check_prices = $conn->prepare("
            SELECT cp.id 
            FROM company_prices cp
            INNER JOIN canvas_items ci ON cp.item_id = ci.id
            WHERE ci.item_no = ?
        ");
        $check_prices->bind_param("s", $item_no);
        $check_prices->execute();
        $prices_result = $check_prices->get_result();
        $has_prices = $prices_result->num_rows > 0;
        $check_prices->close();
        
        // Prepare SQL statement for deletion from products
        $sql = "DELETE FROM products WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $product_id);
        
        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Error deleting product: ' . $conn->error);
        }
        
        $stmt->close();
        
        // Only delete from canvas_items if no company prices exist
        if (!$has_prices) {
            $delete_canvas = $conn->prepare("DELETE FROM canvas_items WHERE item_no = ?");
            $delete_canvas->bind_param("s", $item_no);
            $delete_canvas->execute();
            $delete_canvas->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        if ($has_prices) {
            $_SESSION['success'] = 'Product "' . htmlspecialchars($product_name) . '" deleted successfully!';
        } else {
            $_SESSION['success'] = 'Product "' . htmlspecialchars($product_name) . '" deleted successfully!';
        }
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: products.php');
    exit();
}

/**
 * Helper function to sanitize input data
 */
function sanitizeInput($conn, $data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $conn->real_escape_string($data);
}

// Close database connection
$conn->close();
?>