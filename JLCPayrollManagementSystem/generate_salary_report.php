<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

// ============================================
// PAYROLL CALCULATION FUNCTIONS
// ============================================

function getWorkdayMultipliersForSalaryReport($workday_type) {
    if (empty($workday_type)) {
        $workday_type = 'Ordinary Working Day';
    }
    
    $multipliers = [
        'Ordinary Working Day' => [
            'basic' => 1.0,
            'overtime' => 1.25,
            'night' => 1.10,
            'paid_when_absent' => false
        ],
        'Rest Day / Sunday' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special (Non-Working) Day' => [
            'basic' => 1.3,
            'overtime' => 1.69,
            'night' => 1.43,
            'paid_when_absent' => false
        ],
        'Special Day that falls on Rest Day' => [
            'basic' => 1.5,
            'overtime' => 1.95,
            'night' => 1.65,
            'paid_when_absent' => false
        ],
        'Regular Holiday' => [
            'basic' => 2.0,
            'overtime' => 2.6,
            'night' => 2.2,
            'paid_when_absent' => true
        ],
        'Regular Holiday on the Rest Day' => [
            'basic' => 2.6,
            'overtime' => 3.38,
            'night' => 2.86,
            'paid_when_absent' => true
        ],
        'Double Holiday' => [
            'basic' => 3.0,
            'overtime' => 3.9,
            'night' => 3.3,
            'paid_when_absent' => true
        ],
        'Double Holiday on the Rest Day' => [
            'basic' => 3.9,
            'overtime' => 5.07,
            'night' => 4.29,
            'paid_when_absent' => true
        ]
    ];
    
    if (isset($multipliers[$workday_type])) {
        return $multipliers[$workday_type];
    } else {
        return $multipliers['Ordinary Working Day'];
    }
}

function calculateHoursForSalaryReport($time_in, $time_out) {
    if (empty($time_in) || empty($time_out)) {
        return 0;
    }
    
    $is_midnight_out = ($time_out == '00:00:00');
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return 0;
    }
    
    if ($is_midnight_out) {
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        return max(0, $hours);
    }
    
    $time_out_ts = strtotime($time_out);
    if ($time_out_ts === false) {
        return 0;
    }
    
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400;
    }
    
    $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
    return max(0, $hours);
}

// Calculate day payroll (AM + PM combined)
function calculateDayPayrollForSalaryReport($am_hours, $pm_hours, $hourly_rate, $multipliers, $is_paid_holiday_for_regular = false) {
    $total_hours = $am_hours + $pm_hours;
    
    if ($total_hours == 0) {
        return [
            'hours' => 0,
            'payroll' => 0,
            'regular_hours' => 0,
            'overtime_hours' => 0
        ];
    }
    
    $payroll = 0;
    $regular_hours = 0;
    $overtime_hours = 0;
    
    if ($is_paid_holiday_for_regular) {
        if ($total_hours <= 8) {
            $regular_hours = $total_hours;
            $payroll = $total_hours * $hourly_rate * 1.0;
        } else {
            $regular_hours = 8;
            $overtime_hours = $total_hours - 8;
            $payroll = (8 * $hourly_rate * 1.0) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
        }
    } else {
        if ($total_hours <= 8) {
            $regular_hours = $total_hours;
            $payroll = $total_hours * $hourly_rate * $multipliers['basic'];
        } else {
            $regular_hours = 8;
            $overtime_hours = $total_hours - 8;
            $payroll = (8 * $hourly_rate * $multipliers['basic']) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
        }
    }
    
    return [
        'hours' => $total_hours,
        'payroll' => $payroll,
        'regular_hours' => $regular_hours,
        'overtime_hours' => $overtime_hours
    ];
}

