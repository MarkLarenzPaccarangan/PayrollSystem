<?php
// Start session
session_start();

// ===== DEBUG: I-print ang session para makita kung tama =====
error_log("=== HOME.PHP ACCESS ===");
error_log("User: " . ($_SESSION['username'] ?? 'unknown'));
error_log("Role from session: " . ($_SESSION['role'] ?? 'unknown'));
error_log("User Type: " . ($_SESSION['user_type'] ?? 'unknown'));

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// ============================================
// ROLE-BASED ACCESS CONTROL
// ============================================
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';

// Kung ang role ay 'admin' pero ang user_type ay 'user_account', may problema
if ($user_role === 'admin' && $user_type === 'user_account') {
    error_log("WARNING: User has admin role but user_account type - fixing...");
    // I-set sa tamang role
    $_SESSION['role'] = 'user';
    $user_role = 'user';
}

// Set role-based flags
$is_admin = ($user_role === 'admin' && $user_type === 'super_user');
$is_ceo = ($user_role === 'ceo');
$is_user = ($user_role === 'user');

// Log ang final role
error_log("Final role determination - is_admin: " . ($is_admin ? 'true' : 'false') . 
          ", is_ceo: " . ($is_ceo ? 'true' : 'false') . 
          ", is_user: " . ($is_user ? 'true' : 'false'));

// TURN ON ERROR DISPLAY - PARA MAKITA ANG ACTUAL ERROR
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include access control - with error handling
$access_control_loaded = false;
if (file_exists("check_access.php")) {
    include_once("check_access.php");
    $access_control_loaded = true;
} else {
    // Log that file is missing but continue
    error_log("check_access.php not found - continuing without access control");
}

// Check if user has access to this page (only if access control is loaded)
$current_page = basename($_SERVER['PHP_SELF']);
if ($access_control_loaded && function_exists('checkPageAccess') && !checkPageAccess($current_page)) {
    header("Location: home.php?error=access_denied");
    exit;
}
// If access control not loaded, proceed without checking (for now)

// Include database connection
include_once("connection.php");

// ============================================
// DASHBOARD FUNCTIONS
// ============================================

/**
 * Get sites with employee counts and attendance for a specific date - FIXED VERSION
 * Now properly queries from attendance table (site_assignment fields)
 */
function getSitesWithAttendance($conn, $filter_date = null, $search = '', $sort_by = 'site_name', $sort_order = 'ASC') {
    if (!$filter_date) {
        $filter_date = date('Y-m-d');
    }
    
    $valid_sort_columns = ['site_name', 'site_manager', 'site_address', 'id', 'total_employees', 'present_count', 'absent_count', 'leave_count', 'attendance_rate'];
    if (!in_array($sort_by, $valid_sort_columns)) {
        $sort_by = 'site_name';
    }
    $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
    
    // Get all sites
    $query = "SELECT 
                s.id,
                s.site_name,
                s.site_manager,
                s.site_address,
                s.is_others
              FROM site_monitoring s
              WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $query .= " AND (s.site_name LIKE ? OR s.site_manager LIKE ? OR s.site_address LIKE ?)";
        $search_term = '%' . $search . '%';
        $params = [$search_term, $search_term, $search_term];
        $types = "sss";
    }
    
    // Handle sorting
    switch ($sort_by) {
        case 'site_name':
            $query .= " ORDER BY s.site_name $sort_order";
            break;
        case 'site_manager':
            $query .= " ORDER BY s.site_manager $sort_order";
            break;
        case 'site_address':
            $query .= " ORDER BY s.site_address $sort_order";
            break;
        case 'id':
            $query .= " ORDER BY s.id $sort_order";
            break;
        default:
            $query .= " ORDER BY s.site_name $sort_order";
    }
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing query: " . $conn->error);
        return [];
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sites = [];
    while ($row = $result->fetch_assoc()) {
        $site_name = $row['site_name'];
        
        // FIXED: Get employee counts from ATTENDANCE table based on site assignments
        // Total employees count (distinct employees assigned to this site on this date)
        $total_query = "SELECT COUNT(DISTINCT a.employee_id) as total 
                        FROM attendance a
                        WHERE DATE(a.date) = DATE(?)
                        AND (a.site_assignment_am = ? OR a.site_assignment_pm = ? OR a.site_assignment_night = ?)";
        $total_stmt = $conn->prepare($total_query);
        if ($total_stmt) {
            $total_stmt->bind_param("ssss", $filter_date, $site_name, $site_name, $site_name);
            $total_stmt->execute();
            $total_result = $total_stmt->get_result();
            $total_row = $total_result->fetch_assoc();
            $row['total_employees'] = intval($total_row['total'] ?? 0);
            $total_stmt->close();
        } else {
            $row['total_employees'] = 0;
        }
        
        // Get present count (any session is Present)
        $present_query = "SELECT COUNT(DISTINCT a.employee_id) as present_count 
                          FROM attendance a
                          WHERE DATE(a.date) = DATE(?)
                          AND (a.site_assignment_am = ? OR a.site_assignment_pm = ? OR a.site_assignment_night = ?)
                          AND (a.status = 'Present' OR a.pm_status = 'Present' OR a.night_status = 'Present')";
        $present_stmt = $conn->prepare($present_query);
        if ($present_stmt) {
            $present_stmt->bind_param("ssss", $filter_date, $site_name, $site_name, $site_name);
            $present_stmt->execute();
            $present_result = $present_stmt->get_result();
            $present_row = $present_result->fetch_assoc();
            $row['present_count'] = intval($present_row['present_count'] ?? 0);
            $present_stmt->close();
        } else {
            $row['present_count'] = 0;
        }
        
        // Get leave count
        $leave_query = "SELECT COUNT(DISTINCT a.employee_id) as leave_count 
                        FROM attendance a
                        WHERE DATE(a.date) = DATE(?)
                        AND (a.site_assignment_am = ? OR a.site_assignment_pm = ? OR a.site_assignment_night = ?)
                        AND a.leave_type IS NOT NULL AND a.leave_type != ''";
        $leave_stmt = $conn->prepare($leave_query);
        if ($leave_stmt) {
            $leave_stmt->bind_param("ssss", $filter_date, $site_name, $site_name, $site_name);
            $leave_stmt->execute();
            $leave_result = $leave_stmt->get_result();
            $leave_row = $leave_result->fetch_assoc();
            $row['leave_count'] = intval($leave_row['leave_count'] ?? 0);
            $leave_stmt->close();
        } else {
            $row['leave_count'] = 0;
        }
        
        // Calculate absent count
        $row['absent_count'] = max(0, $row['total_employees'] - $row['present_count'] - $row['leave_count']);
        
        // Calculate attendance rate
        if ($row['total_employees'] > 0) {
            $row['attendance_rate'] = round(($row['present_count'] / $row['total_employees']) * 100);
        } else {
            $row['attendance_rate'] = 0;
        }
        
        // Get others details if needed
        if ($row['is_others'] == 1) {
            $others_query = "SELECT assignment_type, person_group FROM site_others WHERE site_id = ?";
            $others_stmt = $conn->prepare($others_query);
            if ($others_stmt) {
                $others_stmt->bind_param("i", $row['id']);
                $others_stmt->execute();
                $others_result = $others_stmt->get_result();
                if ($others_row = $others_result->fetch_assoc()) {
                    $row['assignment_type'] = $others_row['assignment_type'];
                    $row['person_group'] = $others_row['person_group'];
                }
                $others_stmt->close();
            }
        }
        $sites[] = $row;
    }
    
    // If sorting by calculated fields, sort in PHP
    if (in_array($sort_by, ['total_employees', 'present_count', 'absent_count', 'leave_count', 'attendance_rate'])) {
        usort($sites, function($a, $b) use ($sort_by, $sort_order) {
            $val_a = $a[$sort_by] ?? 0;
            $val_b = $b[$sort_by] ?? 0;
            if ($sort_order == 'ASC') {
                return $val_a - $val_b;
            } else {
                return $val_b - $val_a;
            }
        });
    }
    
    return $sites;
}

