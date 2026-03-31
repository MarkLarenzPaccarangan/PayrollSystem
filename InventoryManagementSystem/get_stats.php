<?php
require_once 'config.php';

header('Content-Type: application/json');

// Function to convert datetime to human readable time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    // Extract weeks and remaining days without adding a property to DateInterval
    $weeks = floor($diff->d / 7);
    $days = $diff->d % 7;
    
    // Create an array with all time units
    $time_units = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $weeks,
        'd' => $days,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];
    
    $labels = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    $string = [];
    foreach ($labels as $unit => $label) {
        if ($time_units[$unit] > 0) {
            $string[$unit] = $time_units[$unit] . ' ' . $label . ($time_units[$unit] > 1 ? 's' : '');
        }
    }
    
    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return !empty($string) ? implode(', ', $string) . ' ago' : 'just now';
}

$stats = [];

// Total products
$result = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['totalProducts'] = $result->fetch_assoc()['count'];

// Total value (price * quantity)
$result = $conn->query("SELECT SUM(price * quantity) as total FROM products");
$stats['totalValue'] = number_format($result->fetch_assoc()['total'] ?? 0, 2);

// Low stock items (quantity less than 10)
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10");
$stats['lowStock'] = $result->fetch_assoc()['count'];

// Total quantity of all products
$result = $conn->query("SELECT SUM(quantity) as total FROM products");
$stats['totalQuantity'] = $result->fetch_assoc()['total'] ?? 0;

// Category distribution
$result = $conn->query("SELECT category, COUNT(*) as count FROM products GROUP BY category");
$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[$row['category']] = $row['count'];
}
$stats['categories'] = $categories;

// Recent activity (last 5 products)
$result = $conn->query("SELECT name, created_at FROM products ORDER BY created_at DESC LIMIT 5");
$recent = [];
while ($row = $result->fetch_assoc()) {
    $recent[] = [
        'name' => $row['name'],
        'time' => time_elapsed_string($row['created_at'])
    ];
}
$stats['recentActivity'] = $recent;

// Return JSON response
echo json_encode($stats);
?>