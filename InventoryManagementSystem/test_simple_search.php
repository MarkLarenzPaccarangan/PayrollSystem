<?php
require_once 'config.php';
requireLogin();

echo "<h2>Simple Database Test</h2>";

// Test 1: Bilangin ang products
$count = $conn->query("SELECT COUNT(*) as total FROM products")->fetch_assoc()['total'];
echo "<p>Total products in database: <strong>$count</strong></p>";

// Test 2: Ipakita ang unang 5 products
$result = $conn->query("SELECT id, item_no, name, quantity FROM products LIMIT 5");
echo "<h3>First 5 Products:</h3>";
echo "<pre>";
while($row = $result->fetch_assoc()) {
    print_r($row);
}
echo "</pre>";

// Test 3: Mag-search ng '0123'
$term = '0123';
$search = "%$term%";
$stmt = $conn->prepare("SELECT id, item_no, name, quantity FROM products WHERE item_no LIKE ? OR name LIKE ?");
$stmt->bind_param("ss", $search, $search);
$stmt->execute();
$result = $stmt->get_result();

echo "<h3>Search for '$term':</h3>";
echo "<pre>";
while($row = $result->fetch_assoc()) {
    print_r($row);
}
echo "</pre>";

// Test 4: Check kung may error sa connection
if ($conn->error) {
    echo "<p style='color:red'>Database error: " . $conn->error . "</p>";
}
?>