<?php
session_start();
include 'connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$site_id = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($site_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit;
}

try {
    // Check if site_appointments table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'site_appointments'");
    if (!$table_check || $table_check->num_rows === 0) {
        echo json_encode([
            'success' => true,
            'appointments' => [],
            'count' => 0
        ]);
        exit;
    }
    
    $query = "SELECT * FROM site_appointments WHERE site_id = ? ORDER BY appointment_date DESC, appointment_time DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'count' => count($appointments)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>