// Calculate night payroll
function calculateNightPayrollForSalaryReport($night_hours, $hourly_rate, $multipliers) {
    if ($night_hours == 0) {
        return [
            'hours' => 0,
            'payroll' => 0,
            'regular_hours' => 0,
            'overtime_hours' => 0
        ];
    }
    
    $payroll = 0;
    $regular_hours = 0;
    $overtime_hours = 0;
    
    if ($night_hours <= 8) {
        $regular_hours = $night_hours;
        $payroll = $night_hours * $hourly_rate * $multipliers['night'];
    } else {
        $regular_hours = 8;
        $overtime_hours = $night_hours - 8;
        $payroll = (8 * $hourly_rate * $multipliers['night']) + ($overtime_hours * $hourly_rate * $multipliers['overtime']);
    }
    
    return [
        'hours' => $night_hours,
        'payroll' => $payroll,
        'regular_hours' => $regular_hours,
        'overtime_hours' => $overtime_hours
    ];
}

// Get parameters
$date_from = isset($_POST['date_from']) ? $_POST['date_from'] : (isset($_GET['date_from']) ? $_GET['date_from'] : '');
$date_to = isset($_POST['date_to']) ? $_POST['date_to'] : (isset($_GET['date_to']) ? $_GET['date_to'] : '');
$employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : (isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0);
$site_filter = isset($_POST['site_id']) ? intval($_POST['site_id']) : (isset($_GET['site_id']) ? intval($_GET['site_id']) : 0);

if (empty($date_from) || empty($date_to)) {
    die("Date range is required");
}

// Get employee name if filtered
$employee_name = "All Employees";
if ($employee_id > 0) {
    $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?";
    $name_stmt = $conn->prepare($name_query);
    $name_stmt->bind_param("i", $employee_id);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($name_row = $name_result->fetch_assoc()) {
        $employee_name = $name_row['full_name'];
    }
    $name_stmt->close();
}

// Get site name if filtered
$selected_site_name = '';
if ($site_filter > 0) {
    $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_filter);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    if ($site_row = $site_result->fetch_assoc()) {
        $selected_site_name = $site_row['site_name'];
    }
    $site_stmt->close();
}

// Get payroll records
if ($employee_id > 0) {
    $payroll_query = "
        SELECT 
            p.id,
            p.employee_id,
            p.date_from,
            p.date_to,
            p.base_salary,
            p.total_deductions,
            p.net_pay,
            p.total_work_hours,
            p.status,
            p.salary_breakdown,
            e.first_name,
            e.last_name,
            e.position,
            e.daily_salary,
            e.employment_type
        FROM payroll p
        JOIN employees e ON e.id = p.employee_id
        WHERE p.employee_id = ?
        AND ((p.date_from BETWEEN ? AND ?) OR (p.date_to BETWEEN ? AND ?))
        ORDER BY p.date_from DESC
    ";
    $payroll_stmt = $conn->prepare($payroll_query);
    $payroll_stmt->bind_param("issss", $employee_id, $date_from, $date_to, $date_from, $date_to);
} else {
    $payroll_query = "
        SELECT 
            p.id,
            p.employee_id,
            p.date_from,
            p.date_to,
            p.base_salary,
            p.total_deductions,
            p.net_pay,
            p.total_work_hours,
            p.status,
            p.salary_breakdown,
            e.first_name,
            e.last_name,
            e.position,
            e.daily_salary,
            e.employment_type
        FROM payroll p
        JOIN employees e ON e.id = p.employee_id
        WHERE (p.date_from BETWEEN ? AND ?) OR (p.date_to BETWEEN ? AND ?)
        ORDER BY e.last_name, e.first_name, p.date_from DESC
    ";
    $payroll_stmt = $conn->prepare($payroll_query);
    $payroll_stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
}

$payroll_stmt->execute();
$payroll_result = $payroll_stmt->get_result();

