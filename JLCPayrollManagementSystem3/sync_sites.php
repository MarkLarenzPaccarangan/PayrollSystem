<?php
session_start();
include_once("connection.php");

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// Sync sites from site_monitoring to sites table
$monitoring_sites = $conn->query("SELECT id, site_name, site_address FROM site_monitoring WHERE is_others = 0");

if ($monitoring_sites && $monitoring_sites->num_rows > 0) {
    while ($site = $monitoring_sites->fetch_assoc()) {
        // Check if site already exists in sites table
        $check = $conn->prepare("SELECT id FROM sites WHERE site_name = ?");
        $check->bind_param("s", $site['site_name']);
        $check->execute();
        $check_result = $check->get_result();
        
        if ($check_result->num_rows == 0) {
            // Insert into sites table
            $insert = $conn->prepare("INSERT INTO sites (site_name, site_code, description, is_active) VALUES (?, ?, ?, 1)");
            $site_code = 'SITE_' . $site['id'];
            $insert->bind_param("sss", $site['site_name'], $site_code, $site['site_address']);
            $insert->execute();
        }
        $check->close();
    }
}

header("Location: attendance.php");
exit;
?>