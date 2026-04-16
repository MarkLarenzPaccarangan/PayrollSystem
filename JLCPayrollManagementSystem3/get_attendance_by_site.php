<?php
session_start();
include_once("connection.php");

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Log for debugging
error_log("=== get_attendance_by_site.php ===");
error_log("Site ID: " . $site_id);
error_log("Date: " . $date);

if (!$site_id) {
    echo json_encode(['success' => false, 'message' => 'Site ID required']);
    exit;
}

try {
    // Get site details
    $site_query = "SELECT sm.*, so.assignment_type, so.person_group 
                   FROM site_monitoring sm 
                   LEFT JOIN site_others so ON sm.id = so.site_id 
                   WHERE sm.id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_id);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    $site = $site_result->fetch_assoc();
    
    if (!$site) {
        echo json_encode(['success' => false, 'message' => 'Site not found']);
        exit;
    }
    
    $site_name = $site['site_name'];
    error_log("Site Name: " . $site_name);
    
    // Get attendance records where employee was assigned to this site
    $attendance_query = "SELECT DISTINCT 
                                a.employee_id,
                                a.status,
                                a.pm_status,
                                a.night_status,
                                a.time_in_am,
                                a.time_out_am,
                                a.time_in_pm,
                                a.time_out_pm,
                                a.time_in_night,
                                a.time_out_night,
                                a.site_assignment_am,
                                a.site_assignment_pm,
                                a.site_assignment_night,
                                a.total_hours,
                                a.remarks,
                                a.leave_type,
                                e.first_name,
                                e.middle_name,
                                e.last_name,
                                e.position
                         FROM attendance a
                         INNER JOIN employees e ON a.employee_id = e.id
                         WHERE DATE(a.date) = DATE(?)
                         AND (
                             a.site_assignment_am = ? OR 
                             a.site_assignment_pm = ? OR 
                             a.site_assignment_night = ?
                         )
                         ORDER BY e.last_name, e.first_name";
    
    $att_stmt = $conn->prepare($attendance_query);
    $att_stmt->bind_param("ssss", $date, $site_name, $site_name, $site_name);
    $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    
    error_log("Query returned " . $att_result->num_rows . " rows");
    
    $employees = [];
    $total_hours_sum = 0;
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
    
    // Helper function to calculate hours
    $calculateHours = function($time_in, $time_out) {
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
    };
    
    while ($row = $att_result->fetch_assoc()) {
        error_log("Processing employee: " . $row['first_name'] . " " . $row['last_name']);
        error_log("  - site_assignment_am: " . $row['site_assignment_am']);
        error_log("  - site_assignment_pm: " . $row['site_assignment_pm']);
        error_log("  - site_assignment_night: " . $row['site_assignment_night']);
        
        // Check if employee is on leave globally (from leave_type column)
        $is_on_leave = !empty($row['leave_type']);
        
        // Format employee name
        $full_name = trim($row['first_name'] . ' ' . 
                        (!empty($row['middle_name']) ? substr($row['middle_name'], 0, 1) . '. ' : '') . 
                        $row['last_name']);
        
        // ============================================
        // FIXED: Determine status and times for EACH SESSION
        // based on whether THIS site was assigned to that session
        // ============================================
        
        // ----- AM SESSION -----
        if ($row['site_assignment_am'] == $site_name) {
            // This employee worked AM shift at THIS site
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
            // This employee did NOT work AM shift at THIS site
            $status_am = 'No Record';
            $time_in_am_display = '--:--';
            $time_out_am_display = '--:--';
            $am_assigned = false;
        }
        
        // ----- PM SESSION -----
        if ($row['site_assignment_pm'] == $site_name) {
            // This employee worked PM shift at THIS site
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
            // This employee did NOT work PM shift at THIS site
            $status_pm = 'No Record';
            $time_in_pm_display = '--:--';
            $time_out_pm_display = '--:--';
            $pm_assigned = false;
        }
        
        // ----- NIGHT SESSION -----
        if ($row['site_assignment_night'] == $site_name) {
            // This employee worked Night shift at THIS site
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
            // This employee did NOT work Night shift at THIS site
            $status_night = 'No Record';
            $time_in_night_display = '--:--';
            $time_out_night_display = '--:--';
            $night_assigned = false;
        }
        
        // ============================================
        // CALCULATE TOTAL HOURS FOR THIS SITE
        // Only sum hours from sessions assigned to THIS site
        // ============================================
        $site_hours = 0;
        
        if ($am_assigned && !empty($row['time_in_am']) && !empty($row['time_out_am'])) {
            $site_hours += $calculateHours($row['time_in_am'], $row['time_out_am']);
        }
        if ($pm_assigned && !empty($row['time_in_pm']) && !empty($row['time_out_pm'])) {
            $site_hours += $calculateHours($row['time_in_pm'], $row['time_out_pm']);
        }
        if ($night_assigned && !empty($row['time_in_night']) && !empty($row['time_out_night'])) {
            $site_hours += $calculateHours($row['time_in_night'], $row['time_out_night']);
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
        
        // Determine overall status for counting
        if ($is_on_leave) {
            $overall_status = 'On Leave';
            $leave_count++;
        } elseif ($has_present_at_this_site) {
            $overall_status = 'Present';
            $present_count++;
        } elseif ($has_any_assignment) {
            $overall_status = 'Absent';
            $absent_count++;
        } else {
            $overall_status = 'No Record';
            $absent_count++;
        }
        
        // Determine which session(s) they are assigned to this site
        $assigned_sessions = [];
        if ($am_assigned) $assigned_sessions[] = 'AM';
        if ($pm_assigned) $assigned_sessions[] = 'PM';
        if ($night_assigned) $assigned_sessions[] = 'Night';
        
        $total_hours_sum += $site_hours;
        
        $employees[] = [
            'employee_id' => $row['employee_id'],
            'employee_name' => $full_name,
            'position' => $row['position'] ?? 'N/A',
            'status' => $status_am,
            'pm_status' => $status_pm,
            'night_status' => $status_night,
            'overall_status' => $overall_status,
            'assigned_sessions' => $assigned_sessions,
            'time_in_am' => $time_in_am_display,
            'time_out_am' => $time_out_am_display,
            'time_in_pm' => $time_in_pm_display,
            'time_out_pm' => $time_out_pm_display,
            'time_in_night' => $time_in_night_display,
            'time_out_night' => $time_out_night_display,
            'site_assignment_am' => $row['site_assignment_am'],
            'site_assignment_pm' => $row['site_assignment_pm'],
            'site_assignment_night' => $row['site_assignment_night'],
            'total_hours' => number_format($site_hours, 2),
            'remarks' => $row['remarks'] ?? '-',
            'leave_type' => $row['leave_type'] ?? ''
        ];
    }
    
    $response = [
        'success' => true,
        'site' => $site,
        'employees' => $employees,
        'summary' => [
            'total' => count($employees),
            'present' => $present_count,
            'absent' => $absent_count,
            'leave' => $leave_count,
            'total_hours' => number_format($total_hours_sum, 2)
        ],
        'date' => $date,
        'debug' => [
            'site_name_searched' => $site_name,
            'employees_found' => count($employees)
        ]
    ];
    
    error_log("Response Summary: Total=" . count($employees) . ", Present=" . $present_count . ", Absent=" . $absent_count . ", Leave=" . $leave_count);
    echo json_encode($response);
    
    $att_stmt->close();
    $site_stmt->close();
    
} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>