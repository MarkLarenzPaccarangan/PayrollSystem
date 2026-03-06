<?php
require_once 'config.php';

echo "Orders table structure:<br><br>";

$result = $conn->query("DESCRIBE orders");
if ($result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Error: " . $conn->error;
}

echo "<br><br>Sample order data:<br><br>";

$result = $conn->query("SELECT * FROM orders LIMIT 1");
if ($result && $result->num_rows > 0) {
    $order = $result->fetch_assoc();
    echo "<pre>";
    print_r($order);
    echo "</pre>";
} else {
    echo "No orders found or error: " . $conn->error;
}
?>