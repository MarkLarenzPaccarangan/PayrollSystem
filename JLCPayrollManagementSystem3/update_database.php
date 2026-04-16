<?php
include_once("connection.php");

echo "<h2>🔄 Updating Database for Forgot Password</h2>";

// 1. Add security questions to super_user table
echo "<h3>Checking super_user table...</h3>";
$columns = ['security_answer1', 'security_answer2', 'security_answer3'];
foreach($columns as $column) {
    $check = $conn->query("SHOW COLUMNS FROM super_user LIKE '$column'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE super_user ADD COLUMN $column VARCHAR(255) NULL";
        if ($conn->query($sql)) {
            echo "✅ Added $column to super_user table<br>";
        } else {
            echo "❌ Error adding $column: " . $conn->error . "<br>";
        }
    } else {
        echo "✅ $column already exists<br>";
    }
}

// 2. Add sample security answers for existing admin users (optional)
echo "<h3>Adding sample security answers for admin users...</h3>";
$update = $conn->query("UPDATE super_user SET 
    security_answer1 = 'admin',
    security_answer2 = 'admin',
    security_answer3 = 'admin'
    WHERE security_answer1 IS NULL");
echo "✅ Updated " . $conn->affected_rows . " admin users<br>";

// 3. Create password_resets table if not exists
echo "<h3>Checking password_resets table...</h3>";
$create_table = "CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_user_id (user_id)
)";
if ($conn->query($create_table)) {
    echo "✅ password_resets table ready<br>";
} else {
    echo "❌ Error: " . $conn->error . "<br>";
}

$conn->close();
echo "<br><br><a href='check_users.php'>Check Users</a> | <a href='login.php'>Go to Login</a>";
?>