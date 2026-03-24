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
    
    // Prepare SQL statement
    $sql = "INSERT INTO products (name, category, description, price, quantity, unit, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssdis", $name, $category, $description, $price, $quantity, $unit);
    
    // Execute the statement
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Product added successfully!';
    } else {
        $_SESSION['error'] = 'Error adding product: ' . $conn->error;
    }
    
    $stmt->close();
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
    $check_sql = "SELECT id FROM products WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $product_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: products.php');
        exit();
    }
    $check_stmt->close();
    
    // Prepare SQL statement
    $sql = "UPDATE products 
            SET name = ?, category = ?, description = ?, price = ?, quantity = ?, unit = ?, updated_at = NOW() 
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssdisi", $name, $category, $description, $price, $quantity, $unit, $product_id);
    
    // Execute the statement
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Product updated successfully!';
    } else {
        $_SESSION['error'] = 'Error updating product: ' . $conn->error;
    }
    
    $stmt->close();
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
    
    // Check if product exists
    $check_sql = "SELECT name FROM products WHERE id = ?";
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
    $check_stmt->close();
    
    // Prepare SQL statement for deletion
    $sql = "DELETE FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    
    // Execute the statement
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Product "' . htmlspecialchars($product_name) . '" deleted successfully!';
    } else {
        $_SESSION['error'] = 'Error deleting product: ' . $conn->error;
    }
    
    $stmt->close();
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