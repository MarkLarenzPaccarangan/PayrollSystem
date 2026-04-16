<?php
include_once("connection.php");

echo "<h1>🔍 DATABASE CHECK</h1>";

// Check super_user table
echo "<h2>📋 SUPER_USER TABLE</h2>";
$result = $conn->query("SELECT id, username, name FROM super_user");
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Username</th><th>Name</th></tr>";
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><strong>" . $row['username'] . "</strong> (length: " . strlen($row['username']) . ")</td>";
        echo "<td>" . $row['name'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>❌ No records found</p>";
}

// Check user_accounts table
echo "<h2>📋 USER_ACCOUNTS TABLE</h2>";
$result = $conn->query("SELECT id, username, full_name, role FROM user_accounts");
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><strong>" . $row['username'] . "</strong> (length: " . strlen($row['username']) . ")</td>";
        echo "<td>" . $row['full_name'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>❌ No records found</p>";
}

$conn->close();
echo "<br><br><a href='login.php'>Go to Login</a>";
?>