<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    
    // Get order details
    $order_query = "SELECT * FROM orders WHERE id = ?";
    $stmt = $conn->prepare($order_query);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order_result = $stmt->get_result();
    
    if ($order_result->num_rows > 0) {
        $order = $order_result->fetch_assoc();
        
        // Get order items
        $items_query = "SELECT oi.*, p.name as product_name 
                        FROM order_items oi 
                        LEFT JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?";
        $stmt = $conn->prepare($items_query);
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $items_result = $stmt->get_result();
        
        $items = [];
        while ($item = $items_result->fetch_assoc()) {
            $items[] = $item;
        }
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No order ID provided']);
}
?>