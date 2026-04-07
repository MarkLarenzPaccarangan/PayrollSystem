<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Admin_User'])) {
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

include_once("connection.php");

$site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (!$site_id) {
    echo json_encode(['error' => 'Invalid site ID']);
    exit;
}

// Get site details
$site_query = "SELECT s.id, s.site_name, s.site_manager, s.site_address 
               FROM site_monitoring s 
               WHERE s.id = ?";
$site_stmt = $conn->prepare($site_query);
$site_stmt->bind_param("i", $site_id);
$site_stmt->execute();
$site_result = $site_stmt->get_result();
$site = $site_result->fetch_assoc();

if (!$site) {
    echo json_encode(['error' => 'Site not found']);
    exit;
}

// Get employees with attendance - FIXED: Added a.site to SELECT
$employees_query = "SELECT 
                    e.id,
                    e.first_name,
                    e.last_name,
                    e.position,
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
                    a.total_hours,
                    a.leave_type,
                    a.remarks
                FROM employees e
                INNER JOIN site_employee se ON e.id = se.employee_id
                LEFT JOIN attendance a ON e.id = a.employee_id AND a.date = ?
                WHERE se.site_id = ? AND se.status = 'active'
                AND e.status = 'active' AND e.is_active = 1
                ORDER BY e.first_name, e.last_name";

$emp_stmt = $conn->prepare($employees_query);
$emp_stmt->bind_param("si", $date, $site_id);
$emp_stmt->execute();
$emp_result = $emp_stmt->get_result();

$employees = [];
$present_count = 0;
$absent_count = 0;
$leave_count = 0;

while ($row = $emp_result->fetch_assoc()) {
    // Format times for display
    $time_in_am_display = (!empty($row['time_in_am']) && $row['time_in_am'] != '00:00:00') ? date('h:i A', strtotime($row['time_in_am'])) : '--:--';
    $time_out_am_display = (!empty($row['time_out_am']) && $row['time_out_am'] != '00:00:00') ? date('h:i A', strtotime($row['time_out_am'])) : '--:--';
    $time_in_pm_display = (!empty($row['time_in_pm']) && $row['time_in_pm'] != '00:00:00') ? date('h:i A', strtotime($row['time_in_pm'])) : '--:--';
    $time_out_pm_display = (!empty($row['time_out_pm']) && $row['time_out_pm'] != '00:00:00') ? date('h:i A', strtotime($row['time_out_pm'])) : '--:--';
    $time_in_night_display = (!empty($row['time_in_night']) && $row['time_in_night'] != '00:00:00') ? date('h:i A', strtotime($row['time_in_night'])) : '--:--';
    $time_out_night_display = (!empty($row['time_out_night']) && $row['time_out_night'] != '00:00:00') ? date('h:i A', strtotime($row['time_out_night'])) : '--:--';
    
    // Determine statuses
    $is_on_leave = !empty($row['leave_type']);
    
    if ($is_on_leave) {
        $status_am = 'On Leave';
        $status_pm = 'On Leave';
        $status_night = 'On Leave';
        $leave_count++;
    } else {
        $status_am = $row['status'] ?? 'Absent';
        $status_pm = $row['pm_status'] ?? 'Absent';
        $status_night = $row['night_status'] ?? 'Absent';
        
        if ($status_am === 'Present' || $status_pm === 'Present' || $status_night === 'Present') {
            $present_count++;
        } else {
            $absent_count++;
        }
    }
    
    $employees[] = [
        'id' => $row['id'],
        'full_name' => $row['first_name'] . ' ' . $row['last_name'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'position' => $row['position'] ?? '—',
        'status_am' => $status_am,
        'status_pm' => $status_pm,
        'status_night' => $status_night,
        'time_in_am' => $row['time_in_am'],
        'time_out_am' => $row['time_out_am'],
        'time_in_pm' => $row['time_in_pm'],
        'time_out_pm' => $row['time_out_pm'],
        'time_in_night' => $row['time_in_night'],
        'time_out_night' => $row['time_out_night'],
        'time_in_am_display' => $time_in_am_display,
        'time_out_am_display' => $time_out_am_display,
        'time_in_pm_display' => $time_in_pm_display,
        'time_out_pm_display' => $time_out_pm_display,
        'time_in_night_display' => $time_in_night_display,
        'time_out_night_display' => $time_out_night_display,
        'site' => $row['site'] ?? '—',  // ← THIS IS THE KEY FIX
        'total_hours' => floatval($row['total_hours'] ?? 0),
        'leave_type' => $row['leave_type'] ?? '',
        'remarks' => $row['remarks'] ?? '—'
    ];
}

echo json_encode([
    'success' => true,
    'site_name' => $site['site_name'],
    'site_manager' => $site['site_manager'] ?? 'N/A',
    'site_address' => $site['site_address'] ?? 'N/A',
    'date' => $date,
    'summary' => [
        'total' => count($employees),
        'present' => $present_count,
        'absent' => $absent_count,
        'leave' => $leave_count
    ],
    'employees' => $employees
]);

$conn->close();
?>