<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once("connection.php");

// Get parameters
$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    $date = date('Y-m-d');
}

if ($site_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit;
}

// Get site details (get the site name first)
$site_query = "SELECT s.*, so.assignment_type, so.person_group 
               FROM site_monitoring s 
               LEFT JOIN site_others so ON s.id = so.site_id 
               WHERE s.id = ?";
$site_stmt = $conn->prepare($site_query);
if (!$site_stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$site_stmt->bind_param("i", $site_id);
$site_stmt->execute();
$site_result = $site_stmt->get_result();
$site = $site_result->fetch_assoc();

if (!$site) {
    echo json_encode(['success' => false, 'message' => 'Site not found']);
    exit;
}

$site_name = $site['site_name'];

// FIXED: Get employees from ATTENDANCE table based on site assignment fields
// This is the correct query since employees are assigned to sites via attendance records
$query = "SELECT DISTINCT 
            e.id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.position,
            e.email,
            a.id as attendance_id,
            a.status,              /* AM status */
            a.pm_status,            /* PM status */
            a.night_status,         /* Night status */
            a.time_in_am,
            a.time_out_am,
            a.time_in_pm,
            a.time_out_pm,
            a.time_in_night,        /* Night time in */
            a.time_out_night,       /* Night time out */
            a.remarks,
            a.total_hours,
            a.leave_type,
            a.workday_type,
            a.site_assignment_am,
            a.site_assignment_pm,
            a.site_assignment_night
          FROM attendance a
          INNER JOIN employees e ON a.employee_id = e.id
          WHERE DATE(a.date) = DATE(?)
          AND (
              a.site_assignment_am = ? OR 
              a.site_assignment_pm = ? OR 
              a.site_assignment_night = ?
          )
          AND e.status = 'active'
          AND e.is_active = 1
          ORDER BY e.first_name, e.last_name";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("ssss", $date, $site_name, $site_name, $site_name);
$stmt->execute();
$result = $stmt->get_result();

$employees = [];
$present_count = 0;
$absent_count = 0;
$leave_count = 0;

// Helper function to format time
$formatTime = function($time) {
    if (empty($time) || $time == '00:00:00') {
        return '--:--';
    }
    return date('h:i A', strtotime($time));
};

while ($row = $result->fetch_assoc()) {
    // Check if employee is on leave based on leave_type column
    $is_on_leave = !empty($row['leave_type']);
    
    // ============================================
    // SESSION-SPECIFIC DISPLAY LOGIC
    // For each session, only show data if THIS site was assigned
    // ============================================
    
    // ----- AM SESSION -----
    if ($row['site_assignment_am'] == $site_name) {
        if ($is_on_leave) {
            $status_am = 'On Leave';
            $time_in_am_display = '--:--';
            $time_out_am_display = '--:--';
        } else {
            $status_am = $row['status'] ?? 'Present';
            $time_in_am_display = $formatTime($row['time_in_am']);
            $time_out_am_display = $formatTime($row['time_out_am']);
        }
        $am_assigned = true;
    } else {
        $status_am = 'No Record';
        $time_in_am_display = '--:--';
        $time_out_am_display = '--:--';
        $am_assigned = false;
    }
    
    // ----- PM SESSION -----
    if ($row['site_assignment_pm'] == $site_name) {
        if ($is_on_leave) {
            $status_pm = 'On Leave';
            $time_in_pm_display = '--:--';
            $time_out_pm_display = '--:--';
        } else {
            $status_pm = $row['pm_status'] ?? 'Present';
            $time_in_pm_display = $formatTime($row['time_in_pm']);
            $time_out_pm_display = $formatTime($row['time_out_pm']);
        }
        $pm_assigned = true;
    } else {
        $status_pm = 'No Record';
        $time_in_pm_display = '--:--';
        $time_out_pm_display = '--:--';
        $pm_assigned = false;
    }
    
    // ----- NIGHT SESSION -----
    if ($row['site_assignment_night'] == $site_name) {
        if ($is_on_leave) {
            $status_night = 'On Leave';
            $time_in_night_display = '--:--';
            $time_out_night_display = '--:--';
        } else {
            $status_night = $row['night_status'] ?? 'Present';
            $time_in_night_display = $formatTime($row['time_in_night']);
            $time_out_night_display = $formatTime($row['time_out_night']);
        }
        $night_assigned = true;
    } else {
        $status_night = 'No Record';
        $time_in_night_display = '--:--';
        $time_out_night_display = '--:--';
        $night_assigned = false;
    }
    
    // ============================================
    // DETERMINE OVERALL STATUS FOR THIS SITE
    // ============================================
    $has_present_at_this_site = false;
    $has_any_assignment = false;
    
    if ($am_assigned && $status_am == 'Present') $has_present_at_this_site = true;
    if ($pm_assigned && $status_pm == 'Present') $has_present_at_this_site = true;
    if ($night_assigned && $status_night == 'Present') $has_present_at_this_site = true;
    
    if ($am_assigned || $pm_assigned || $night_assigned) {
        $has_any_assignment = true;
    }
    
    // Count for site summary
    if ($is_on_leave) {
        $leave_count++;
        $overall_status = 'On Leave';
    } elseif ($has_present_at_this_site) {
        $present_count++;
        $overall_status = 'Present';
    } elseif ($has_any_assignment) {
        $absent_count++;
        $overall_status = 'Absent';
    } else {
        $absent_count++;
        $overall_status = 'No Record';
    }
    
    // Calculate total hours for THIS site (only hours from assigned sessions)
    $site_total_hours = 0;
    if ($am_assigned && !empty($row['time_in_am']) && !empty($row['time_out_am'])) {
        $site_total_hours += floatval($row['total_hours'] ?? 0);
    }
    if ($pm_assigned && !empty($row['time_in_pm']) && !empty($row['time_out_pm'])) {
        $site_total_hours += floatval($row['total_hours'] ?? 0);
    }
    if ($night_assigned && !empty($row['time_in_night']) && !empty($row['time_out_night'])) {
        $site_total_hours += floatval($row['total_hours'] ?? 0);
    }
    
    // Format full name with middle name
    $full_name = $row['first_name'];
    if (!empty($row['middle_name'])) {
        $full_name .= ' ' . substr($row['middle_name'], 0, 1) . '.';
    }
    $full_name .= ' ' . $row['last_name'];
    
    // Determine which session(s) they are assigned to this site
    $assigned_sessions = [];
    if ($am_assigned) $assigned_sessions[] = 'AM';
    if ($pm_assigned) $assigned_sessions[] = 'PM';
    if ($night_assigned) $assigned_sessions[] = 'Night';
    
    $employees[] = [
        'id' => $row['id'],
        'employee_id' => $row['id'],
        'full_name' => $full_name,
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'position' => $row['position'] ?? 'N/A',
        'status_am' => $status_am,
        'status_pm' => $status_pm,
        'status_night' => $status_night,
        'time_in_am' => $time_in_am_display,
        'time_out_am' => $time_out_am_display,
        'time_in_pm' => $time_in_pm_display,
        'time_out_pm' => $time_out_pm_display,
        'time_in_night' => $time_in_night_display,
        'time_out_night' => $time_out_night_display,
        'total_hours' => number_format($site_total_hours, 2),
        'remarks' => $row['remarks'] ?? '',
        'leave_type' => $row['leave_type'] ?? '',
        'workday_type' => $row['workday_type'] ?? '',
        'overall_status' => $overall_status,
        'assigned_sessions' => implode(', ', $assigned_sessions)
    ];
}

// Total employees count is simply the number of employees returned
$total_employees_count = count($employees);

$response = [
    'success' => true,
    'site_id' => $site_id,
    'site_name' => $site['site_name'] ?? 'Unknown',
    'site_manager' => $site['site_manager'] ?? 'N/A',
    'site_address' => $site['site_address'] ?? 'N/A',
    'date' => $date,
    'employees' => $employees,
    'summary' => [
        'total' => $total_employees_count,
        'present' => $present_count,
        'absent' => $absent_count,
        'leave' => $leave_count
    ],
    'debug' => [
        'employees_found' => count($employees),
        'date_used' => $date,
        'site_name_used' => $site_name
    ]
];

echo json_encode($response);

$site_stmt->close();
$stmt->close();
$conn->close();
?>