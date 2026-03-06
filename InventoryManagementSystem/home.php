<?php
// Start output buffering
ob_start();

require_once 'config.php';

// Require login
requireLogin();

// Get statistics with more detailed queries
$totalProducts = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'] ?? 0;
$totalValue = $conn->query("SELECT SUM(price * quantity) as total FROM products")->fetch_assoc()['total'] ?? 0;
$totalQuantity = $conn->query("SELECT SUM(quantity) as total FROM products")->fetch_assoc()['total'] ?? 0;
$lowStock = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10")->fetch_assoc()['count'] ?? 0;

// Get category stock distribution (UPDATED: now shows total quantity per category)
$categoryStats = $conn->query("
    SELECT category, 
           COUNT(*) as count, 
           SUM(quantity) as total_quantity,
           SUM(price * quantity) as total_value
    FROM products 
    GROUP BY category 
    ORDER BY total_quantity DESC 
    LIMIT 5
");

// Get recent products
$recentProducts = $conn->query("
    SELECT * FROM products 
    ORDER BY created_at DESC 
    LIMIT 5
");

// Get top selling products (by quantity)
$topProducts = $conn->query("
    SELECT * FROM products 
    ORDER BY quantity DESC 
    LIMIT 5
");

// Get default date range (last 7 days) for initial load
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-7 days'));

// Get stock levels by date range for initial load
$stockByDate = $conn->query("
    SELECT 
        DATE(created_at) as date,
        SUM(quantity) as total_quantity
    FROM products 
    WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");

// Prepare arrays for initial chart
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
        $values[$date] = (float)$row['total_quantity'];
    }
}

// Calculate trends (simulated)
$lastMonthProducts = $totalProducts * 0.9;
$productsTrend = $totalProducts > 0 ? (($totalProducts - $lastMonthProducts) / $lastMonthProducts) * 100 : 0;

$current_user = getCurrentUser();

// Include header
require_once 'include/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css">
<style>
    /* Dashboard Specific Styles - Maintaining color palette */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 24px;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        animation: fadeInUp 0.5s ease;
        animation-fill-mode: both;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #75e6da, #6c5ce7, #e84393);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .stat-card:hover::before {
        opacity: 1;
    }

    .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stat-card:nth-child(2) { animation-delay: 0.15s; }
    .stat-card:nth-child(3) { animation-delay: 0.2s; }
    .stat-card:nth-child(4) { animation-delay: 0.25s; }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 40px rgba(117, 230, 218, 0.15);
        border-color: #75e6da;
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }

    .stat-icon-wrapper {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(117, 230, 218, 0.15), rgba(108, 92, 231, 0.15));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #75e6da;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-icon-wrapper::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .stat-card:hover .stat-icon-wrapper {
        transform: scale(1.1) rotate(5deg);
        background: linear-gradient(135deg, #75e6da, #6c5ce7);
        color: white;
    }

    .stat-card:hover .stat-icon-wrapper::after {
        opacity: 1;
        animation: shimmer 1.5s infinite;
    }

    @keyframes shimmer {
        0% { transform: translate(-30%, -30%) rotate(0deg); }
        100% { transform: translate(30%, 30%) rotate(20deg); }
    }

    .stat-badge-group {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 6px;
    }

    .stat-trend-badge {
        background: rgba(117, 230, 218, 0.1);
        border-radius: 30px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #75e6da;
        border: 1px solid rgba(117, 230, 218, 0.2);
        display: inline-flex;
        align-items: center;
        gap: 4px;
        backdrop-filter: blur(5px);
    }

    .stat-trend-badge i {
        font-size: 10px;
    }

    .stat-trend-badge.positive {
        color: #75e6da;
        background: rgba(117, 230, 218, 0.15);
    }

    .stat-trend-badge.negative {
        color: #d63031;
        background: rgba(214, 48, 49, 0.1);
        border-color: rgba(214, 48, 49, 0.2);
    }

    .stat-time-badge {
        font-size: 11px;
        color: var(--text-secondary);
        background: rgba(255, 255, 255, 0.05);
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .stat-time-badge i {
        font-size: 10px;
        color: #75e6da;
    }

    .stat-availability-badge {
        background: rgba(117, 230, 218, 0.15);
        border-radius: 30px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #75e6da;
        border: 1px solid rgba(117, 230, 218, 0.2);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .stat-availability-badge i {
        font-size: 10px;
    }

    .stat-urgency-badge {
        border-radius: 30px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .stat-urgency-badge.warning {
        background: rgba(243, 156, 18, 0.15);
        color: #f39c12;
        border: 1px solid rgba(243, 156, 18, 0.2);
        animation: pulse-warning 2s infinite;
    }

    @keyframes pulse-warning {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.8;
            transform: scale(1.05);
        }
    }

    .stat-urgency-badge.safe {
        background: rgba(117, 230, 218, 0.15);
        color: #75e6da;
        border: 1px solid rgba(117, 230, 218, 0.2);
    }

    .stat-content {
        margin: 8px 0 4px;
    }

    .stat-value-large {
        font-size: 42px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 8px;
        line-height: 1.1;
        letter-spacing: -1px;
    }

    .stat-value-large.text-warning {
        color: #f39c12;
    }

    .stat-label-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .stat-label {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-secondary);
        letter-spacing: 0.3px;
    }

    .stat-info-badge {
        position: relative;
        color: var(--text-secondary);
        cursor: help;
        font-size: 14px;
        transition: color 0.3s ease;
    }

    .stat-info-badge:hover {
        color: #75e6da;
    }

    .stat-info-tooltip {
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%) translateY(-8px);
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 11px;
        font-weight: normal;
        color: var(--text-secondary);
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        box-shadow: var(--shadow);
        z-index: 100;
        pointer-events: none;
    }

    .stat-info-badge:hover .stat-info-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(-13px);
    }

    .stat-info-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border-width: 5px;
        border-style: solid;
        border-color: var(--border-color) transparent transparent transparent;
    }

    .stat-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        padding-top: 16px;
        border-top: 1px solid var(--border-color);
    }

    .stat-footer-left {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--text-secondary);
    }

    .stat-footer-left i {
        color: #75e6da;
        font-size: 14px;
    }

    .stat-progress {
        width: 80px;
        height: 4px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        overflow: hidden;
    }

    .stat-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #75e6da, #6c5ce7);
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    .stat-distribution {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .stat-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        animation: pulse-dot 2s infinite;
    }

    @keyframes pulse-dot {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.5;
            transform: scale(1.2);
        }
    }

    .stat-distribution-text {
        font-size: 11px;
        color: var(--text-secondary);
    }

    .stat-availability {
        display: flex;
        align-items: center;
    }

    .stat-availability-high {
        font-size: 11px;
        color: #75e6da;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .stat-availability-high i {
        font-size: 6px;
        animation: pulse-dot 2s infinite;
    }

    .stat-action-link {
        color: #75e6da;
        text-decoration: none;
        font-size: 12px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        border-radius: 20px;
        background: rgba(117, 230, 218, 0.1);
        transition: all 0.3s ease;
    }

    .stat-action-link:hover {
        background: rgba(117, 230, 218, 0.2);
        gap: 8px;
        color: #75e6da;
    }

    .stat-allgood {
        font-size: 12px;
        color: #75e6da;
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(117, 230, 218, 0.1);
        padding: 4px 10px;
        border-radius: 20px;
    }

    .stat-allgood i {
        font-size: 12px;
    }

    .charts-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }

    .chart-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 20px;
        animation: fadeInUp 0.5s ease 0.3s both;
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .chart-header h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
    }

    .chart-header i {
        color: #75e6da;
        font-size: 20px;
    }

    .date-range-picker {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 4px;
    }

    .date-input {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 6px 12px;
        color: var(--text-primary);
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .date-input:hover {
        border-color: #75e6da;
        transform: translateY(-1px);
    }

    .date-input i {
        margin-right: 6px;
        color: #75e6da;
    }

    .apply-btn {
        background: linear-gradient(135deg, #75e6da, #6c5ce7);
        border: none;
        border-radius: 6px;
        padding: 6px 16px;
        color: white;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .apply-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(117, 230, 218, 0.3);
    }

    .apply-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    .chart-container {
        position: relative;
        height: 250px;
        width: 100%;
        margin-top: 10px;
    }

    .chart-loading {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(26, 28, 60, 0.7);
        backdrop-filter: blur(3px);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        z-index: 10;
    }

    .chart-loading i {
        color: #75e6da;
        font-size: 30px;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .insights-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }

    .insights-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 20px;
        animation: fadeInUp 0.5s ease 0.4s both;
    }

    .insights-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 16px;
    }

    .insights-header i {
        color: #75e6da;
        font-size: 20px;
    }

    .insights-header h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .product-list {
        list-style: none;
    }

    .product-list-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }

    .product-list-item:last-child {
        border-bottom: none;
    }

    .product-list-item:hover {
        transform: translateX(5px);
        background: rgba(117, 230, 218, 0.05);
        padding-left: 12px;
        border-radius: 8px;
    }

    .product-rank {
        width: 24px;
        height: 24px;
        border-radius: 8px;
        background: linear-gradient(135deg, #75e6da, #6c5ce7);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 600;
    }

    .product-info {
        flex: 1;
    }

    .product-name {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2px;
    }

    .product-meta {
        font-size: 12px;
        color: var(--text-secondary);
    }

    .product-value {
        font-weight: 600;
        color: #75e6da;
    }

    .product-stock {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .stock-high {
        background: rgba(117, 230, 218, 0.15);
        color: #75e6da;
    }

    .stock-medium {
        background: rgba(243, 156, 18, 0.15);
        color: #f39c12;
    }

    .stock-low {
        background: rgba(214, 48, 49, 0.15);
        color: #d63031;
    }

    .welcome-section {
        margin-bottom: 30px;
        background: linear-gradient(135deg, rgba(117, 230, 218, 0.1), rgba(108, 92, 231, 0.1));
        border-radius: 20px;
        padding: 30px;
        border: 1px solid var(--border-color);
        position: relative;
        overflow: hidden;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .welcome-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(117, 230, 218, 0.2), transparent 70%);
        border-radius: 50%;
        animation: pulse 4s infinite;
    }

    .welcome-section::after {
        content: '';
        position: absolute;
        bottom: -50%;
        left: -50%;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(108, 92, 231, 0.15), transparent 70%);
        border-radius: 50%;
        animation: pulse 4s infinite reverse;
    }

    .welcome-text {
        position: relative;
        z-index: 1;
    }

    .welcome-text h1 {
        font-size: 32px;
        margin-bottom: 8px;
        background: linear-gradient(135deg, #75e6da, #6c5ce7);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        display: inline-block;
    }

    .welcome-text p {
        font-size: 16px;
    }

    .date-badge {
        background: var(--bg-primary);
        padding: 8px 16px;
        border-radius: 30px;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        position: relative;
        z-index: 1;
    }

    .date-badge i {
        color: #75e6da;
    }

    @keyframes pulse {
        0%, 100% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.1);
        }
    }

    /* Flatpickr custom theme */
    .flatpickr-calendar {
        background: var(--bg-secondary) !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: var(--shadow) !important;
    }

    .flatpickr-months .flatpickr-month {
        background: var(--bg-secondary) !important;
        color: var(--text-primary) !important;
    }

    .flatpickr-weekdays {
        background: var(--bg-secondary) !important;
    }

    .flatpickr-weekday {
        color: var(--text-secondary) !important;
    }

    .flatpickr-day {
        color: var(--text-primary) !important;
    }

    .flatpickr-day:hover {
        background: var(--hover-bg) !important;
        border-color: #75e6da !important;
    }

    .flatpickr-day.selected {
        background: linear-gradient(135deg, #75e6da, #6c5ce7) !important;
        border-color: #75e6da !important;
    }

    .flatpickr-day.today {
        border-color: #75e6da !important;
    }

    .flatpickr-time {
        background: var(--bg-secondary) !important;
    }

    .flatpickr-time input {
        color: var(--text-primary) !important;
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .dashboard-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .charts-row,
        .insights-row {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        
        .welcome-section {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
        }
        
        .welcome-text h1 {
            font-size: 24px;
        }

        .chart-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .date-range-picker {
            width: 100%;
            flex-wrap: wrap;
        }

        .date-input {
            flex: 1;
        }
    }
</style>

<div class="main-content">
    <!-- Statistics Cards -->
    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrapper">
                    <i class="fas fa-box"></i>
                </div>
                <div class="stat-badge-group">
                    <span class="stat-trend-badge <?php echo $productsTrend >= 0 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-arrow-<?php echo $productsTrend >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo number_format(abs($productsTrend), 1); ?>%
                    </span>
                    <span class="stat-time-badge">
                        <i class="far fa-clock"></i> vs last month
                    </span>
                </div>
            </div>
            <div class="stat-content">
                <div class="stat-value-large"><?php echo number_format($totalProducts); ?></div>
                <div class="stat-label-wrapper">
                    <span class="stat-label">Total Products</span>
                    <span class="stat-info-badge">
                        <i class="fas fa-info-circle"></i>
                        <span class="stat-info-tooltip">All products in inventory including active and inactive items</span>
                    </span>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-footer-left">
                    <i class="fas fa-cube"></i>
                    <span>Active inventory items</span>
                </div>
                <div class="stat-progress">
                    <div class="stat-progress-bar" style="width: 75%"></div>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrapper">
                    <i class="fas fa-peso-sign"></i>
                </div>
                <div class="stat-badge-group">
                    <span class="stat-trend-badge positive">
                        <i class="fas fa-arrow-up"></i>
                        15.0%
                    </span>
                    <span class="stat-time-badge">
                        <i class="far fa-calendar-alt"></i> this month
                    </span>
                </div>
            </div>
            <div class="stat-content">
                <div class="stat-value-large">₱<?php echo number_format($totalValue, 0); ?></div>
                <div class="stat-label-wrapper">
                    <span class="stat-label">Inventory Value</span>
                    <span class="stat-info-badge">
                        <i class="fas fa-info-circle"></i>
                        <span class="stat-info-tooltip">Total value of all products based on current stock and prices</span>
                    </span>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-footer-left">
                    <i class="fas fa-chart-line"></i>
                    <span>Total stock value</span>
                </div>
                <div class="stat-distribution">
                    <div class="stat-dot" style="background: #75e6da;"></div>
                    <span class="stat-distribution-text">+12.5% from last month</span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrapper">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-badge-group">
                    <span class="stat-availability-badge">
                        <i class="fas fa-check"></i> In Stock
                    </span>
                </div>
            </div>
            <div class="stat-content">
                <div class="stat-value-large"><?php echo number_format($totalQuantity); ?></div>
                <div class="stat-label-wrapper">
                    <span class="stat-label">Units in Stock</span>
                    <span class="stat-info-badge">
                        <i class="fas fa-info-circle"></i>
                        <span class="stat-info-tooltip">Total number of individual units available across all products</span>
                    </span>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-footer-left">
                    <i class="fas fa-boxes"></i>
                    <span>Available units</span>
                </div>
                <div class="stat-availability">
                    <span class="stat-availability-high">
                        <i class="fas fa-circle"></i> 95% in stock
                    </span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrapper">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-badge-group">
                    <span class="stat-urgency-badge <?php echo $lowStock > 0 ? 'warning' : 'safe'; ?>">
                        <i class="fas fa-exclamation"></i> <?php echo $lowStock > 0 ? 'Action Needed' : 'All Good'; ?>
                    </span>
                </div>
            </div>
            <div class="stat-content">
                <div class="stat-value-large <?php echo $lowStock > 0 ? 'text-warning' : ''; ?>"><?php echo $lowStock; ?></div>
                <div class="stat-label-wrapper">
                    <span class="stat-label">Low Stock Items</span>
                    <span class="stat-info-badge">
                        <i class="fas fa-info-circle"></i>
                        <span class="stat-info-tooltip">Products with quantity less than 10 units that need reordering</span>
                    </span>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-footer-left">
                    <i class="fas fa-clock"></i>
                    <span>Need reorder soon</span>
                </div>
                <?php if($lowStock > 0): ?>
                <a href="products.php?filter=low_stock" class="stat-action-link">
                    View Items <i class="fas fa-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="stat-allgood">
                    <i class="fas fa-check-circle"></i> All stocked
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-header">
                <h3>
                    <i class="fas fa-chart-pie"></i>
                    Category Stock Distribution
                </h3>
                <i class="fas fa-ellipsis-h"></i>
            </div>
            <div class="chart-container">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>

        
    <!-- Insights Row -->
    <div class="insights-row">
        <div class="insights-card">
            <div class="insights-header">
                <i class="fas fa-fire"></i>
                <h3>Top Products</h3>
            </div>
            <ul class="product-list">
                <?php 
                $rank = 1;
                while($product = $topProducts->fetch_assoc()): 
                    $stockClass = $product['quantity'] > 50 ? 'stock-high' : ($product['quantity'] > 20 ? 'stock-medium' : 'stock-low');
                ?>
                <li class="product-list-item">
                    <span class="product-rank"><?php echo $rank++; ?></span>
                    <div class="product-info">
                        <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                        <div class="product-meta"><?php echo htmlspecialchars($product['category']); ?></div>
                    </div>
                    <div>
                        <span class="product-stock <?php echo $stockClass; ?>"><?php echo $product['quantity']; ?> units</span>
                    </div>
                </li>
                <?php endwhile; ?>
            </ul>
        </div>

        <div class="insights-card">
            <div class="insights-header">
                <i class="fas fa-clock"></i>
                <h3>Recent Additions</h3>
            </div>
            <ul class="product-list">
                <?php 
                while($product = $recentProducts->fetch_assoc()): 
                    $stockClass = $product['quantity'] > 50 ? 'stock-high' : ($product['quantity'] > 20 ? 'stock-medium' : 'stock-low');
                ?>
                <li class="product-list-item">
                    <div class="product-icon" style="width: 32px; height: 32px; background: linear-gradient(135deg, #75e6da, #6c5ce7); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-box" style="color: white; font-size: 14px;"></i>
                    </div>
                    <div class="product-info">
                        <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                        <div class="product-meta"><?php echo $product['quantity']; ?> units • Added <?php echo date('M d', strtotime($product['created_at'])); ?></div>
                    </div>
                    <div>
                        <span class="product-stock <?php echo $stockClass; ?>"><?php echo $product['quantity']; ?></span>
                    </div>
                </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize flatpickr for date range
    const startDatePicker = flatpickr("#startDate", {
        dateFormat: "Y-m-d",
        maxDate: "today",
        defaultDate: "<?php echo $startDate; ?>"
    });

    const endDatePicker = flatpickr("#endDate", {
        dateFormat: "Y-m-d",
        maxDate: "today",
        defaultDate: "<?php echo $endDate; ?>"
    });

    // Category Stock Distribution Chart (UPDATED: now shows quantity per category)
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    
    <?php
    // Reset category stats pointer
    $categoryStats->data_seek(0);
    $categories = [];
    $categoryQuantities = [];
    while($cat = $categoryStats->fetch_assoc()) {
        $categories[] = $cat['category'];
        $categoryQuantities[] = (int)$cat['total_quantity'];
    }
    ?>
    
    new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($categories); ?>,
            datasets: [{
                data: <?php echo json_encode($categoryQuantities); ?>,
                backgroundColor: [
                    '#75e6da',
                    '#6c5ce7',
                    '#e84393',
                    '#f39c12',
                    '#00b894'
                ],
                borderWidth: 0,
                borderRadius: 10,
                spacing: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#a0a3c0',
                        font: {
                            size: 11
                        },
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} units (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

    // Stock Quantity by Date Chart (UPDATED: now shows quantity instead of value)
    const stockCtx = document.getElementById('stockChart').getContext('2d');
    let stockChart = new Chart(stockCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_values($dates)); ?>,
            datasets: [
                {
                    label: 'Total Stock Quantity',
                    data: <?php echo json_encode(array_values($values)); ?>,
                    borderColor: '#75e6da',
                    backgroundColor: 'rgba(117, 230, 218, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#75e6da',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'var(--bg-secondary)',
                    titleColor: 'var(--text-primary)',
                    bodyColor: 'var(--text-secondary)',
                    borderColor: '#75e6da',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            return context.raw.toLocaleString() + ' units';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)'
                    },
                    ticks: {
                        color: '#a0a3c0',
                        callback: function(value) {
                            return value.toLocaleString() + ' units';
                        }
                    },
                    title: {
                        display: true,
                        text: 'Quantity (Units)',
                        color: '#a0a3c0'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#a0a3c0',
                        font: {
                            size: 11
                        }
                    }
                }
            }
        }
    });

    // Handle Apply button click (UPDATED: now fetches quantity data)
    document.getElementById('applyDateRange').addEventListener('click', function() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        // Show loading
        document.getElementById('chartLoading').style.display = 'flex';
        this.disabled = true;

        // Make AJAX request
        fetch('get_chart_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                start_date: startDate,
                end_date: endDate,
                data_type: 'quantity' // Add this parameter to specify we want quantity data
            })
        })
        .then(response => response.json())
        .then(data => {
            // Update chart with new data
            stockChart.data.labels = data.dates;
            stockChart.data.datasets[0].data = data.values;
            stockChart.update();
            
            // Hide loading
            document.getElementById('chartLoading').style.display = 'none';
            document.getElementById('applyDateRange').disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading chart data');
            document.getElementById('chartLoading').style.display = 'none';
            document.getElementById('applyDateRange').disabled = false;
        });
    });
});
</script>

<?php
// Include footer
require_once 'include/footer.php';
?>