/**
 * Get available dates for filter dropdown
 */
function getAvailableDates($conn) {
    $query = "SELECT DISTINCT date 
              FROM attendance 
              ORDER BY date DESC 
              LIMIT 30";
    $result = $conn->query($query);
    
    $dates = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dates[] = $row['date'];
        }
    }
    
    return $dates;
}

/**
 * Get total statistics across all sites - FIXED to use attendance table
 */
function getTotalStats($conn, $filter_date = null) {
    if (!$filter_date) {
        $filter_date = date('Y-m-d');
    }
    
    // Get total sites
    $sites_query = "SELECT COUNT(*) as total_sites FROM site_monitoring";
    $sites_result = $conn->query($sites_query);
    $sites_row = $sites_result->fetch_assoc();
    
    // FIXED: Get total employees from attendance table (distinct employees with site assignments on this date)
    $total_emp_query = "SELECT COUNT(DISTINCT a.employee_id) as total 
                        FROM attendance a
                        WHERE DATE(a.date) = DATE(?)
                        AND (a.site_assignment_am IS NOT NULL OR a.site_assignment_pm IS NOT NULL OR a.site_assignment_night IS NOT NULL)";
    $total_emp_stmt = $conn->prepare($total_emp_query);
    $total_emp_stmt->bind_param("s", $filter_date);
    $total_emp_stmt->execute();
    $total_emp_result = $total_emp_stmt->get_result();
    $total_emp_row = $total_emp_result->fetch_assoc();
    $total_employees = intval($total_emp_row['total'] ?? 0);
    $total_emp_stmt->close();
    
    // Get present count
    $present_query = "SELECT COUNT(DISTINCT a.employee_id) as total 
                      FROM attendance a
                      WHERE DATE(a.date) = DATE(?)
                      AND (a.status = 'Present' OR a.pm_status = 'Present' OR a.night_status = 'Present')";
    $present_stmt = $conn->prepare($present_query);
    $present_stmt->bind_param("s", $filter_date);
    $present_stmt->execute();
    $present_result = $present_stmt->get_result();
    $present_row = $present_result->fetch_assoc();
    $total_present = intval($present_row['total'] ?? 0);
    $present_stmt->close();
    
    // Get leave count
    $leave_query = "SELECT COUNT(DISTINCT a.employee_id) as total 
                    FROM attendance a
                    WHERE DATE(a.date) = DATE(?)
                    AND a.leave_type IS NOT NULL AND a.leave_type != ''";
    $leave_stmt = $conn->prepare($leave_query);
    $leave_stmt->bind_param("s", $filter_date);
    $leave_stmt->execute();
    $leave_result = $leave_stmt->get_result();
    $leave_row = $leave_result->fetch_assoc();
    $total_leave = intval($leave_row['total'] ?? 0);
    $leave_stmt->close();
    
    // Calculate absent
    $total_absent = max(0, $total_employees - $total_present - $total_leave);
    
    return [
        'total_sites' => $sites_row['total_sites'] ?? 0,
        'total_employees' => $total_employees,
        'total_present' => $total_present,
        'total_leave' => $total_leave,
        'total_absent' => $total_absent
    ];
}
/**
 * Get employees with attendance status for a specific site and date - FIXED with night session
 * Now correctly shows On Leave status when leave_type exists
 */
function getSiteEmployeesWithAttendance($conn, $site_id, $filter_date = null) {
    if (!$filter_date) {
        $filter_date = date('Y-m-d');
    }
    
    $employees = [];
    
    $query = "SELECT 
                e.id,
                e.first_name,
                e.last_name,
                e.position,
                e.email,
                se.assigned_date,
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
                a.total_hours,
                a.leave_type
              FROM employees e
              INNER JOIN site_employee se ON e.id = se.employee_id
              LEFT JOIN attendance a ON e.id = a.employee_id AND a.date = ?
              WHERE se.site_id = ? AND se.status = 'active'
              AND e.status = 'active' AND e.is_active = 1
              ORDER BY e.first_name, e.last_name";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing query: " . $conn->error);
        return $employees;
    }
    
    $stmt->bind_param("si", $filter_date, $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // FIXED: Check if employee is on leave based on leave_type column
        $is_on_leave = !empty($row['leave_type']);
        
        // Set default values if NULL
        // For on leave employees, override status to "On Leave" for display
        if ($is_on_leave) {
            $row['status_am'] = 'On Leave';
            $row['status_pm'] = 'On Leave';
            $row['status_night'] = 'On Leave';
        } else {
            $row['status_am'] = $row['status'] ?? 'Absent';
            $row['status_pm'] = $row['pm_status'] ?? 'Absent';
            $row['status_night'] = $row['night_status'] ?? 'Absent';
        }
        
        $row['time_in_am'] = $row['time_in_am'] ?? null;
        $row['time_out_am'] = $row['time_out_am'] ?? null;
        $row['time_in_pm'] = $row['time_in_pm'] ?? null;
        $row['time_out_pm'] = $row['time_out_pm'] ?? null;
        $row['time_in_night'] = $row['time_in_night'] ?? null;
        $row['time_out_night'] = $row['time_out_night'] ?? null;
        $row['total_hours'] = $row['total_hours'] ?? 0.00;
        $row['leave_type'] = $row['leave_type'] ?? '';
        
        $employees[] = $row;
    }
    
    return $employees;
}

// ============================================
// GET DASHBOARD DATA
// ============================================

// Get filter date from URL parameter or use today
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_date)) {
    $filter_date = date('Y-m-d');
}

// Get search and sort parameters
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'site_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Get site ID for employee view modal
$view_site_id = isset($_GET['view_site']) ? (int)$_GET['view_site'] : 0;
$view_employees = [];
$view_site_details = null;

