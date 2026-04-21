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
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

if (empty($date_from) || empty($date_to)) {
    echo json_encode(['success' => false, 'message' => 'Date range is required']);
    exit;
}

// Get site name if filtered
$selected_site_name = '';
if ($site_id > 0) {
    $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_id);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    if ($site_row = $site_result->fetch_assoc()) {
        $selected_site_name = $site_row['site_name'];
    }
    $site_stmt->close();
}

// If site is selected, only get that site
if ($site_id > 0 && !empty($selected_site_name)) {
    $sites = [$selected_site_name];
} else {
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
}

// For each site, get attendance records
$site_data = [];
$all_records_flat = [];
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
        
        // Calculate total hours for THIS SITE only (only sessions assigned to this site)
        $is_am_assigned = ($row['site_assignment_am'] == $site_name);
        $is_pm_assigned = ($row['site_assignment_pm'] == $site_name);
        $is_night_assigned = ($row['site_assignment_night'] == $site_name);
        
        $site_specific_hours = 0;
        
        if ($is_am_assigned && !empty($row['time_in_am']) && !empty($row['time_out_am'])) {
            $site_specific_hours += floatval(calculateHoursAPI($row['time_in_am'], $row['time_out_am']));
        }
        if ($is_pm_assigned && !empty($row['time_in_pm']) && !empty($row['time_out_pm'])) {
            $site_specific_hours += floatval(calculateHoursAPI($row['time_in_pm'], $row['time_out_pm']));
        }
        if ($is_night_assigned && !empty($row['time_in_night']) && !empty($row['time_out_night'])) {
            $site_specific_hours += floatval(calculateHoursAPI($row['time_in_night'], $row['time_out_night']));
        }
        
        $row['total_hours_for_site'] = number_format($site_specific_hours, 2);
        $site_total_hours += $site_specific_hours;
        
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
        if ($is_am_assigned) {
            $assigned_sessions[] = 'AM';
        }
        if ($is_pm_assigned) {
            $assigned_sessions[] = 'PM';
        }
        if ($is_night_assigned) {
            $assigned_sessions[] = 'Night';
        }
        $row['assigned_sessions'] = $assigned_sessions;
        $row['is_am_assigned'] = $is_am_assigned;
        $row['is_pm_assigned'] = $is_pm_assigned;
        $row['is_night_assigned'] = $is_night_assigned;
        
        // Determine overall status for this record at this site
        $is_on_leave = (!empty($row['leave_type']) && $row['leave_type'] != 'None' && $row['leave_type'] != '');
        
        if ($is_on_leave) {
            $row['overall_status'] = 'On Leave';
            $site_leave_count++;
        } else {
            $has_present = false;
            $has_absent = false;
            
            if ($is_am_assigned && $row['status'] == 'Present') $has_present = true;
            if ($is_pm_assigned && $row['pm_status'] == 'Present') $has_present = true;
            if ($is_night_assigned && $row['night_status'] == 'Present') $has_present = true;
            
            if ($is_am_assigned && $row['status'] == 'Absent') $has_absent = true;
            if ($is_pm_assigned && $row['pm_status'] == 'Absent') $has_absent = true;
            if ($is_night_assigned && $row['night_status'] == 'Absent') $has_absent = true;
            
            if ($has_present) {
                $row['overall_status'] = 'Present';
                $site_present_count++;
            } elseif ($has_absent) {
                $row['overall_status'] = 'Absent';
                $site_absent_count++;
            } else {
                $row['overall_status'] = 'No Record';
            }
        }
        
        $site_records[] = $row;
        
        // Add to flat array for backward compatibility
        $flat_row = $row;
        $flat_row['site_name'] = $site_name;
        $flat_row['total_hours'] = $row['total_hours_for_site'];
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
    // Backward compatibility
    'records' => $all_records_flat,
    'total_hours' => number_format($grand_total_hours, 2),
    'record_count' => count($all_records_flat),
    'present_count' => $grand_present_count,
    'absent_count' => $grand_absent_count,
    'leave_count' => $grand_leave_count
]);

$conn->close();

// ============================================
// HELPER FUNCTIONS
// ============================================

function formatTimeForAPI($time) {
    if (empty($time) || $time === null) {
        return '-';
    }
    if ($time == '00:00:00') {
        return '12:00 AM';
    }
    return date('h:i A', strtotime($time));
}

function calculateHoursAPI($time_in, $time_out) {
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
    
    if ($hours < 0) {
        $hours = 0;
    }
    
    return $hours;
}
?>