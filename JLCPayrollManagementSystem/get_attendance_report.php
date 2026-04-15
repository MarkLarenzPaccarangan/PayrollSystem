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

// Get all distinct sites that have attendance records within the date range
$sites_query = "SELECT DISTINCT site_name 
                FROM (
                    SELECT site_assignment_am as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_am IS NOT NULL AND site_assignment_am != ''
                    UNION
                    SELECT site_assignment_pm as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_pm IS NOT NULL AND site_assignment_pm != ''
                    UNION
                    SELECT site_assignment_night as site_name FROM attendance 
                    WHERE date BETWEEN ? AND ? AND site_assignment_night IS NOT NULL AND site_assignment_night != ''
                ) AS sites
                ORDER BY site_name";

$sites_stmt = $conn->prepare($sites_query);
$sites_stmt->bind_param("ssssss", $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
$sites_stmt->execute();
$sites_result = $sites_stmt->get_result();

$sites = [];
while ($site_row = $sites_result->fetch_assoc()) {
    $sites[] = $site_row['site_name'];
}
$sites_stmt->close();

// For each site, get attendance records
$site_data = [];
$all_records_flat = []; // FOR BACKWARD COMPATIBILITY - keeps original flat structure
$grand_total_records = 0;
$grand_total_hours = 0;
$grand_present_count = 0;
$grand_absent_count = 0;
$grand_leave_count = 0;

foreach ($sites as $site_name) {
    // Build query for this specific site
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
                a.remarks,
                a.leave_type,
                a.workday_type,
                a.site_assignment_am,
                a.site_assignment_pm,
                a.site_assignment_night
            FROM employees e
            INNER JOIN attendance a ON a.employee_id = e.id 
            WHERE a.date BETWEEN ? AND ?
            AND (
                a.site_assignment_am = ? OR 
                a.site_assignment_pm = ? OR 
                a.site_assignment_night = ?
            )";
    
    $params = [$date_from, $date_to, $site_name, $site_name, $site_name];
    $param_types = "sssss";
    
    if ($employee_id > 0) {
        $sql .= " AND e.id = ?";
        $params[] = $employee_id;
        $param_types .= "i";
    }
    
    $sql .= " ORDER BY e.last_name ASC, a.date ASC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        continue;
    }
    
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $site_records = [];
    $site_total_hours = 0;
    $site_present_count = 0;
    $site_absent_count = 0;
    $site_leave_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Format times for display
        $row['time_in_am_display'] = formatTimeForAPI($row['time_in_am']);
        $row['time_out_am_display'] = formatTimeForAPI($row['time_out_am']);
        $row['time_in_pm_display'] = formatTimeForAPI($row['time_in_pm']);
        $row['time_out_pm_display'] = formatTimeForAPI($row['time_out_pm']);
        $row['time_in_night_display'] = formatTimeForAPI($row['time_in_night']);
        $row['time_out_night_display'] = formatTimeForAPI($row['time_out_night']);
        
        // Calculate total hours for this record
        $total = calculateTotalHoursAPI(
            $row['time_in_am'], $row['time_out_am'],
            $row['time_in_pm'], $row['time_out_pm'],
            $row['time_in_night'], $row['time_out_night']
        );
        $row['total_hours'] = $total;
        $site_total_hours += floatval($total);
        
        // Format employee name
        $row['employee_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
        
        // Format date
        if (!empty($row['date'])) {
            $row['date_display'] = date('M d, Y', strtotime($row['date']));
            $row['day_of_week'] = date('D', strtotime($row['date']));
        } else {
            $row['date_display'] = '-';
            $row['day_of_week'] = '-';
        }
        
        // Determine which session(s) this employee attended at this site
        $assigned_sessions = [];
        if ($row['site_assignment_am'] == $site_name) {
            $assigned_sessions[] = 'AM';
        }
        if ($row['site_assignment_pm'] == $site_name) {
            $assigned_sessions[] = 'PM';
        }
        if ($row['site_assignment_night'] == $site_name) {
            $assigned_sessions[] = 'Night';
        }
        $row['assigned_sessions'] = $assigned_sessions;
        
        // ============================================
        // FIXED STATUS LOGIC - Checks ALL THREE SESSIONS
        // ============================================
        
        // Check if ON LEAVE first (highest priority)
        $is_on_leave = (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '');
        
        // Check if PRESENT in ANY session (AM, PM, or Night)
        $is_present = ($row['status'] == 'Present' || $row['pm_status'] == 'Present' || $row['night_status'] == 'Present');
        
        // Determine final status
        if ($is_on_leave) {
            $row['overall_status'] = 'On Leave';
            $site_leave_count++;
        } elseif ($is_present) {
            $row['overall_status'] = 'Present';
            $site_present_count++;
        } else {
            $row['overall_status'] = 'Absent';
            $site_absent_count++;
        }
        
        $site_records[] = $row;
        
        // ============================================
        // ALSO ADD TO FLAT ARRAY FOR BACKWARD COMPATIBILITY
        // ============================================
        $flat_row = $row;
        $flat_row['site_name'] = $site_name;
        $all_records_flat[] = $flat_row;
    }
    
    $site_data[] = [
        'site_name' => $site_name,
        'records' => $site_records,
        'summary' => [
            'record_count' => count($site_records),
            'present_count' => $site_present_count,
            'absent_count' => $site_absent_count,
            'leave_count' => $site_leave_count,
            'total_hours' => number_format($site_total_hours, 2)
        ]
    ];
    
    $grand_total_records += count($site_records);
    $grand_total_hours += $site_total_hours;
    $grand_present_count += $site_present_count;
    $grand_absent_count += $site_absent_count;
    $grand_leave_count += $site_leave_count;
    
    $stmt->close();
}