if ($view_site_id > 0) {
    // Get site details
    $site_query = "SELECT s.*, so.assignment_type, so.person_group 
                   FROM site_monitoring s 
                   LEFT JOIN site_others so ON s.id = so.site_id 
                   WHERE s.id = ?";
    
    $stmt = $conn->prepare($site_query);
    $stmt->bind_param("i", $view_site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $view_site_details = $result->fetch_assoc();
    
    // Get employees with attendance
    $view_employees = getSiteEmployeesWithAttendance($conn, $view_site_id, $filter_date);
}

// Fetch sites data with search and sort
$sites = getSitesWithAttendance($conn, $filter_date, $search_query, $sort_by, $sort_order);

// Fetch total statistics
$total_stats = getTotalStats($conn, $filter_date);

// Fetch available dates for filter
$available_dates = getAvailableDates($conn);

// Format display date
$display_date = date('m/d/Y', strtotime($filter_date));
$month = date('m', strtotime($filter_date));
$year = date('Y', strtotime($filter_date));
$day = date('d', strtotime($filter_date));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Site Monitoring</title>
    <link rel="stylesheet" href="./assets/css/home2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* EXISTING STYLES - WALANG BINAGO */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            overflow: hidden;
            background-color: #f0f2f5;
            position: relative;
        }

        /* Main Content Area - IMPROVED SCROLLING */
        .content {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        .main-content {
            flex: 1;
            padding: 20px 40px 0 40px;
            width: 100%;
            height: 100%;
            overflow-y: scroll !important;
            overflow-x: hidden;
            background-color: transparent;
            color: #333;
            position: relative;
            display: flex;
            justify-content: center;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
        }

        /* Custom Scrollbar - Enhanced */
        .main-content::-webkit-scrollbar {
            width: 12px;
        }

        .main-content::-webkit-scrollbar-track {
            background: #e9ecef;
            border-radius: 10px;
        }

        .main-content::-webkit-scrollbar-thumb {
            background: #2E7D32;
            border-radius: 10px;
            border: 3px solid #e9ecef;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: #1B5E20;
        }

        /* Firefox scrollbar */
        .main-content {
            scrollbar-width: thin;
            scrollbar-color: #2E7D32 #e9ecef;
        }

        /* Dashboard Container */
        .dashboard-container {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto 0 auto;
            padding: 0 20px 50px 20px;
            min-height: calc(100vh - 150px);
        }

        /* Dashboard Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            background: white;
            padding: 20px 30px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            width: 100%;
            border: 1px solid #e9ecef;
        }

        .dashboard-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dashboard-title i {
            color: #2E7D32;
            font-size: 26px;
        }

        /* Date Filter Styles */
        .date-filter {
            background: #f8fafc;
            padding: 12px 20px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .date-filter label {
            font-weight: 600;
            color: #475569;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .date-filter label i {
            color: #046e04;
        }

        .main-date-picker-wrapper {
            position: relative;
        }

        .main-date-picker-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            padding: 8px 16px 8px 12px;
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            height: 38px;
            min-width: 160px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .main-date-picker-btn:hover {
            border-color: #75e6da;
            background-color: #f8fafc;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .main-date-picker-btn:focus {
            outline: none;
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        }

        .main-date-picker-btn i:first-child {
            color: #2E7D32;
            font-size: 1rem;
        }

        .main-date-picker-btn i:last-child {
            color: #64748b;
            font-size: 0.8rem;
            margin-left: auto;
        }

        .main-date-picker-btn span {
            flex: 1;
            text-align: left;
            font-weight: 600;
        }
        
        .main-calendar-wrapper {
            position: absolute;
            top: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            display: none;
            width: 300px;
        }
        
        .main-calendar-wrapper.show {
            display: block;
        }
        
        .main-calendar-box {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .main-calendar-header {
            background: linear-gradient(135deg, #75e6da, #62d4c8);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .main-calendar-month-year {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .main-calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .main-calendar-nav-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1.1rem;
            line-height: 1;
        }
        
        .main-calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .main-calendar-selectors {
            display: flex;
            gap: 8px;
            padding: 12px 12px 5px 12px;
            background: white;
        }
        
        .main-calendar-select {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }
        
        .main-calendar-select:hover {
            border-color: #75e6da;
        }
        
        .main-calendar-select:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 2px rgba(117, 230, 218, 0.2);
        }
        
        .main-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 10px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 0.75rem;
            color: #2c3e50;
            border-bottom: 1px solid #e9ecef;
        }
        
        .main-calendar-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 8px;
            background: white;
        }
        
        .main-calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 0.85rem;
            color: #2c3e50;
            text-decoration: none;
            border: none;
            background: none;
            width: 100%;
            height: 100%;
            min-width: 30px;
            min-height: 30px;
        }
        
        .main-calendar-day:hover {
            background: #e8f5e9;
            color: #2E7D32;
        }
        
        .main-calendar-day.selected {
            background: #75e6da;
            color: white;
            font-weight: 600;
        }
        
        .main-calendar-day.today {
            border: 2px solid #75e6da;
            font-weight: 600;
        }
        
        .main-calendar-day.weekend {
            color: #e74c3c;
        }
        
        .main-calendar-day.other-month {
            color: #bdc3c7;
        }
        
        .main-calendar-footer {
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
        }
        
        .main-calendar-action-btn {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
        }
        
        .main-calendar-action-btn.clear {
            background: #f8f9fa;
            color: #7f8c8d;
            border: 1px solid #bdc3c7;
        }
        
        .main-calendar-action-btn.clear:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }
        
        .main-calendar-action-btn.today {
            background: #75e6da;
            color: white;
            border: 1px solid #75e6da;
        }
        
        .main-calendar-action-btn.today:hover {
            background: #62d4c8;
        }

        .quick-select {
            padding: 8px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 30px;
            background: white;
            font-size: 14px;
            cursor: pointer;
            min-width: 120px;
        }

        /* Statistics Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-color: #4CAF50;
        }

        .stat-info h3 {
            margin: 0;
            font-size: 14px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-info .number {
            font-size: 32px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 6px;
            line-height: 1;
        }

        .stat-icon {
            font-size: 42px;
            color: #2E7D32;
            opacity: 0.8;
        }

        /* Sites Container */
        .sites-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 25px;
            margin-top: 20px;
            width: 100%;
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }

        .sites-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 15px;
        }

        .sites-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .sites-header h2 i {
            color: #2E7D32;
        }

        .sites-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .site-search-container {
            display: flex;
            align-items: center;
            border: 2px solid #2E7D32;
            border-radius: 25px;
            padding: 3px 3px 3px 15px;
            background-color: white;
            transition: all 0.3s ease;
            min-width: 250px;
        }

        .site-search-container:focus-within {
            border-color: #2E7D32;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .site-search-bar {
            border: none;
            outline: none;
            width: 100%;
            font-size: 0.9rem;
            background-color: transparent;
            padding: 8px 5px;
        }

        .site-search-btn {
            background: #2E7D32;
            border: none;
            color: white;
            font-size: 1rem;
            cursor: pointer;
            padding: 8px 16px;
            border-radius: 25px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .site-search-btn:hover {
            background: #62d4c8;
        }

        .site-sort-container {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f5f5f5;
            padding: 5px 12px;
            border-radius: 25px;
            border: 1px solid #e0e0e0;
        }

        .site-sort-select {
            padding: 6px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 0.85rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }

        .site-sort-select:focus {
            border-color: #75e6da;
        }

        .site-sort-btn {
            padding: 6px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            background: white;
            color: #666;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }

        .site-sort-btn:hover {
            background: #f0f9f8;
            border-color: #75e6da;
            color: #2E7D32;
        }

        .clear-search-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 24px;
            background: #f1f5f9;
            color: #334155;
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .clear-search-btn:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .clear-search-btn i {
            margin-right: 5px;
        }

        .sites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            width: 100%;
            margin-bottom: 30px;
        }

        .site-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .site-card:hover {
            border-color: #4CAF50;
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .site-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .site-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .site-header h3 i {
            color: #2E7D32;
            font-size: 18px;
        }

        .site-header p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .site-content {
            padding: 20px;
            flex: 1;
        }

        .employee-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 12px;
            border-radius: 12px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .stat-value.present {
            color: #059669;
        }

        .stat-value.absent {
            color: #dc2626;
        }
        
        .stat-value.leave {
            color: #f59e0b;
        }

        .progress-section {
            margin-bottom: 20px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .progress-label {
            color: #64748b;
            font-weight: 500;
        }

        .progress-percent {
            font-weight: 700;
            color: #2E7D32;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background-color: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #2E7D32);
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .site-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e9ecef;
        }

        .view-details-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            color: #334155;
            padding: 6px 14px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
        }

        .view-details-btn:hover {
            background: #2E7D32;
            color: white;
        }

        .no-data {
            text-align: center;
            padding: 50px 20px;
            background: white;
            border-radius: 16px;
            color: #64748b;
        }

        .no-data i {
            font-size: 48px;
            color: #94a3b8;
            margin-bottom: 16px;
        }

        .no-data p {
            font-size: 16px;
            margin-bottom: 8px;
        }

        .no-data .sub-text {
            font-size: 14px;
            color: #94a3b8;
        }

        /* MODAL STYLES */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 10000;
            padding: 20px;
            overflow-y: auto;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 1400px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: #75e6da;
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px 16px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 15px 25px;
            background-color: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-top: 1px solid #e9ecef;
            border-radius: 0 0 16px 16px;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #75e6da;
            color: white;
        }

        .btn-primary:hover {
            background: #62d4c8;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .site-info-modal {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }

        .site-info-modal p {
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #475569;
        }

        .site-info-modal i {
            color: #75e6da;
            width: 20px;
        }

        /* Table Styles */
        .employees-table-container {
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            width: 100%;
        }

        .employees-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            min-width: 1200px;
        }

        .employees-table thead tr {
            background: linear-gradient(135deg, #75e6da 0%, #75e6da 100%);
        }

        .employees-table th {
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px;
            border: none;
            white-space: nowrap;
            text-align: center;
        }

        .employees-table th i {
            margin-right: 6px;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .employees-table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #e9ecef;
        }

        .employees-table tbody tr:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .employees-table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        /* Status Badge Styles */
        .status-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            white-space: nowrap;
        }

        .status-present {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #2E7D32;
            border: 1px solid #a5d6a7;
        }

        .status-absent {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
        
        .status-leave {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Time Badge Styles */
        .time-badge {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            border: 1px solid #e9ecef;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            line-height: 1.4;
        }

        .time-badge i {
            color: #2E7D32;
            font-size: 0.8rem;
            margin-right: 4px;
        }
        
        .time-row {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        
        .time-label {
            font-weight: 600;
            color: #2E7D32;
            min-width: 45px;
        }
        
        .time-value {
            color: #334155;
        }

        /* Total Hours column - tight fit with larger font */
        .employees-table td:nth-child(7) {
            width: 1%;
            white-space: nowrap;
        }
        
        .employees-table td:nth-child(7) .time-badge {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border: 1px solid #a5d6a7;
            font-weight: 700;
            color: #2E7D32;
            display: inline-block;
            white-space: nowrap;
            padding: 2px 8px;
            font-size: 0.85rem;
            border-radius: 12px;
        }

        /* Remarks column - tight fit */
        .employees-table td:last-child {
            width: 1%;
            white-space: nowrap;
        }
        
        .employees-table td:last-child .time-badge {
            background: #fff3e0;
            border-color: #ffe0b2;
            white-space: nowrap;
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7rem;
            border-radius: 12px;
        }

        .employees-table td:last-child .time-badge i {
            color: #f57c00;
        }

        /* Site Info Enhancement */
        .site-info-modal {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid #75e6da;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .site-info-modal p {
            margin: 10px 0;
            font-size: 0.95rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .site-info-modal i {
            color: #75e6da;
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        /* Summary stats row - aligned to left */
        .summary-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
            justify-content: flex-start;
        }

        .summary-stat {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
        }

        .summary-stat.present {
            color: #059669;
        }

        .summary-stat.absent {
            color: #dc2626;
        }

        .summary-stat.leave {
            color: #f59e0b;
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #2E7D32;
        }

        .loading i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #75e6da;
        }

        .no-employees {
            text-align: center;
            padding: 50px;
            color: #64748b;
        }

        .no-employees i {
            font-size: 4rem;
            color: #75e6da;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .employees-table th,
            .employees-table td {
                padding: 12px 10px;
                font-size: 0.8rem;
            }
            
            .employees-table th i {
                display: none;
            }
            
            .status-badge {
                padding: 4px 10px;
                font-size: 0.7rem;
            }
            
            .time-badge {
                padding: 4px 8px;
                font-size: 0.7rem;
            }
            
            .site-info-modal p {
                font-size: 0.85rem;
                gap: 8px;
            }
            
            .main-date-picker-btn {
                min-width: 140px;
                padding: 6px 12px;
                font-size: 0.85rem;
            }
            
            .modal-content {
                max-width: 95%;
            }
        }

        @media (max-width: 576px) {
            .employees-table th,
            .employees-table td {
                padding: 8px;
            }
        }
        
        /* Role-based access */
        .access-denied {
            text-align: center;
            padding: 50px 20px;
            background: white;
            border-radius: 16px;
            margin: 20px 0;
        }
        
        .access-denied i {
            font-size: 60px;
            color: #dc2626;
            margin-bottom: 20px;
        }
        
        .access-denied h3 {
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .access-denied p {
            color: #64748b;
        }
        
        <?php if ($is_user): ?>
        .admin-only,
        .ceo-only {
            display: none !important;
        }
        <?php elseif ($is_ceo): ?>
        .admin-only {
            display: none !important;
        }
        <?php endif; ?>
        
        .admin-only {
            display: none !important;
        }
        
        .debug-scroll {
            text-align: center;
            padding: 10px;
            color: #64748b;
            font-size: 12px;
            border-top: 1px dashed #75e6da;
            margin-top: 30px;
        }

        /* MODAL EMPLOYEE TABLE - MINIMIZED FONT SIZES & CENTERED */
        .modal .employees-table {
            font-size: 0.7rem;
        }
        
        .modal .employees-table th {
            font-size: 0.7rem;
            padding: 8px 6px;
            text-align: center;
        }
        
        .modal .employees-table td {
            font-size: 0.7rem;
            padding: 8px 6px;
            text-align: center;
        }
        
        .modal .employees-table .status-badge {
            font-size: 0.65rem;
            padding: 3px 8px;
            white-space: nowrap;
            display: inline-flex;
        }
        
        .modal .employees-table .time-badge {
            font-size: 0.65rem;
            padding: 4px 8px;
            white-space: normal;
            min-width: 170px;
            display: inline-flex;
        }
        
        .modal .employees-table .time-row {
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            flex-wrap: nowrap;
            justify-content: center;
        }
        
        .modal .employees-table .time-label {
            font-weight: 600;
            color: #2E7D32;
            min-width: 42px;
            font-size: 0.65rem;
        }
        
        .modal .employees-table .time-value {
            font-size: 0.65rem;
        }
        
        /* Total Hours column - tight fit with larger font in modal */
        .modal .employees-table td:nth-child(7) {
            width: 1%;
            white-space: nowrap;
            text-align: center;
        }
        
        .modal .employees-table td:nth-child(7) .time-badge {
            font-size: 0.8rem;
            padding: 2px 6px;
            display: inline-block;
            white-space: nowrap;
            border-radius: 10px;
        }
        
        /* Remarks column - tight fit in modal */
        .modal .employees-table td:last-child {
            width: 1%;
            white-space: nowrap;
            text-align: center;
        }
        
        .modal .employees-table td:last-child .time-badge {
            font-size: 0.65rem;
            display: inline-block;
            white-space: nowrap;
            max-width: none;
            padding: 2px 6px;
            border-radius: 10px;
        }
        
        /* Employee name column - remove ID display */
        .modal .employees-table td:first-child div {
            display: none;
        }
    </style>
    
</head>
<body>

    <!-- header -->
    <?php include_once("./includes/header.php"); ?>

    <main class="content">
        <div class="main-content">
            <div class="dashboard-container">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div class="dashboard-title">
                        <i class="fas fa-chart-line"></i>
                        Dashboard
                    </div>
                    
                    <div class="date-filter">
                        <label>
                            <i class="fas fa-calendar-alt"></i>
                            Date:
                        </label>
                        
                        <div class="main-date-picker-wrapper">
                            <button type="button" class="main-date-picker-btn" onclick="toggleMainCalendar()">
                                <span id="mainDateDisplay"><?= $display_date ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <input type="hidden" id="selectedDate" name="date" value="<?= $filter_date ?>">
                            
                            <div class="main-calendar-wrapper" id="mainCalendarWrapper">
                                <div class="main-calendar-box">
                                    <div class="main-calendar-header">
                                        <div class="main-calendar-month-year" id="mainCalendarMonthYear">
                                            <?= date('F Y', strtotime($filter_date)) ?>
                                        </div>
                                        <div class="main-calendar-nav">
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMainMonth(-1)">‹</button>
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMainMonth(1)">›</button>
                                        </div>
                                    </div>
                                    
                                    <div class="main-calendar-selectors">
                                        <select id="mainMonthSelect" class="main-calendar-select" onchange="changeMainMonthYear()">
                                            <option value="0">January</option>
                                            <option value="1">February</option>
                                            <option value="2">March</option>
                                            <option value="3">April</option>
                                            <option value="4">May</option>
                                            <option value="5">June</option>
                                            <option value="6">July</option>
                                            <option value="7">August</option>
                                            <option value="8">September</option>
                                            <option value="9">October</option>
                                            <option value="10">November</option>
                                            <option value="11">December</option>
                                        </select>
                                        
                                        <select id="mainYearSelect" class="main-calendar-select" onchange="changeMainMonthYear()">
                                            <?php for($y = date('Y') - 10; $y <= date('Y') + 10; $y++): ?>
                                                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="main-calendar-weekdays">
                                        <div>Sun</div>
                                        <div>Mon</div>
                                        <div>Tue</div>
                                        <div>Wed</div>
                                        <div>Thu</div>
                                        <div>Fri</div>
                                        <div>Sat</div>
                                    </div>
                                    
                                    <div class="main-calendar-days-grid" id="mainCalendarDaysGrid">
                                    </div>
                                    
                                    <div class="main-calendar-footer">
                                        <button type="button" class="main-calendar-action-btn clear" onclick="clearMainDate()">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <button type="button" class="main-calendar-action-btn today" onclick="setMainToday()">
                                            <i class="fas fa-calendar-check"></i> Today
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($available_dates)): ?>
                        <select id="quick_date" class="quick-select" onchange="quickDateSelect(this.value)">
                            <option value="">Quick Select</option>
                            <?php foreach ($available_dates as $date): ?>
                            <option value="<?php echo htmlspecialchars($date); ?>" <?php echo $date == $filter_date ? 'selected' : ''; ?>>
                                <?php echo date('M d, Y', strtotime($date)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Sites</h3>
                            <div class="number"><?php echo number_format($total_stats['total_sites'] ?? 0); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Employees</h3>
                            <div class="number"><?php echo number_format($total_stats['total_employees'] ?? 0); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Present</h3>
                            <div class="number present">
                                <?php echo number_format($total_stats['total_present'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Absent</h3>
                            <div class="number absent">
                                <?php echo number_format($total_stats['total_absent'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="stat-icon absent">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>On Leave</h3>
                            <div class="number leave">
                                <?php echo number_format($total_stats['total_leave'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="stat-icon leave">
                            <i class="fas fa-umbrella-beach"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Attendance Rate</h3>
                            <div class="number">
                                <?php 
                                $total_emp = $total_stats['total_employees'] ?? 0;
                                $total_present = $total_stats['total_present'] ?? 0;
                                $rate = $total_emp > 0 ? round(($total_present / $total_emp) * 100) : 0;
                                echo $rate . '%';
                                ?>
                            </div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                    </div>
                </div>

                <!-- Sites Dashboard Section -->
                <div class="sites-container">
                    <div class="sites-header">
                        <h2>
                            <i class="fas fa-map-marked-alt"></i>
                            Site Status Overview
                        </h2>
                        <div class="sites-controls">
                            <?php if (!$is_user): ?>
                            <div class="site-search-container">
                                <form method="GET" action="home.php" id="searchForm" style="display: flex; align-items: center; width: 100%;">
                                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                    <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
                                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                                    <input type="text" name="search" 
                                           value="<?php echo htmlspecialchars($search_query); ?>" 
                                           placeholder="Search sites..." 
                                           class="site-search-bar">
                                    <button type="submit" class="site-search-btn">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </form>
                            </div>
                            
                            <div class="site-sort-container">
                                <form method="GET" action="home.php" id="sortForm" style="display: flex; align-items: center; gap: 8px;">
                                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                                    <select name="sort_by" class="site-sort-select" onchange="document.getElementById('sortForm').submit();">
                                        <option value="site_name" <?php echo $sort_by == 'site_name' ? 'selected' : ''; ?>>Site Name</option>
                                        <option value="site_manager" <?php echo $sort_by == 'site_manager' ? 'selected' : ''; ?>>Manager</option>
                                        <option value="site_address" <?php echo $sort_by == 'site_address' ? 'selected' : ''; ?>>Address</option>
                                        <option value="total_employees" <?php echo $sort_by == 'total_employees' ? 'selected' : ''; ?>>Total Employees</option>
                                        <option value="present_count" <?php echo $sort_by == 'present_count' ? 'selected' : ''; ?>>Present</option>
                                        <option value="absent_count" <?php echo $sort_by == 'absent_count' ? 'selected' : ''; ?>>Absent</option>
                                        <option value="leave_count" <?php echo $sort_by == 'leave_count' ? 'selected' : ''; ?>>On Leave</option>
                                        <option value="attendance_rate" <?php echo $sort_by == 'attendance_rate' ? 'selected' : ''; ?>>Attendance Rate</option>
                                    </select>
                                    <button type="submit" name="sort_order" value="<?php echo $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>" class="site-sort-btn">
                                        <i class="fas fa-sort-amount-<?php echo $sort_order == 'ASC' ? 'down' : 'up'; ?>"></i>
                                        <?php echo $sort_order == 'ASC' ? 'Asc' : 'Desc'; ?>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <div style="color: #64748b; font-size: 14px;">
                                <i class="fas fa-info-circle"></i> Viewing all sites
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($sites)): ?>
                        <div class="no-data">
                            <i class="fas fa-map-marked-alt"></i>
                            <p>No sites found</p>
                            <div class="sub-text">
                                <?php if (!empty($search_query)): ?>
                                    No sites match your search criteria.
                                <?php elseif (empty($total_stats['total_sites'])): ?>
                                    Please add sites in the Site Monitoring page.
                                <?php else: ?>
                                    No employees assigned to any site yet.
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($search_query)): ?>
                            <a href="home.php?date=<?php echo urlencode($filter_date); ?>" class="clear-search-btn">
                                <i class="fas fa-times"></i> Clear Search
                            </a>
                            <?php else: ?>
                            <?php if (!$is_user): ?>
                            <a href="site_monitoring.php" style="display: inline-block; margin-top: 20px; padding: 10px 24px; background: #2E7D32; color: white; text-decoration: none; border-radius: 30px; font-weight: 600;">
                                <i class="fas fa-plus-circle"></i> Manage Sites
                            </a>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="sites-grid">
                            <?php foreach ($sites as $site): ?>
                                <?php 
                                $total_employees = intval($site['total_employees'] ?? 0);
                                $present_count = intval($site['present_count'] ?? 0);
                                $leave_count = intval($site['leave_count'] ?? 0);
                                $absent_count = intval($site['absent_count'] ?? 0);
                                $attendance_rate = intval($site['attendance_rate'] ?? 0);
                                $display_name = isset($site['is_others']) && $site['is_others'] == 1 && !empty($site['assignment_type']) 
                                    ? $site['assignment_type'] 
                                    : $site['site_name'];
                                ?>
                                <div class="site-card">
                                    <div class="site-header">
                                        <h3>
                                            <?php if (isset($site['is_others']) && $site['is_others'] == 1): ?>
                                                <i class="fas fa-tasks"></i>
                                            <?php else: ?>
                                                <i class="fas fa-building"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($display_name); ?>
                                            <?php if (isset($site['is_others']) && $site['is_others'] == 1): ?>
                                                <span style="font-size: 11px; background: #e8f5e9; color: #2E7D32; padding: 3px 8px; border-radius: 12px; margin-left: 8px;">
                                                    Others
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p>
                                            <i class="fas fa-user-tie"></i>
                                            <?php echo htmlspecialchars($site['site_manager'] ?? 'No manager'); ?>
                                        </p>
                                        <p>
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($site['site_address'] ?? 'No address'); ?>
                                        </p>
                                        <?php if (isset($site['is_others']) && $site['is_others'] == 1 && !empty($site['person_group'])): ?>
                                        <p style="color: #2E7D32;">
                                            <i class="fas fa-users"></i>
                                            Group: <?php echo htmlspecialchars($site['person_group']); ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="site-content">
                                        <div class="employee-stats">
                                            <div class="stat-item">
                                                <div class="stat-label">Total</div>
                                                <div class="stat-value"><?php echo $total_employees; ?></div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-label">Present</div>
                                                <div class="stat-value present"><?php echo $present_count; ?></div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-label">Absent</div>
                                                <div class="stat-value absent"><?php echo $absent_count; ?></div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-label">On Leave</div>
                                                <div class="stat-value leave"><?php echo $leave_count; ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-section">
                                            <div class="progress-header">
                                                <span class="progress-label">Attendance Rate</span>
                                                <span class="progress-percent"><?php echo $attendance_rate; ?>%</span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $attendance_rate; ?>%;"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="site-footer">
                                            <span style="color: #64748b; font-size: 12px;">
                                                <i class="fas fa-users"></i>
                                                <?php echo $total_employees; ?> assigned
                                            </span>
                                            <button onclick="viewSiteEmployees(<?php echo $site['id']; ?>)" class="view-details-btn">
                                                <i class="fas fa-eye"></i>
                                                More Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="debug-scroll">
                            <i class="fas fa-arrow-down"></i> End of content <i class="fas fa-arrow-down"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Site Employees Modal -->
    <div id="siteEmployeesModal" class="modal">
        <div class="modal-content" style="max-width: 1400px;">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-users"></i>
                    Site Employees
                </h3>
                <button class="modal-close" onclick="closeEmployeesModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="siteInfoContainer" class="site-info-modal">
                </div>
                <div id="employeesListModalContainer">
                    <div class="loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading employees...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEmployeesModal()">
                    <i class="fas fa-times"></i>
                    Close
                </button>
                <?php if (!$is_user): ?>
                <a href="site_monitoring.php" class="btn btn-primary">
                    <i class="fas fa-cog"></i>
                    Manage Sites
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- footer -->
    <?php include_once("./includes/footer.php"); ?>
    
    <!-- modal -->
    <?php include_once("./modal/logout-modal.php"); ?>

    <script>
        // ============================================
        // DATE FILTER CALENDAR FUNCTIONS
        // ============================================
        let mainCurrentDate = new Date('<?= $filter_date ?>');
        let mainSelectedDate = '<?= $filter_date ?>';

        function toggleMainCalendar() {
            const calendar = document.getElementById('mainCalendarWrapper');
            if (calendar) {
                if (calendar.style.display === 'block') {
                    calendar.style.display = 'none';
                } else {
                    updateMainCalendarSelectors();
                    generateMainCalendarDays();
                    calendar.style.display = 'block';
                }
            }
        }

        function updateMainCalendarSelectors() {
            const monthSelect = document.getElementById('mainMonthSelect');
            const yearSelect = document.getElementById('mainYearSelect');
            
            if (monthSelect) {
                monthSelect.value = mainCurrentDate.getMonth();
            }
            if (yearSelect) {
                yearSelect.value = mainCurrentDate.getFullYear();
            }
        }

        function changeMainMonthYear() {
            const monthSelect = document.getElementById('mainMonthSelect');
            const yearSelect = document.getElementById('mainYearSelect');
            
            if (monthSelect && yearSelect) {
                const newMonth = parseInt(monthSelect.value);
                const newYear = parseInt(yearSelect.value);
                
                mainCurrentDate = new Date(newYear, newMonth, 1);
                generateMainCalendarDays();
            }
        }

        function generateMainCalendarDays() {
            const year = mainCurrentDate.getFullYear();
            const month = mainCurrentDate.getMonth();
            const daysGrid = document.getElementById('mainCalendarDaysGrid');
            const monthYearDisplay = document.getElementById('mainCalendarMonthYear');
            
            if (!daysGrid || !monthYearDisplay) return;
            
            monthYearDisplay.textContent = mainCurrentDate.toLocaleDateString('en-US', { 
                month: 'long', 
                year: 'numeric' 
            });
            
            updateMainCalendarSelectors();
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();
            
            let html = '';
            
            const prevMonthDays = new Date(year, month, 0).getDate();
            for (let i = firstDay - 1; i >= 0; i--) {
                const day = prevMonthDays - i;
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                html += `<div class="main-calendar-day other-month" onclick="selectMainDate('${dateStr}')">${day}</div>`;
            }
            
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
                const isSelected = dateStr === mainSelectedDate;
                const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
                
                let classes = 'main-calendar-day';
                if (isToday) classes += ' today';
                if (isSelected) classes += ' selected';
                if (isWeekend) classes += ' weekend';
                
                html += `<div class="${classes}" onclick="selectMainDate('${dateStr}')">${day}</div>`;
            }
            
            const totalCells = 42;
            const cellsUsed = firstDay + daysInMonth;
            const nextMonthDays = totalCells - cellsUsed;
            for (let day = 1; day <= nextMonthDays; day++) {
                const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                html += `<div class="main-calendar-day other-month" onclick="selectMainDate('${dateStr}')">${day}</div>`;
            }
            
            daysGrid.innerHTML = html;
        }

        function navigateMainMonth(direction) {
            mainCurrentDate.setMonth(mainCurrentDate.getMonth() + direction);
            generateMainCalendarDays();
        }

        function selectMainDate(dateStr) {
            const date = new Date(dateStr);
            const formattedDisplay = date.toLocaleDateString('en-US', {
                month: '2-digit',
                day: '2-digit',
                year: 'numeric'
            });
            
            const dateDisplay = document.getElementById('mainDateDisplay');
            const dateHidden = document.getElementById('selectedDate');
            
            if (dateDisplay) dateDisplay.textContent = formattedDisplay;
            if (dateHidden) dateHidden.value = dateStr;
            
            mainSelectedDate = dateStr;
            mainCurrentDate = new Date(dateStr);
            
            generateMainCalendarDays();
            
            const calendar = document.getElementById('mainCalendarWrapper');
            if (calendar) calendar.style.display = 'none';
            
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('date', dateStr);
            currentUrl.searchParams.set('search', '<?= $search_query ?>');
            currentUrl.searchParams.set('sort_by', '<?= $sort_by ?>');
            currentUrl.searchParams.set('sort_order', '<?= $sort_order ?>');
            window.location.href = currentUrl.toString();
        }

        function clearMainDate() {
            const dateDisplay = document.getElementById('mainDateDisplay');
            const dateHidden = document.getElementById('selectedDate');
            
            if (dateDisplay) dateDisplay.textContent = 'Select Date';
            if (dateHidden) dateHidden.value = '';
            
            mainSelectedDate = '';
            
            const calendar = document.getElementById('mainCalendarWrapper');
            if (calendar) calendar.style.display = 'none';
        }

        function setMainToday() {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;
            
            mainCurrentDate = new Date(dateStr);
            selectMainDate(dateStr);
        }

        function quickDateSelect(date) {
            if (date) {
                window.location.href = 'home.php?date=' + encodeURIComponent(date) +
                    '&search=<?php echo urlencode($search_query); ?>' +
                    '&sort_by=<?php echo urlencode($sort_by); ?>' +
                    '&sort_order=<?php echo urlencode($sort_order); ?>';
            }
        }
        
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        
        function closeEmployeesModal() {
            console.log('Closing modal');
            const modal = document.getElementById('siteEmployeesModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                
                document.getElementById('siteInfoContainer').innerHTML = '';
                document.getElementById('employeesListModalContainer').innerHTML = `
                    <div class="loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading employees...</p>
                    </div>
                `;
            }
        }

        function viewSiteEmployees(siteId) {
            console.log('Viewing site ID:', siteId);
            
            const modal = document.getElementById('siteEmployeesModal');
            if (!modal) {
                console.error('Modal not found!');
                return;
            }
            
            document.getElementById('siteInfoContainer').innerHTML = '';
            document.getElementById('employeesListModalContainer').innerHTML = '';
            
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.body.classList.add('modal-open');
            
            document.getElementById('siteInfoContainer').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #2E7D32;"></i>
                    <p style="margin-top: 10px;">Loading site information...</p>
                </div>
            `;
            
            document.getElementById('employeesListModalContainer').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 3rem; color: #2E7D32;"></i>
                    <p style="margin-top: 15px;">Loading employees...</p>
                </div>
            `;
            
            loadSiteEmployees(siteId);
        }

        async function loadSiteEmployees(siteId) {
            const date = '<?php echo $filter_date; ?>';
            console.log('Loading employees for site:', siteId, 'date:', date);
            
            try {
                const url = `get_site_employees_attendance.php?site_id=${siteId}&date=${encodeURIComponent(date)}`;
                console.log('Fetching URL:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Received data:', data);
                
                if (data.success) {
                    const siteInfoContainer = document.getElementById('siteInfoContainer');
                    siteInfoContainer.innerHTML = `
                        <p><i class="fas fa-building"></i> <strong>${data.site_name}</strong></p>
                        <p><i class="fas fa-user-tie"></i> Manager: ${data.site_manager || 'N/A'}</p>
                        <p><i class="fas fa-map-marker-alt"></i> Address: ${data.site_address || 'N/A'}</p>
                        <p><i class="fas fa-calendar-alt"></i> Date: ${new Date(data.date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</p>
                        <p><i class="fas fa-users"></i> Total Employees: ${data.summary.total}</p>
                        <div class="summary-stats">
                            <span class="summary-stat present"><i class="fas fa-check-circle"></i> Present: ${data.summary.present}</span>
                            <span class="summary-stat absent"><i class="fas fa-times-circle"></i> Absent: ${data.summary.absent}</span>
                            <span class="summary-stat leave"><i class="fas fa-umbrella-beach"></i> On Leave: ${data.summary.leave}</span>
                        </div>
                    `;
                    
                    const container = document.getElementById('employeesListModalContainer');
                    
                    if (data.employees && data.employees.length > 0) {
                        let html = `
                            <div class="employees-table-container">
                                <table class="employees-table">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-user"></i> Employee</th>
                                            <th><i class="fas fa-briefcase"></i> Position</th>
                                            <th><i class="fas fa-sun"></i> AM Status</th>
                                            <th><i class="fas fa-moon"></i> PM Status</th>
                                            <th><i class="fas fa-star"></i> Night Status</th>
                                            <th><i class="fas fa-clock"></i> Time Details</th>
                                            <th><i class="fas fa-hourglass-half"></i> Total Hours</th>
                                            <th><i class="fas fa-comment"></i> Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.employees.forEach(emp => {
                            const amStatus = emp.status_am || 'Absent';
                            const amStatusClass = amStatus === 'Present' ? 'status-present' : 
                                                  (amStatus === 'On Leave' ? 'status-leave' : 'status-absent');
                            
                            const pmStatus = emp.status_pm || 'Absent';
                            const pmStatusClass = pmStatus === 'Present' ? 'status-present' : 
                                                  (pmStatus === 'On Leave' ? 'status-leave' : 'status-absent');
                            
                            const nightStatus = emp.status_night || 'Absent';
                            const nightStatusClass = nightStatus === 'Present' ? 'status-present' : 
                                                    (nightStatus === 'On Leave' ? 'status-leave' : 'status-absent');
                            
                            const timeInAm = emp.time_in_am_display || emp.time_in_am || '--:--';
                            const timeOutAm = emp.time_out_am_display || emp.time_out_am || '--:--';
                            const timeInPm = emp.time_in_pm_display || emp.time_in_pm || '--:--';
                            const timeOutPm = emp.time_out_pm_display || emp.time_out_pm || '--:--';
                            const timeInNight = emp.time_in_night_display || emp.time_in_night || '--:--';
                            const timeOutNight = emp.time_out_night_display || emp.time_out_night || '--:--';
                            
                            let hoursDisplay = '—';
                            if (emp.total_hours && parseFloat(emp.total_hours) > 0) {
                                const hours = parseFloat(emp.total_hours).toFixed(2);
                                hoursDisplay = `${hours} hrs`;
                            }
                            
                            html += `
                                <tr>
                                    <td>
                                        <span>${emp.full_name || emp.first_name + ' ' + emp.last_name}</span>
                                    </td>
                                    <td>${emp.position || '—'}</td>
                                    <td>
                                        <span class="status-badge ${amStatusClass}">
                                            <i class="fas ${amStatus === 'Present' ? 'fa-check-circle' : 
                                                           (amStatus === 'On Leave' ? 'fa-umbrella-beach' : 'fa-times-circle')}"></i>
                                            ${amStatus}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge ${pmStatusClass}">
                                            <i class="fas ${pmStatus === 'Present' ? 'fa-check-circle' : 
                                                           (pmStatus === 'On Leave' ? 'fa-umbrella-beach' : 'fa-times-circle')}"></i>
                                            ${pmStatus}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge ${nightStatusClass}">
                                            <i class="fas ${nightStatus === 'Present' ? 'fa-check-circle' : 
                                                           (nightStatus === 'On Leave' ? 'fa-umbrella-beach' : 'fa-times-circle')}"></i>
                                            ${nightStatus}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="time-badge">
                                            <div class="time-row">
                                                <span class="time-label"><i class="fas fa-sun"></i> AM:</span>
                                                <span class="time-value">${timeInAm} - ${timeOutAm}</span>
                                            </div>
                                            <div class="time-row">
                                                <span class="time-label"><i class="fas fa-moon"></i> PM:</span>
                                                <span class="time-value">${timeInPm} - ${timeOutPm}</span>
                                            </div>
                                            <div class="time-row">
                                                <span class="time-label"><i class="fas fa-star"></i> Night:</span>
                                                <span class="time-value">${timeInNight} - ${timeOutNight}</span>
                                            </div>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="time-badge" style="font-weight: 700; color: #2E7D32;">
                                            ${hoursDisplay}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="time-badge" style="color: #64748b;">
                                            ${emp.remarks || '—'}
                                        </span>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                 </table>
                            </div>
                        `;
                        
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = `
                            <div class="no-employees">
                                <i class="fas fa-user-slash"></i>
                                <p>No employees assigned to this site</p>
                            </div>
                        `;
                    }
                } else {
                    throw new Error(data.message || 'Failed to load employees');
                }
            } catch (error) {
                console.error('Error loading site employees:', error);
                document.getElementById('siteInfoContainer').innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #dc2626;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i>
                        <p style="margin-top: 10px;">Error loading site information</p>
                        <small>${error.message}</small>
                    </div>
                `;
                document.getElementById('employeesListModalContainer').innerHTML = `
                    <div class="no-employees">
                        <i class="fas fa-exclamation-triangle" style="color: #dc2626;"></i>
                        <p>Error loading employees</p>
                        <div class="sub-text">${error.message}</div>
                        <button onclick="loadSiteEmployees(${siteId})" style="margin-top: 15px; padding: 8px 20px; background: #2E7D32; color: white; border: none; border-radius: 5px; cursor: pointer;">
                            <i class="fas fa-sync-alt"></i> Try Again
                        </button>
                    </div>
                `;
            }
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('siteEmployeesModal');
            if (event.target === modal) {
                closeEmployeesModal();
            }
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEmployeesModal();
                
                const calendar = document.getElementById('mainCalendarWrapper');
                if (calendar && calendar.style.display === 'block') {
                    calendar.style.display = 'none';
                }
            }
        });

        document.addEventListener('click', function(e) {
            const mainDatePicker = document.querySelector('.main-date-picker-wrapper');
            const mainCalendar = document.getElementById('mainCalendarWrapper');
            
            if (mainDatePicker && mainCalendar && !mainDatePicker.contains(e.target)) {
                mainCalendar.style.display = 'none';
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            generateMainCalendarDays();
            
            setTimeout(() => {
                document.querySelectorAll('.progress-fill').forEach(bar => {
                    const width = bar.style.width;
                    bar.style.width = '0%';
                    setTimeout(() => {
                        bar.style.width = width;
                    }, 100);
                });
            }, 200);
        });

        window.closeEmployeesModal = closeEmployeesModal;
        window.viewSiteEmployees = viewSiteEmployees;
        window.loadSiteEmployees = loadSiteEmployees;
    </script>

</body>
</html>
<?php $conn->close(); ?>