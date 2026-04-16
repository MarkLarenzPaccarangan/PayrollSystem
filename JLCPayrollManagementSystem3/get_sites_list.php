<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once("connection.php");

$sites = [];

// Check if site_monitoring table exists
$table_check = $conn->query("SHOW TABLES LIKE 'site_monitoring'");
if ($table_check && $table_check->num_rows > 0) {
    // Get ALL sites from site_monitoring table
    $site_sql = "SELECT id, site_name, site_address, is_others 
                 FROM site_monitoring 
                 ORDER BY is_others ASC, site_name ASC";
    $site_result = $conn->query($site_sql);
    
    if ($site_result && $site_result->num_rows > 0) {
        while($site = $site_result->fetch_assoc()) {
            $display_name = $site['site_name'];
            
            // For others/assignments, add a prefix to distinguish them
            if ($site['is_others'] == 1) {
                $display_name = "[Assignment] " . $display_name;
            }
            
            $sites[] = [
                'id' => $site['id'],
                'site_name' => $display_name,
                'site_code' => 'SITE_' . $site['id'],
                'description' => $site['site_address'] ?? ''
            ];
        }
    }
}

// Also check if old sites table exists as fallback
$old_table_check = $conn->query("SHOW TABLES LIKE 'sites'");
if ($old_table_check && $old_table_check->num_rows > 0) {
    $old_site_sql = "SELECT id, site_name, site_code, description FROM sites WHERE is_active = 1 ORDER BY site_name";
    $old_site_result = $conn->query($old_site_sql);
    if ($old_site_result && $old_site_result->num_rows > 0) {
        while($site = $old_site_result->fetch_assoc()) {
            // Check if not already added
            $exists = false;
            foreach ($sites as $existing) {
                if ($existing['site_name'] == $site['site_name'] || 
                    $existing['site_name'] == "[Assignment] " . $site['site_name']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $sites[] = [
                    'id' => $site['id'],
                    'site_name' => $site['site_name'],
                    'site_code' => $site['site_code'],
                    'description' => $site['description'] ?? ''
                ];
            }
        }
    }
}

echo json_encode(['success' => true, 'sites' => $sites]);
?>