<?php
// repair_march06_final.php
require_once 'config.php';

$conn = new mysqli('localhost', 'root', '', 'inventory_system');
if ($conn->connect_error) die("Connection failed");

echo "<h2>🔧 Repairing March 06, 2026 Purchases</h2>";

$conn->begin_transaction();

try {
    // Step 1: Kunin lahat ng unique items
    $items = $conn->query("
        SELECT DISTINCT item_no, description, price_per_unit, purchase_date, quantity_purchased
        FROM purchases 
        WHERE DATE(purchase_date) = '2026-03-06'
        AND status = 'completed'
        AND (product_id IS NULL OR product_id = 0)
    ");
    
    $product_count = 0;
    while ($item = $items->fetch_assoc()) {
        // Check if product exists
        $check = $conn->prepare("SELECT id FROM products WHERE item_no = ?");
        $check->bind_param("s", $item['item_no']);
        $check->execute();
        $exists = $check->get_result();
        
        if ($exists->num_rows == 0) {
            $name = $item['item_no'] . ' - ' . $item['description'];
            $insert = $conn->prepare("
                INSERT INTO products (name, item_no, description, quantity, unit, price, low_stock_threshold, created_at) 
                VALUES (?, ?, ?, ?, 'pcs', ?, 10, ?)
            ");
            $insert->bind_param("sssids", $name, $item['item_no'], $item['description'], 
                               $item['quantity_purchased'], $item['price_per_unit'], $item['purchase_date']);
            $insert->execute();
            $product_count++;
        }
    }
    echo "<p>✓ Created $product_count products</p>";
    
    // Step 2: Update purchases with product_id
    $conn->query("
        UPDATE purchases p
        JOIN products pr ON pr.item_no = p.item_no 
        SET p.product_id = pr.id
        WHERE DATE(p.purchase_date) = '2026-03-06'
        AND p.status = 'completed'
        AND p.product_id IS NULL
    ");
    echo "<p>✓ Updated purchases with product IDs</p>";
    
    // Step 3: Insert stock movements
    $movements = $conn->query("
        SELECT p.*, pr.id as prod_id
        FROM purchases p
        JOIN products pr ON pr.item_no = p.item_no
        WHERE DATE(p.purchase_date) = '2026-03-06'
        AND p.status = 'completed'
        AND (p.stock_movement_id IS NULL OR p.stock_movement_id = 0)
    ");
    
    $movement_count = 0;
    while ($pur = $movements->fetch_assoc()) {
        $ref = "PURCHASE #" . $pur['purchase_number'];
        $notes = "Purchased from {$pur['company_name']} - {$pur['quantity_purchased']} pcs of {$pur['description']}";
        
        $insert = $conn->prepare("
            INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by, created_at) 
            VALUES (?, 'in', ?, ?, ?, 3, ?)
        ");
        $insert->bind_param("iisss", $pur['prod_id'], $pur['quantity_purchased'], $ref, $notes, $pur['purchase_date']);
        $insert->execute();
        $movement_id = $conn->insert_id;
        
        $conn->query("UPDATE purchases SET stock_movement_id = $movement_id WHERE id = {$pur['id']}");
        $movement_count++;
    }
    echo "<p>✓ Created $movement_count stock movements</p>";
    
    $conn->commit();
    echo "<p style='color:green;'>✅ REPAIR COMPLETED!</p>";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='stock_tracker.php?date=2026-03-06'>👉 Go to Stock Tracker</a></p>";