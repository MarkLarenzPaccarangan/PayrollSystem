<?php
// get_all_comparison.php - API for getting all comparison data
require_once 'config.php';

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'inventory_system';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// Get all companies with colors and contact person
$companies = $conn->query("SELECT * FROM companies WHERE status = 'active' ORDER BY name");
$companies_array = [];
$company_colors = [];
if ($companies && $companies->num_rows > 0) {
    $colors = ['#4e73df', '#1cc88a', '#f6c23e', '#e74a3b', '#36b9cc', '#6f42c1', '#fd7e14', '#20c9a6'];
    $i = 0;
    while($comp = $companies->fetch_assoc()) {
        $companies_array[$comp['id']] = $comp;
        $company_colors[$comp['id']] = $colors[$i % count($colors)];
        $i++;
    }
}

// Get all items with prices
$items_query = $conn->query("
    SELECT ci.*, 
           GROUP_CONCAT(CONCAT(cp.company_id, ':', cp.quantity, ':', cp.price, ':', cp.availability) SEPARATOR '|') as prices
    FROM canvas_items ci
    LEFT JOIN company_prices cp ON ci.id = cp.item_id
    GROUP BY ci.id
    ORDER BY ci.item_no
");

$result = [];
while($item = $items_query->fetch_assoc()) {
    $item_prices = [];
    if ($item['prices']) {
        $price_parts = explode('|', $item['prices']);
        foreach ($price_parts as $part) {
            if (!empty($part)) {
                list($company_id, $quantity, $price, $availability) = explode(':', $part);
                if (isset($companies_array[$company_id])) {
                    $item_prices[] = [
                        'company_id' => $company_id,
                        'company_name' => $companies_array[$company_id]['name'],
                        'company_color' => $company_colors[$company_id],
                        'contact_person' => $companies_array[$company_id]['contact_person'] ?? '', // NEW
                        'quantity' => intval($quantity),
                        'price' => floatval($price),
                        'availability' => intval($availability)
                    ];
                }
            }
        }
    }
    
    if (!empty($item_prices)) {
        $result[] = [
            'item_no' => $item['item_no'],
            'description' => $item['description'],
            'prices' => $item_prices
        ];
    }
}

echo json_encode($result);
$conn->close();
?>