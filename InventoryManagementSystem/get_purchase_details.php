<?php
// get_purchase_details.php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Purchase ID required']);
    exit();
}

$purchase_id = intval($_GET['id']);

// Get purchase details
$purchase_sql = "SELECT * FROM purchases WHERE id = ?";
$stmt = $conn->prepare($purchase_sql);
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase_result = $stmt->get_result();

if ($purchase_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Purchase not found']);
    exit();
}

$purchase = $purchase_result->fetch_assoc();

// Get purchase items
$items_sql = "SELECT * FROM purchase_items WHERE purchase_id = ?";
$stmt = $conn->prepare($items_sql);
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$items_result = $stmt->get_result();

$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

echo json_encode([
    'success' => true,
    'purchase' => $purchase,
    'items' => $items
]);
?>