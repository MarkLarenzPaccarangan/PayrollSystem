<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['movement_id'])) {
    $movement_id = intval($_POST['movement_id']);
    
    // Get the stock movement details
    $get_sql = "SELECT product_id, quantity FROM stock_movements WHERE id = ? AND type = 'out'";
    $stmt = $conn->prepare($get_sql);
    $stmt->bind_param("i", $movement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $movement = $result->fetch_assoc();
    
    if ($movement) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Add back the stock to product
            $update_sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $movement['quantity'], $movement['product_id']);
            $stmt->execute();
            
            // Delete the stock movement
            $delete_sql = "DELETE FROM stock_movements WHERE id = ?";
            $stmt = $conn->prepare($delete_sql);
            $stmt->bind_param("i", $movement_id);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['toast'] = ['message' => 'Pull out record deleted successfully! Stock quantity restored.', 'type' => 'success'];
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['toast'] = ['message' => 'Error deleting record: ' . $e->getMessage(), 'type' => 'error'];
        }
    } else {
        $_SESSION['toast'] = ['message' => 'Record not found.', 'type' => 'error'];
    }
    
    header("Location: site.php");
    exit();
}

header("Location: site.php");
exit();
?>