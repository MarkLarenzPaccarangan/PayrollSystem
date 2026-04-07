<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once("connection.php");

// Get parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if (empty($date_from) || empty($date_to)) {
    echo json_encode(['success' => false, 'message' => 'Date range is required']);
    exit;
}

// Build query
$sql = "SELECT 
            e.id AS employee_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.position,
            a.id as attendance_id,
            a.date,
            a.status,
            a.pm_status,
            a.night_status,
            a.time_in_am,
            a.time_out_am,
            a.time_in_pm,
            a.time_out_pm,
            a.time_in_night,
            a.time_out_night,
            a.site,
            a.leave_type,
            a.workday_type
        FROM employees e
        LEFT JOIN attendance a ON a.employee_id = e.id 
        WHERE a.date BETWEEN ? AND ?";

$params = [$date_from, $date_to];
$param_types = "ss";

if ($employee_id > 0) {
    $sql .= " AND e.id = ?";
    $params[] = $employee_id;
    $param_types .= "i";
}

$sql .= " ORDER BY e.last_name ASC, a.date ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
$total_hours = 0;
$present_count = 0;
$absent_count = 0;
$leave_count = 0;

while ($row = $result->fetch_assoc()) {
    // Count statuses - FIXED to check leave_type first
    if (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '') {
        $leave_count++;
    } elseif ($row['status'] == 'Present') {
        $present_count++;
    } elseif ($row['status'] == 'On Leave') {
        $leave_count++;
    } elseif ($row['status'] == 'Absent') {
        $absent_count++;
    }
    
    // Format times for display - FIXED to handle 00:00:00
    $row['time_in_am_display'] = formatTimeForAPI($row['time_in_am']);
    $row['time_out_am_display'] = formatTimeForAPI($row['time_out_am']);
    $row['time_in_pm_display'] = formatTimeForAPI($row['time_in_pm']);
    $row['time_out_pm_display'] = formatTimeForAPI($row['time_out_pm']);
    $row['time_in_night_display'] = formatTimeForAPI($row['time_in_night']);
    $row['time_out_night_display'] = formatTimeForAPI($row['time_out_night']);
    
    // Calculate total hours for this record - FIXED to handle midnight
    $total = calculateTotalHoursAPI(
        $row['time_in_am'], $row['time_out_am'],
        $row['time_in_pm'], $row['time_out_pm'],
        $row['time_in_night'], $row['time_out_night']
    );
    $row['total_hours'] = $total;
    $total_hours += floatval($total);
    
    // Format employee name
    $row['employee_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
    
    // Format date
    if (!empty($row['date'])) {
        $row['date_display'] = date('M d, Y', strtotime($row['date']));
    } else {
        $row['date_display'] = '-';
    }
    
    $records[] = $row;
}

echo json_encode([
    'success' => true,
    'records' => $records,
    'total_hours' => number_format($total_hours, 2),
    'record_count' => count($records),
    'present_count' => $present_count,
    'absent_count' => $absent_count,
    'leave_count' => $leave_count
]);

$stmt->close();
$conn->close();

// ============================================
// HELPER FUNCTIONS - FIXED FOR MIDNIGHT
// ============================================

function formatTimeForAPI($time) {
    if (empty($time) || $time === null) {
        return '-';
    }
    // Handle midnight (12:00 AM) specially
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
}

function calculateTotalHoursAPI($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    
    // Calculate AM hours - FIXED to include 00:00:00
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHoursAPI($time_in_am, $time_out_am);
        $total += floatval($am_hours);
    }
    
    // Calculate PM hours - FIXED to include 00:00:00
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHoursAPI($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
    }
    
    // Calculate Night hours - FIXED to include 00:00:00
    if (!empty($time_in_night) && !empty($time_out_night)) {
        $night_hours = calculateHoursAPI($time_in_night, $time_out_night);
        $total += floatval($night_hours);
    }
    
    return number_format($total, 2);
}

function calculateHoursAPI($time_in, $time_out) {
    // Check if times are empty
    if (empty($time_in) || empty($time_out)) {
        return 0;
    }
    
    // Special handling for midnight (12:00 AM) as time_out
    $is_midnight_out = ($time_out == '00:00:00');
    
    // Convert to timestamps
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return 0;
    }
    
    if ($is_midnight_out) {
        // For midnight (12:00 AM) as time_out, calculate hours until midnight of the same day
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        
        // Calculate hours from time_in to midnight
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        return max(0, $hours);
    }
    
    // Regular time calculation (not midnight)
    $time_out_ts = strtotime($time_out);
    
    if ($time_out_ts === false) {
        return 0;
    }
    
    // If time_out is less than time_in, it means it's next day
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts += 86400; // Add 24 hours
    }
    
    // Calculate hours
    $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
    
    // Prevent negative hours
    if ($hours < 0) {
        $hours = 0;
    }
    
    return $hours;
}
?>