// Group payroll records by employee
$employee_payroll_data = [];
while ($row = $payroll_result->fetch_assoc()) {
    $emp_key = $row['employee_id'];
    if (!isset($employee_payroll_data[$emp_key])) {
        $employee_payroll_data[$emp_key] = [
            'employee_id' => $row['employee_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'position' => $row['position'],
            'daily_salary' => $row['daily_salary'],
            'employment_type' => $row['employment_type'],
            'records' => []
        ];
    }
    
    $employee_payroll_data[$emp_key]['records'][] = [
        'payroll_id' => $row['id'],
        'date_from' => $row['date_from'],
        'date_to' => $row['date_to'],
        'base_salary' => floatval($row['base_salary']),
        'total_deductions' => floatval($row['total_deductions']),
        'net_pay' => floatval($row['net_pay']),
        'total_work_hours' => floatval($row['total_work_hours']),
        'status' => $row['status'],
        'salary_breakdown' => json_decode($row['salary_breakdown'] ?? '{}', true)
    ];
}

$payroll_stmt->close();

// Set headers for Excel download with proper encoding
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Salary_Report_' . $date_from . '_to_' . $date_to . '.xls"');
header('Cache-Control: max-age=0');

// Add UTF-8 BOM for Excel to recognize UTF-8 characters
echo "\xEF\xBB\xBF";

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Salary Report - <?= $date_from ?> to <?= $date_to ?></title>
<style>
    body {
        font-family: 'Arial', sans-serif;
        margin: 30px;
        color: #333;
    }
    
    .header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 3px solid #2E7D32;
    }
    
    .header h1 {
        color: #2E7D32;
        font-size: 28px;
        margin: 0 0 10px 0;
    }
    
    .header h2 {
        color: #1B5E20;
        font-size: 20px;
        margin: 5px 0;
        font-weight: normal;
    }
    
    .header p {
        color: #666;
        font-size: 14px;
        margin: 5px 0;
    }
    
    .info-section {
        background: #f5f5f5;
        padding: 20px;
        margin-bottom: 30px;
        border-left: 6px solid #2E7D32;
        border-radius: 8px;
    }
    
    .info-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .info-item {
        flex: 1;
        min-width: 150px;
    }
    
    .info-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .info-value {
        font-size: 16px;
        font-weight: 700;
        color: #2E7D32;
    }
    
    .employee-section {
        margin-top: 40px;
        margin-bottom: 40px;
        page-break-inside: avoid;
    }
    
    .employee-title {
        background: linear-gradient(135deg, #2E7D32, #1B5E20);
        color: white;
        padding: 12px 20px;
        border-radius: 8px 8px 0 0;
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 0;
    }
    
    .employee-subtitle {
        background: #e8f5e9;
        padding: 8px 20px;
        font-size: 12px;
        color: #2E7D32;
        border-bottom: 1px solid #c8e6c9;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0;
        font-size: 11px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    th {
        background: #2E7D32;
        color: white;
        font-weight: 600;
        padding: 10px 5px;
        text-align: center;
        border: 1px solid #1B5E20;
        font-size: 12px;
    }
    
    td {
        padding: 8px 5px;
        border: 1px solid #ddd;
        text-align: center;
        vertical-align: middle;
    }
    
    tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    
    tr:hover {
        background-color: #f1f8e9;
    }
    
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    
    .currency {
        font-weight: 600;
        font-family: monospace;
    }
    
    .status-paid {
        color: #28a745;
        font-weight: 700;
        background-color: #e8f5e9;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .status-pending {
        color: #856404;
        font-weight: 700;
        background-color: #fff9e6;
        padding: 3px 8px;
        border-radius: 20px;
        display: inline-block;
        font-size: 10px;
    }
    
    .payroll-amount {
        color: #00838f;
        font-weight: 700;
    }
    
    .site-badge {
        display: inline-block;
        background: #e6f7f5;
        color: #00838f;
        padding: 2px 6px;
        border-radius: 10px;
        margin: 2px;
        font-size: 9px;
    }
    
    .breakdown-cell {
        font-size: 10px;
    }
    
    .employee-summary-container {
        margin-top: 20px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: none;
    }
    
    .summary-title {
        font-size: 14px;
        font-weight: 700;
        color: #2E7D32;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #2E7D32;
    }
    
    .summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .summary-box {
        flex: 1;
        min-width: 250px;
    }
    
    .summary-box h5 {
        color: #2E7D32;
        margin-bottom: 10px;
        font-size: 12px;
        font-weight: 700;
    }
    
    .summary-table {
        width: 100%;
        border: none;
        margin: 0;
        box-shadow: none;
    }
    
    .summary-table td {
        border: none;
        padding: 5px 0;
        text-align: left;
        background: transparent;
    }
    
    .summary-table td:first-child {
        font-weight: 600;
        color: #555;
        width: 60%;
    }
    
    .summary-table td:last-child {
        font-weight: 700;
        color: #2c3e50;
    }
    
    .grand-total {
        background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
        margin-top: 30px;
        padding: 20px;
        border-radius: 8px;
    }
    
    .footer {
        margin-top: 30px;
        padding-top: 15px;
        border-top: 2px solid #2E7D32;
        font-size: 11px;
        color: #666;
        text-align: center;
    }
    
    .no-data {
        text-align: center;
        padding: 50px;
        color: #666;
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <h1>SALARY REPORT</h1>
    <h2><?= date('F d, Y', strtotime($date_from)) ?> to <?= date('F d, Y', strtotime($date_to)) ?></h2>
    <p>Generated on: <?= date('F j, Y \a\t h:i A') ?></p>
</div>

<!-- Filter Information -->
<div class="info-section">
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Employee Filter</div>
            <div class="info-value"><?= htmlspecialchars($employee_name) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Site Filter</div>
            <div class="info-value"><?= !empty($selected_site_name) ? htmlspecialchars($selected_site_name) : 'All Sites' ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Report Period</div>
            <div class="info-value"><?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Total Employees</div>
            <div class="info-value"><?= count($employee_payroll_data) ?></div>
        </div>
    </div>
</div>

<?php if (empty($employee_payroll_data)): ?>
    <div class="no-data">
        <p>No salary records found for the selected period and filters.</p>
    </div>
<?php else: 
    $grand_total_base_salary = 0;
    $grand_total_deductions = 0;
    $grand_total_net_pay = 0;
    $grand_total_hours = 0;
    
    foreach ($employee_payroll_data as $emp_key => $emp_data):
        $emp_total_base = 0;
        $emp_total_deductions = 0;
        $emp_total_net = 0;
        $emp_total_hours = 0;
        $emp_site_breakdown = [];
        
        foreach ($emp_data['records'] as $record):
            $emp_total_base += $record['base_salary'];
            $emp_total_deductions += $record['total_deductions'];
            $emp_total_net += $record['net_pay'];
            $emp_total_hours += $record['total_work_hours'];
            
            // Aggregate site breakdown from salary_breakdown JSON
            if (!empty($record['salary_breakdown'])) {
                foreach ($record['salary_breakdown'] as $site => $breakdown) {
                    if (!isset($emp_site_breakdown[$site])) {
                        $emp_site_breakdown[$site] = ['hours' => 0, 'pay' => 0];
                    }
                    $emp_site_breakdown[$site]['hours'] += $breakdown['hours'];
                    $emp_site_breakdown[$site]['pay'] += $breakdown['pay'];
                }
            }
        endforeach;
        
        $grand_total_base_salary += $emp_total_base;
        $grand_total_deductions += $emp_total_deductions;
        $grand_total_net_pay += $emp_total_net;
        $grand_total_hours += $emp_total_hours;
        
        $full_name = trim($emp_data['first_name'] . ' ' . $emp_data['last_name']);
        $employment_type_label = $emp_data['employment_type'] === 'regular' ? 'Regular' : 'Non-Regular';
?>
        
        <!-- Employee Section -->
        <div class="employee-section">
            <div class="employee-title">
                <i class="fas fa-user"></i> <?= htmlspecialchars($full_name) ?> 
                <span style="font-size: 12px; margin-left: 10px;">(<?= $employment_type_label ?>)</span>
            </div>
            <div class="employee-subtitle">
                Position: <?= htmlspecialchars($emp_data['position']) ?> | Daily Rate: PHP <?= number_format($emp_data['daily_salary'], 2) ?>
            </div>
            
            <!-- Payroll Records Table -->
            <table>
                <thead>
                    <tr>
                        <th>Pay Period</th>
                        <th>Work Hours</th>
                        <th class="text-right">Base Salary</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Pay</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emp_data['records'] as $record): 
                        $date_from_display = date('M d, Y', strtotime($record['date_from']));
                        $date_to_display = date('M d, Y', strtotime($record['date_to']));
                        $status_class = $record['status'] === 'paid' ? 'status-paid' : 'status-pending';
                    ?>
                        <tr>
                            <td class="text-center"><?= $date_from_display ?> - <?= $date_to_display ?></td>
                            <td class="text-center"><?= number_format($record['total_work_hours'], 2) ?> hrs</td>
                            <td class="text-right currency">PHP <?= number_format($record['base_salary'], 2) ?></td>
                            <td class="text-right currency" style="color: #dc3545;">PHP <?= number_format($record['total_deductions'], 2) ?></td>
                            <td class="text-right currency payroll-amount">PHP <?= number_format($record['net_pay'], 2) ?></td>
                            <td class="text-center"><span class="<?= $status_class ?>"><?= ucfirst($record['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- Employee Subtotal Row -->
                    <tr style="background: #e8f5e9; font-weight: bold;">
                        <td class="text-right"><strong>SUBTOTAL:</strong></td>
                        <td class="text-center"><strong><?= number_format($emp_total_hours, 2) ?> hrs</strong></td>
                        <td class="text-right"><strong>PHP <?= number_format($emp_total_base, 2) ?></strong></td>
                        <td class="text-right"><strong>PHP <?= number_format($emp_total_deductions, 2) ?></strong></td>
                        <td class="text-right"><strong>PHP <?= number_format($emp_total_net, 2) ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Site Breakdown for this Employee -->
            <?php if (!empty($emp_site_breakdown)): ?>
            <div class="employee-summary-container" style="margin-top: 15px;">
                <div class="summary-title">Site Breakdown - <?= htmlspecialchars($full_name) ?></div>
                <div class="summary-grid">
                    <div class="summary-box">
                        <table class="summary-table">
                            <thead>
                                <tr><th>Site</th><th>Hours</th><th>Pay</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emp_site_breakdown as $site => $breakdown): ?>
                                <tr>
                                    <td><?= htmlspecialchars($site) ?></td>
                                    <td><?= number_format($breakdown['hours'], 2) ?> hrs</td>
                                    <td>PHP <?= number_format($breakdown['pay'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
<?php endforeach; ?>

    <!-- GRAND TOTAL SECTION -->
    <div class="grand-total">
        <div class="summary-title" style="color: #1B5E20; border-bottom-color: #1B5E20;">
            <i class="fas fa-chart-bar"></i> GRAND TOTALS
        </div>
        <div class="summary-grid">
            <div class="summary-box">
                <h5>OVERALL SUMMARY</h5>
                <table class="summary-table">
                    <tr><td>Total Employees:</td><td><strong><?= count($employee_payroll_data) ?></strong></td></tr>
                    <tr><td>Total Work Hours:</td><td><strong><?= number_format($grand_total_hours, 2) ?> hrs</strong></td></tr>
                    <tr><td>Total Base Salary:</td><td><strong>PHP <?= number_format($grand_total_base_salary, 2) ?></strong></td></tr>
                    <tr><td>Total Deductions:</td><td><strong>PHP <?= number_format($grand_total_deductions, 2) ?></strong></td></tr>
                    <tr style="border-top: 2px solid #2E7D32;"><td><strong>NET PAYROLL:</strong></td><td><strong>PHP <?= number_format($grand_total_net_pay, 2) ?></strong></td></tr>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Footer -->
<div class="footer">
    <p>Payroll Management System - Official Salary Report</p>
    <p>Generated on <?= date('F j, Y \a\t h:i A') ?> by <?= htmlspecialchars($_SESSION['Admin_User']) ?></p>
</div>

</body>
</html>
<?php
$conn->close();
?>