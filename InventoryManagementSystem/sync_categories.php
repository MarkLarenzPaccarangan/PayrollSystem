<?php
// sync_categories.php
require_once 'config.php';
requireLogin();

// I-enable ang error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Category Sync</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1c3c; color: #fff; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #242858; padding: 30px; border-radius: 12px; }
        h2 { color: #75e6da; margin-bottom: 20px; }
        p { padding: 10px; background: #2f3366; border-radius: 8px; margin: 10px 0; }
        .success { color: #00b894; }
        a { display: inline-block; margin-top: 20px; padding: 10px 20px; background: linear-gradient(135deg, #75e6da, #6c5ce7); color: white; text-decoration: none; border-radius: 8px; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>🔄 Category Synchronization</h2>";

// 1. Kunin ang categories mula sa purchases
echo "<p>📦 Syncing from purchases...</p>";
$purchases_sql = "SELECT DISTINCT item_no, category FROM purchases WHERE category IS NOT NULL AND category != ''";
$purchases_result = $conn->query($purchases_sql);
$purchases_count = 0;

if ($purchases_result && $purchases_result->num_rows > 0) {
    while ($row = $purchases_result->fetch_assoc()) {
        $update_sql = "UPDATE products SET category = '{$conn->real_escape_string($row['category'])}' 
                       WHERE item_no = '{$conn->real_escape_string($row['item_no'])}' 
                       AND (category IS NULL OR category = '' OR category != '{$conn->real_escape_string($row['category'])}')";
        if ($conn->query($update_sql)) {
            $purchases_count += $conn->affected_rows;
        }
    }
    echo "<p class='success'>✅ Updated $purchases_count products from purchases</p>";
} else {
    echo "<p>⚠️ No categories found in purchases</p>";
}

// 2. Kunin ang categories mula sa canvas_items
echo "<p>📦 Syncing from canvas_items...</p>";
$canvas_sql = "SELECT DISTINCT item_no, category FROM canvas_items WHERE category IS NOT NULL AND category != ''";
$canvas_result = $conn->query($canvas_sql);
$canvas_count = 0;

if ($canvas_result && $canvas_result->num_rows > 0) {
    while ($row = $canvas_result->fetch_assoc()) {
        $update_sql = "UPDATE products SET category = '{$conn->real_escape_string($row['category'])}' 
                       WHERE item_no = '{$conn->real_escape_string($row['item_no'])}' 
                       AND (category IS NULL OR category = '' OR category != '{$conn->real_escape_string($row['category'])}')";
        if ($conn->query($update_sql)) {
            $canvas_count += $conn->affected_rows;
        }
    }
    echo "<p class='success'>✅ Updated $canvas_count products from canvas_items</p>";
} else {
    echo "<p>⚠️ No categories found in canvas_items</p>";
}

// 3. I-set sa 'Uncategorized' ang mga wala pa ring category
echo "<p>🏷️ Setting uncategorized products...</p>";
$default_sql = "UPDATE products SET category = 'Uncategorized' WHERE category IS NULL OR category = ''";
$conn->query($default_sql);
$default_count = $conn->affected_rows;
echo "<p class='success'>✅ Set $default_count products to 'Uncategorized'</p>";

// 4. Ipakita ang summary
echo "<h3>📊 Sync Summary</h3>";
echo "<p>Total products in database: " . $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] . "</p>";
echo "<p>Products with categories: " . $conn->query("SELECT COUNT(*) as count FROM products WHERE category IS NOT NULL AND category != ''")->fetch_assoc()['count'] . "</p>";

echo "<p style='margin-top: 30px;'><strong>✅ Sync Complete!</strong></p>";
echo "<a href='stock_tracker.php'>🚀 Go to Stock Tracker</a>";
echo "</div></body></html>";
?>