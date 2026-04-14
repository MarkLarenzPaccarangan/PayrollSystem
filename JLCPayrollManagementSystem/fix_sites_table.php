<?php
session_start();
include_once("connection.php");

if (!isset($_SESSION['Admin_User'])) {
    die("Please login as admin first");
}

echo "<h2>Fixing Sites Table</h2>";

// Check if site_code column exists
$check_column = "SHOW COLUMNS FROM sites LIKE 'site_code'";
$result = $conn->query($check_column);

if ($result->num_rows == 0) {
    echo "Adding site_code column...<br>";
    $add_column = "ALTER TABLE sites ADD COLUMN site_code VARCHAR(20) NOT NULL UNIQUE AFTER site_name";
    if ($conn->query($add_column)) {
        echo "✅ site_code column added<br>";
    } else {
        echo "❌ Error adding site_code: " . $conn->error . "<br>";
    }
} else {
    echo "✅ site_code column already exists<br>";
}

// Check if sites exist
$check_sites = "SELECT COUNT(*) as count FROM sites";
$site_result = $conn->query($check_sites);
$site_count = $site_result->fetch_assoc();

if ($site_count['count'] == 0) {
    echo "Inserting default sites...<br>";
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
        echo "✅ Default sites added<br>";
    } else {
        echo "❌ Error adding sites: " . $conn->error . "<br>";
    }
} else {
    echo "✅ Sites already exist (" . $site_count['count'] . " records)<br>";
    
    // Update any existing sites that might be missing site_code
    $update_sites = "UPDATE sites SET site_code = 
        CASE 
            WHEN site_name = 'Main Office' THEN 'MO'
            WHEN site_name = 'Site A - North' THEN 'SITE_A'
            WHEN site_name = 'Site B - South' THEN 'SITE_B'
            WHEN site_name = 'Site C - East' THEN 'SITE_C'
            WHEN site_name = 'Site D - West' THEN 'SITE_D'
            WHEN site_name = 'Field Work' THEN 'FIELD'
            WHEN site_name = 'Remote' THEN 'REMOTE'
            WHEN site_name = 'Client Site' THEN 'CLIENT'
            ELSE site_code
        END
        WHERE site_code IS NULL OR site_code = ''";
    
    if ($conn->query($update_sites)) {
        echo "✅ Updated existing sites with site_code<br>";
    }
}

echo "<hr>";
echo "<h3>Current Sites in Database:</h3>";
$display_sites = "SELECT id, site_name, site_code, description FROM sites";
$sites_result = $conn->query($display_sites);

if ($sites_result && $sites_result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Site Name</th><th>Site Code</th><th>Description</th></tr>";
    while($site = $sites_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $site['id'] . "</td>";
        echo "<td>" . htmlspecialchars($site['site_name']) . "</td>";
        echo "<td>" . htmlspecialchars($site['site_code']) . "</td>";
        echo "<td>" . htmlspecialchars($site['description'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No sites found in database.<br>";
}

echo "<hr>";
echo "<a href='attendance.php'>Go back to Attendance Page</a>";

$conn->close();
?>