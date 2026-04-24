<?php
// process_deduction.php - FIXED: Calculate actual site quantity from database
error_reporting(0);
ini_set('display_errors', 0);

if (ob_get_level()) ob_end_clean();
ob_start();

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/config.php';

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }

    $user_id = $_SESSION['user_id'];
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }

    // Ensure type enum has 'deduct' value
    $conn->query("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'deduct') NOT NULL");

    $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
    $site_name = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
    $items_json = isset($_POST['items_json']) ? $_POST['items_json'] : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    if ($site_id <= 0) throw new Exception('Invalid site ID');
    if (empty($site_name)) throw new Exception('Site name is required');
    if (empty($items_json)) throw new Exception('No items to deduct');

    $items = json_decode($items_json, true);
    if (!$items || !is_array($items)) throw new Exception('Invalid items data format');

    $valid_items = [];
    foreach ($items as $item) {
        if (isset($item['deduct_qty']) && intval($item['deduct_qty']) > 0) {
            $valid_items[] = $item;
        }
    }

    if (empty($valid_items)) throw new Exception('No valid deduction quantities entered');

    $conn->begin_transaction();

    $reference = 'DED-' . date('YmdHis') . '-' . rand(100, 999);
    $success_count = 0;
    $errors = [];
    $total_deducted = 0;

    foreach ($valid_items as $item) {
        $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
        $deduct_qty = isset($item['deduct_qty']) ? intval($item['deduct_qty']) : 0;

        if ($deduct_qty <= 0) continue;

        // ================================================
        // FIXED: Calculate ACTUAL current site quantity from database
        // ================================================
        $actual_qty_sql = "
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN type = 'deduct' THEN quantity ELSE 0 END), 0) as net_quantity
            FROM stock_movements 
            WHERE product_id = ? 
            AND site_location = ? 
            AND (status = 'active' OR status IS NULL OR status = '')
        ";
        
        $qty_stmt = $conn->prepare($actual_qty_sql);
        $qty_stmt->bind_param("is", $product_id, $site_name);
        $qty_stmt->execute();
        $qty_result = $qty_stmt->get_result();
        $qty_row = $qty_result->fetch_assoc();
        $current_site_qty = intval($qty_row['net_quantity'] ?? 0);
        $qty_stmt->close();

        // Validate deduction quantity against actual available quantity
        if ($deduct_qty > $current_site_qty) {
            $errors[] = "Cannot deduct $deduct_qty from product ID $product_id. Only $current_site_qty available at site.";
            continue;
        }

        // Get product details
        $product_stmt = $conn->prepare("SELECT id, name, item_no, unit FROM products WHERE id = ?");
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product = $product_stmt->get_result()->fetch_assoc();
        $product_stmt->close();

        if (!$product) {
            $errors[] = "Product ID $product_id not found";
            continue;
        }

        $product_name = preg_replace('/^\d+\s*-\s*/', '', $product['name']);
        $item_no = $product['item_no'] ?: 'N/A';
        $unit = $product['unit'] ?? 'pcs';

        // Insert deduction movement
        $deduct_stmt = $conn->prepare("
            INSERT INTO stock_movements 
            (product_id, type, quantity, reference, notes, created_at, created_by, site_location, status) 
            VALUES (?, 'deduct', ?, ?, ?, NOW(), ?, ?, 'active')
        ");
        
        $deduct_notes = "DEDUCTION: $deduct_qty $unit permanently removed from site '$site_name'.";
        $deduct_stmt->bind_param("iissss", $product_id, $deduct_qty, $reference, $deduct_notes, $user_id, $site_name);
        $deduct_stmt->execute();
        $deduct_stmt->close();

        // Calculate new quantity after deduction
        $new_site_qty = $current_site_qty - $deduct_qty;
        
        // Record deduction in history
        $history_stmt = $conn->prepare("
            INSERT INTO deduction_history 
            (site_id, site_name, product_id, product_name, item_no, 
             quantity_deducted, previous_quantity, new_quantity, 
             reference, notes, remarks, deducted_by, deducted_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $history_notes = "DEDUCTION: Removed $deduct_qty $unit from site. Items are PERMANENTLY REMOVED.";
        $history_ref = $reference . '-' . $product_id;
        
        $history_stmt->bind_param(
            "isissiissssi",
            $site_id, $site_name, $product_id, $product_name, $item_no,
            $deduct_qty, $current_site_qty, $new_site_qty,
            $history_ref, $history_notes, $remarks, $user_id
        );
        
        $history_stmt->execute();
        $history_stmt->close();

        $success_count++;
        $total_deducted += $deduct_qty;
    }

    if ($success_count == 0) {
        $conn->rollback();
        throw new Exception('No items were deducted: ' . implode(', ', $errors));
    }

    $conn->commit();

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Successfully deducted {$total_deducted} item(s) from {$site_name}.",
        'details' => [
            'site_name' => $site_name,
            'total_deducted' => $total_deducted,
            'reference' => $reference
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

if (isset($conn) && $conn) {
    $conn->close();
}

ob_end_flush();
exit();
?>