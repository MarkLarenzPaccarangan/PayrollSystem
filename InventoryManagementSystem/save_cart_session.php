<?php
// save_cart_session.php - Save cart to session for purchase.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid cart data']);
    exit();
}

// Save cart to session
$_SESSION['purchase_cart'] = $input['cart'];

echo json_encode(['success' => true]);
?>