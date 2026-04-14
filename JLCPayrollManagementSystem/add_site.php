<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

include_once("connection.php");

$site_name = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
$address = isset($_POST['address']) ? trim($_POST['address']) : '';

if (empty($site_name)) {
    echo json_encode(['success' => false, 'message' => 'Site name is required']);
    exit;
}

// Check if site already exists
$check_sql = "SELECT id FROM sites WHERE site_name = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("s", $site_name);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Site already exists']);
    $check_stmt->close();
    $conn->close();
    exit;
}
$check_stmt->close();

// Insert new site
$insert_sql = "INSERT INTO sites (site_name, address, created_at) VALUES (?, ?, NOW())";
$insert_stmt = $conn->prepare($insert_sql);
$insert_stmt->bind_param("ss", $site_name, $address);

if ($insert_stmt->execute()) {
    $new_id = $insert_stmt->insert_id;
    echo json_encode([
        'success' => true, 
        'message' => 'Site added successfully',
        'site_id' => $new_id,
        'site_name' => $site_name,
        'address' => $address
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $insert_stmt->error]);
}

$insert_stmt->close();
$conn->close();
?>