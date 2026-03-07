<?php
// Start output buffering
ob_start();

require_once 'config.php';

// Require login
requireLogin();

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

$startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $data['end_date'] ?? date('Y-m-d');

// Validate dates
if ($startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// Get stock levels by date range
$stockByDate = $conn->query("
    SELECT 
        DATE(created_at) as date,
        SUM(price * quantity) as total_value
    FROM products 
    WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");

// Prepare arrays for chart
$dates = [];
$values = [];

// Calculate date range
$dateRange = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);

foreach ($dateRange as $date) {
    $dateStr = $date->format('Y-m-d');
    $dates[] = $date->format('M d');
    $values[$dateStr] = 0;
}

// Fill with actual data
while($row = $stockByDate->fetch_assoc()) {
    $date = $row['date'];
    if (isset($values[$date])) {
        $values[$date] = (float)$row['total_value'];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'dates' => array_values($dates),
    'values' => array_values($values)
]);

ob_end_flush();
?>