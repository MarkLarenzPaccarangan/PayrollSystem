<?php
session_start();
include_once("connection.php");

echo "<h1>🔍 USER DATABASE CHECK</h1>";

// Check super_user table
echo "<h2>📋 SUPER_USER TABLE</h2>";
$result = $conn->query("SELECT id, username, name FROM super_user");
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Name</th></tr>";
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><strong>" . $row['username'] . "</strong></td>";
        echo "<td>" . $row['name'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ No admin users found!</p>";
}

// Check user_accounts table
echo "<h2>📋 USER_ACCOUNTS TABLE</h2>";
$result = $conn->query("SELECT id, username, full_name, role FROM user_accounts");
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><strong>" . $row['username'] . "</strong></td>";
        echo "<td>" . $row['full_name'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ No user accounts found!</p>";
}

// If no users found, offer to create test users
$super_count = $conn->query("SELECT COUNT(*) as count FROM super_user")->fetch_assoc()['count'];
$user_count = $conn->query("SELECT COUNT(*) as count FROM user_accounts")->fetch_assoc()['count'];

if ($super_count == 0 && $user_count == 0) {
    echo "<h2 style='color: orange;'>⚠️ No users found in database!</h2>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='create_test_users' style='padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer;'>Create Test Users</button>";
    echo "</form>";
}

// Create test users if requested
if (isset($_POST['create_test_users'])) {
    // Create admin
    $conn->query("INSERT INTO super_user (username, name, password, security_answer1, security_answer2, security_answer3) 
                  VALUES ('admin', 'Admin User', 'admin123', 'admin', 'admin', 'admin')");
    
    // Create CEO
    $conn->query("INSERT INTO user_accounts (username, password_hash, full_name, email, role, security_answer1, security_answer2, security_answer3) 
                  VALUES ('ceo', 'ceo123', 'CEO User', 'ceo@test.com', 'ceo', 'ceo', 'ceo', 'ceo')");
    
    // Create Regular User
    $conn->query("INSERT INTO user_accounts (username, password_hash, full_name, email, role, security_answer1, security_answer2, security_answer3) 
                  VALUES ('user', 'user123', 'Regular User', 'user@test.com', 'user', 'user', 'user', 'user')");
    
    echo "<p style='color: green;'>✅ Test users created! Refresh page.</p>";
    echo "<script>setTimeout(() => { window.location.href = 'check_users_debug.php'; }, 2000);</script>";
}

$conn->close();
?>

<br><br>
<a href="login.php" style="padding: 10px 20px; background: #2E7D32; color: white; text-decoration: none; border-radius: 5px;">Go to Login</a>