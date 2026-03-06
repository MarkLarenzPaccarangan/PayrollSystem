<?php
require_once 'config.php';

header('Content-Type: application/json');

$stats = [];

// Total products
$result = $conn->query("SELECT COUNT(*) as count FROM products");
$stats['totalProducts'] = $result->fetch_assoc()['count'];

// Total value
$result = $conn->query("SELECT SUM(price * quantity) as total FROM products");
$stats['totalValue'] = number_format($result->fetch_assoc()['total'] ?? 0, 2);

// Low stock items
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10");
$stats['lowStock'] = $result->fetch_assoc()['count'];

// Total quantity
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

echo json_encode($stats);

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>