<?php
session_start();
include_once("connection.php");

if (!isset($_SESSION['Admin_User'])) {
    die("Please login as admin first");
}

echo "<h2>Complete Database Fix for Sites Table</h2>";

// Drop and recreate sites table
echo "<h3>Recreating sites table...</h3>";

$drop_table = "DROP TABLE IF EXISTS sites";
if ($conn->query($drop_table)) {
    echo "✅ Dropped existing sites table<br>";
}

$create_table = "CREATE TABLE sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(100) NOT NULL,
    site_code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($create_table)) {
    echo "✅ Sites table created successfully<br>";
} else {
    echo "❌ Error creating sites table: " . $conn->error . "<br>";
    exit;
}

// Insert default sites
$insert_sites = "INSERT INTO sites (site_name, site_code, description) VALUES
    ('Main Office', 'MO', 'Main company office location'),
    ('Site A - North', 'SITE_A', 'North construction site'),
    ('Site B - South', 'SITE_B', 'South construction site'),
    ('Site C - East', 'SITE_C', 'East warehouse location'),
    ('Site D - West', 'SITE_D', 'West branch office'),
    ('Field Work', 'FIELD', 'Field assignment - offsite'),
    ('Remote', 'REMOTE', 'Work from home / remote'),
    ('Client Site', 'CLIENT', 'Client location')";

if ($conn->query($insert_sites)) {
    echo "✅ Default sites inserted successfully<br>";
} else {
    echo "❌ Error inserting sites: " . $conn->error . "<br>";
}

// Add site columns to attendance table if not exists
echo "<h3>Adding site columns to attendance table...</h3>";

$columns_to_add = ['am_site_id', 'pm_site_id', 'night_site_id'];

foreach ($columns_to_add as $column) {
    $check_column = "SHOW COLUMNS FROM attendance LIKE '$column'";
    $check_result = $conn->query($check_column);
    
    if ($check_result->num_rows == 0) {
        $add_column = "ALTER TABLE attendance ADD COLUMN $column INT NULL DEFAULT NULL";
        if ($conn->query($add_column)) {
            echo "✅ Added $column column<br>";
        } else {
            echo "❌ Error adding $column: " . $conn->error . "<br>";
        }
    } else {
        echo "✅ $column column already exists<br>";
    }
}

// Display results
echo "<hr>";
echo "<h3>Current Sites in Database:</h3>";
$display_sites = "SELECT id, site_name, site_code, description FROM sites";
$sites_result = $conn->query($display_sites);

if ($sites_result && $sites_result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr style='background: #4CAF50; color: white;'><th>ID</th><th>Site Name</th><th>Site Code</th><th>Description</th></tr>";
    while($site = $sites_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $site['id'] . "</td>";
        echo "<td>" . htmlspecialchars($site['site_name']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($site['site_code']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($site['description'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<p style='color: green; font-weight: bold;'>✅ Database fix completed successfully!</p>";
echo "<a href='attendance.php' style='display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px;'>Go to Attendance Page</a>";

$conn->close();
?>