// Calculate grand total rates
$grand_total_accounted = $grand_present_count + $grand_absent_count + $grand_leave_count;
$grand_present_rate = ($grand_total_accounted > 0) ? round(($grand_present_count / $grand_total_accounted) * 100, 2) : 0;
$grand_absent_rate = ($grand_total_accounted > 0) ? round(($grand_absent_count / $grand_total_accounted) * 100, 2) : 0;
$grand_leave_rate = ($grand_total_accounted > 0) ? round(($grand_leave_count / $grand_total_accounted) * 100, 2) : 0;

// Calculate totals for flat records (for backward compatibility)
$flat_total_hours = 0;
$flat_present_count = 0;
$flat_absent_count = 0;
$flat_leave_count = 0;

foreach ($all_records_flat as $record) {
    $flat_total_hours += floatval($record['total_hours']);
    if ($record['overall_status'] == 'Present') {
        $flat_present_count++;
    } elseif ($record['overall_status'] == 'Absent') {
        $flat_absent_count++;
    } elseif ($record['overall_status'] == 'On Leave') {
        $flat_leave_count++;
    }
}

echo json_encode([
    'success' => true,
    'grouped_by_site' => true,
    'date_from' => $date_from,
    'date_to' => $date_to,
    'sites' => $site_data,
    'grand_total' => [
        'total_records' => $grand_total_records,
        'present_count' => $grand_present_count,
        'absent_count' => $grand_absent_count,
        'leave_count' => $grand_leave_count,
        'total_hours' => number_format($grand_total_hours, 2),
        'present_rate' => $grand_present_rate,
        'absent_rate' => $grand_absent_rate,
        'leave_rate' => $grand_leave_rate
    ],
    // ============================================
    // BACKWARD COMPATIBILITY - Original flat structure
    // The existing displayReportPreview() in attendance.php expects this
    // ============================================
    'records' => $all_records_flat,
    'total_hours' => number_format($flat_total_hours, 2),
    'record_count' => count($all_records_flat),
    'present_count' => $flat_present_count,
    'absent_count' => $flat_absent_count,
    'leave_count' => $flat_leave_count
]);

$conn->close();

// ============================================
// HELPER FUNCTIONS
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

function calculateTotalHoursAPI($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    
    // Calculate AM hours
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHoursAPI($time_in_am, $time_out_am);
        $total += floatval($am_hours);
    }
    
    // Calculate PM hours
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHoursAPI($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
    }
    
    // Calculate Night hours
    if (!empty($time_in_night) && !empty($time_out_night)) {
        $night_hours = calculateHoursAPI($time_in_night, $time_out_night);
        $total += floatval($night_hours);
    }
    
    return number_format($total, 2);
}
?>