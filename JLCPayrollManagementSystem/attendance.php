<?php
session_start();

// Debug: Check if we have success/error messages
if (isset($_SESSION['success'])) {
    error_log("SUCCESS MESSAGE: " . $_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    error_log("ERROR MESSAGE: " . $_SESSION['error']);
}

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// Include access control
include_once("check_access.php");

// Check if user has access to this page
$current_page = basename($_SERVER['PHP_SELF']);
if (!checkPageAccess($current_page)) {
    header("Location: home.php?error=access_denied");
    exit;
}

include_once("connection.php");

// Date filter - default to current date
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Employee filter
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$employee_filter_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

// Extract month, year, and day from selected date
$month = date('m', strtotime($selected_date));
$year = date('Y', strtotime($selected_date));
$day = date('d', strtotime($selected_date));

// Check if selected date is a holiday
$holiday_sql = "SELECT * FROM holidays WHERE holiday_date = ?";
$holiday_stmt = $conn->prepare($holiday_sql);
if ($holiday_stmt) {
    $holiday_stmt->bind_param("s", $selected_date);
    $holiday_stmt->execute();
    $holiday_result = $holiday_stmt->get_result();
    $is_holiday = $holiday_result->num_rows > 0;
    $holiday_data = $is_holiday ? $holiday_result->fetch_assoc() : null;
    $holiday_stmt->close();
} else {
    $is_holiday = false;
    $holiday_data = null;
}

// BASE QUERY - with workday_type
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
        LEFT JOIN attendance a 
            ON a.employee_id = e.id 
            AND DATE(a.date) = ?";

$params = [$selected_date];
$param_types = "s";

// Apply filters
$where_clauses = [];

if ($employee_filter_id > 0) {
    $where_clauses[] = "e.id = ?";
    $params[] = $employee_filter_id;
    $param_types .= "i";
} 
elseif (!empty($search)) {
    $where_clauses[] = "(e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ? OR e.id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY e.last_name ASC, a.date DESC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get employees for modal dropdown
$employee_sql = "SELECT id, first_name, middle_name, last_name, position FROM employees ORDER BY last_name, first_name";
$employee_result = $conn->query($employee_sql);

// Get selected employee name for display
$selected_employee_name = "";
$selected_employee_position = "";
if ($employee_filter_id > 0) {
    $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name, position FROM employees WHERE id = ?";
    $name_stmt = $conn->prepare($name_query);
    $name_stmt->bind_param("i", $employee_filter_id);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($name_row = $name_result->fetch_assoc()) {
        $selected_employee_name = $name_row['full_name'];
        $selected_employee_position = $name_row['position'] ?? 'N/A';
    }
    $name_stmt->close();
}

function calculateHours($time_in, $time_out) {
    // Check if times are empty or invalid
    if (empty($time_in) || empty($time_out)) {
        return '0.00';
    }
    
    // Special handling for midnight (12:00 AM)
    $is_midnight_out = ($time_out == '00:00:00');
    
    // Convert to timestamps
    $time_in_ts = strtotime($time_in);
    
    if ($time_in_ts === false) {
        return '0.00';
    }
    
    if ($is_midnight_out) {
        // For midnight (12:00 AM) as time_out, calculate hours until midnight of the same day
        // Get the date part of time_in and add 1 day, then set to 00:00:00
        $date_of_time_in = date('Y-m-d', $time_in_ts);
        $next_day = strtotime('+1 day', strtotime($date_of_time_in));
        $time_out_ts = strtotime(date('Y-m-d', $next_day) . ' 00:00:00');
        
        // Calculate hours from time_in to midnight
        $hours = round(($time_out_ts - $time_in_ts) / 3600, 2);
        
        // Debug logging
        error_log("Midnight calculation: time_in=$time_in, time_out=$time_out, hours=$hours");
        
        return number_format($hours, 2);
    }
    
    // Regular time calculation (not midnight)
    $time_out_ts = strtotime($time_out);
    
    if ($time_out_ts === false) {
        return '0.00';
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
    
    return number_format($hours, 2);
}
function calculateTotalHours($time_in_am, $time_out_am, $time_in_pm, $time_out_pm, $time_in_night, $time_out_night) {
    $total = 0;
    
    // Check AM session - include if either time exists (even if midnight)
    if (!empty($time_in_am) && !empty($time_out_am)) {
        $am_hours = calculateHours($time_in_am, $time_out_am);
        $total += floatval($am_hours);
        error_log("AM Hours: $am_hours (in: $time_in_am, out: $time_out_am)");
    }
    
    // Check PM session
    if (!empty($time_in_pm) && !empty($time_out_pm)) {
        $pm_hours = calculateHours($time_in_pm, $time_out_pm);
        $total += floatval($pm_hours);
        error_log("PM Hours: $pm_hours (in: $time_in_pm, out: $time_out_pm)");
    }
    
    // Check Night session
    if (!empty($time_in_night) && !empty($time_out_night)) {
        $night_hours = calculateHours($time_in_night, $time_out_night);
        $total += floatval($night_hours);
        error_log("Night Hours: $night_hours (in: $time_in_night, out: $time_out_night)");
    }
    
    error_log("Total Hours: $total");
    return number_format($total, 2);
}
// Function to format time for display
function formatTimeForDownload($time) {
    if (empty($time) || $time == '00:00:00') {
        return '-';
    }
    return date('h:i A', strtotime($time));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance</title>
    <link rel="stylesheet" href="./assets/css/attendance1.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Keep all your existing CSS styles exactly as they are - no changes needed */
        .content-wrapper {
            min-height: calc(100vh - var(--header-height) - 40px);
            margin-top: 0;
            padding: 0 0 30px 0;
        }
        
        .payroll-info {
            display: grid;
            gap: 4px;
        }
        
        .payroll-info strong {
            color: var(--sidebar-dark-green);
            font-weight: 600;
        }
        
        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 15px 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .search-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            position: relative;
        }
        
        .generate-payroll-btn {
            background-color: var(--sidebar-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--box-shadow);
            white-space: nowrap;
            text-decoration: none;
        }
        
        .generate-payroll-btn:hover {
            background-color: var(--sidebar-dark-green);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
        }
        
        .date-range {
            color: #666;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        /* Status badge styles */
        .status-present, .status-absent, .status-no-record, .status-leave {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            text-align: center;
            min-width: 70px;
        }
        
        .status-present {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-absent {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .status-no-record {
            background-color: #e9ecef;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .status-leave {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .leave-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
            background-color: #ffeaa7;
            color: #856404;
        }
        
        .leave-type-badge.sick {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .leave-type-badge.vacation {
            background-color: #d4edda;
            color: #155724;
        }
        
        .leave-type-badge.emergency {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .workday-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
            background-color: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
            word-break: break-word;
            white-space: normal;
            max-width: 150px;
            line-height: 1.3;
        }

        /* Holiday Info Bar - Keep this for compatibility */
        .holiday-info-bar {
            background: linear-gradient(135deg, #f3e5f5, #e1bee7);
            border-left: 4px solid #9b59b6;
            padding: 12px 20px;
            margin: 0 20px 20px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--box-shadow);
        }
        
        .holiday-info-bar i {
            font-size: 1.5rem;
            color: #9b59b6;
        }
        
        .holiday-info-content {
            flex: 1;
        }
        
        .holiday-info-title {
            font-weight: 700;
            color: #6a1b9a;
            margin-bottom: 3px;
            font-size: 1rem;
        }
        
        .holiday-info-desc {
            font-size: 0.9rem;
            color: #4a148c;
        }
        
        .holiday-info-badge {
            background: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #9b59b6;
            border: 1px solid #ce93d8;
        }

        /* ============ MAIN DATE FILTER STYLES WITH DROPDOWNS ============ */
        .main-date-picker-wrapper {
            position: relative;
            width: 180px;
        }
        
        .main-date-input-group {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
        }
        
        .main-date-field {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            cursor: pointer;
            color: #2c3e50;
            font-weight: 500;
            height: 42px;
        }
        
        .main-date-field:hover {
            border-color: var(--sidebar-green);
        }
        
        .main-date-field:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .main-date-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--sidebar-green);
            font-size: 1rem;
            pointer-events: none;
        }
        
        .main-calendar-dropdown-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .main-calendar-dropdown-btn:hover {
            color: var(--sidebar-green);
        }
        
        .main-calendar-wrapper {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            display: none;
            width: 320px;
        }
        
        .main-calendar-wrapper.show {
            display: block;
        }
        
        .main-calendar-box {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .main-calendar-header {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
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
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1.2rem;
        }
        
        .main-calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .main-calendar-selectors {
            display: flex;
            gap: 10px;
            padding: 15px 15px 5px 15px;
            background: white;
        }
        
        .main-calendar-select {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }
        
        .main-calendar-select:hover {
            border-color: var(--sidebar-green);
        }
        
        .main-calendar-select:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
        }
        
        .main-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        .main-calendar-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 10px;
            background: white;
        }
        
        .main-calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 0.9rem;
            color: #2c3e50;
            text-decoration: none;
            border: none;
            background: none;
            width: 100%;
        }
        
        .main-calendar-day:hover {
            background: #e8f5e9;
            color: var(--sidebar-dark-green);
        }
        
        .main-calendar-day.selected {
            background: var(--sidebar-green);
            color: white;
            font-weight: 600;
        }
        
        .main-calendar-day.today {
            border: 2px solid var(--sidebar-green);
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
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }
        
        .main-calendar-action-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
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
            background: var(--sidebar-green);
            color: white;
            border: 1px solid var(--sidebar-green);
        }
        
        .main-calendar-action-btn.today:hover {
            background: var(--sidebar-dark-green);
        }

        /* ============ MODAL DATE PICKER STYLES WITH DROPDOWNS ============ */
        .date-picker-wrapper {
            position: relative;
            width: 100%;
        }
        
        .date-input-group {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
        }
        
        .date-field {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            cursor: pointer;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .date-field:hover {
            border-color: var(--sidebar-green);
        }
        
        .date-field:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .date-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--sidebar-green);
            font-size: 1rem;
            pointer-events: none;
        }
        
        .calendar-dropdown-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .calendar-dropdown-btn:hover {
            color: var(--sidebar-green);
        }
        
        .modal-calendar-wrapper {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            display: none;
        }
        
        .modal-calendar-box {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .modal-calendar-header {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-calendar-month-year {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .modal-calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .modal-calendar-nav-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1.2rem;
        }
        
        .modal-calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .modal-calendar-selectors {
            display: flex;
            gap: 10px;
            padding: 15px 15px 5px 15px;
            background: white;
        }
        
        .modal-calendar-select {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }
        
        .modal-calendar-select:hover {
            border-color: var(--sidebar-green);
        }
        
        .modal-calendar-select:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
        }
        
        .modal-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        .modal-calendar-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 10px;
            background: white;
        }
        
        .modal-calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 0.9rem;
            color: #2c3e50;
            text-decoration: none;
        }
        
        .modal-calendar-day:hover {
            background: #e8f5e9;
            color: var(--sidebar-dark-green);
        }
        
        .modal-calendar-day.selected {
            background: var(--sidebar-green);
            color: white;
            font-weight: 600;
        }
        
        .modal-calendar-day.today {
            border: 2px solid var(--sidebar-green);
            font-weight: 600;
        }
        
        .modal-calendar-day.weekend {
            color: #e74c3c;
        }
        
        .modal-calendar-day.other-month {
            color: #bdc3c7;
        }
        
        .modal-calendar-footer {
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }
        
        .modal-calendar-action-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .modal-calendar-action-btn.clear {
            background: #f8f9fa;
            color: #7f8c8d;
            border: 1px solid #bdc3c7;
        }
        
        .modal-calendar-action-btn.clear:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }
        
        .modal-calendar-action-btn.today {
            background: var(--sidebar-green);
            color: white;
            border: 1px solid var(--sidebar-green);
        }
        
        .modal-calendar-action-btn.today:hover {
            background: var(--sidebar-dark-green);
        }

        /* Employee search styles */
        .employee-search-wrapper {
            position: relative;
            width: 100%;
            z-index: 1000;
        }
        
        .employee-search-container {
            position: relative;
            width: 100%;
            margin-bottom: 0;
        }
        
        .search-input-wrapper {
            position: relative;
            width: 100%;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            font-size: 0.9rem;
        }
        
        .employee-search-input {
            width: 100%;
            padding: 12px 40px 12px 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }
        
        .employee-search-input:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .clear-search {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .clear-search:hover {
            color: #e74c3c;
        }
        
        .employee-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            z-index: 1001;
            margin-top: 4px;
            max-height: 320px;
            overflow-y: auto;
            display: none;
        }
        
        .results-header {
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.85rem;
            color: #6c757d;
            border-radius: 8px 8px 0 0;
        }
        
        .results-list {
            max-height: 250px;
            overflow-y: auto;
        }
        
        .employee-result-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .employee-result-item:hover {
            background: #e8f5e9;
        }
        
        .employee-result-item.selected {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        
        .employee-result-info {
            flex: 1;
        }
        
        .employee-result-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
            font-size: 0.95rem;
        }
        
        .employee-result-name strong {
            background: #ffeaa7;
            padding: 0 2px;
            border-radius: 3px;
        }
        
        .employee-result-details {
            display: flex;
            gap: 15px;
            font-size: 0.8rem;
            color: #7f8c8d;
        }
        
        .employee-result-id i {
            margin-right: 4px;
            font-size: 0.75rem;
        }
        
        .no-results {
            text-align: center;
            padding: 30px 20px;
            color: #7f8c8d;
        }
        
        .no-results i {
            font-size: 2.5rem;
            color: #d1f0eb;
            margin-bottom: 10px;
        }
        
        .no-results h4 {
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .no-results p {
            font-size: 0.85rem;
        }
        
        .results-footer {
            padding: 10px 15px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            font-size: 0.8rem;
            color: #95a5a6;
            border-radius: 0 0 8px 8px;
        }
        
        .text-muted {
            color: #95a5a6;
        }
        
        .live-search-container {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 5px;
            z-index: 3000;
            display: none;
        }
        
        .live-search-container.active {
            display: block;
        }
        
        .live-search-results {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
            max-height: 400px;
            overflow-y: auto;
            width: 100%;
        }
        
        .live-search-header {
            padding: 12px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.85rem;
            color: #6c757d;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .live-search-header span {
            font-weight: 600;
        }
        
        .live-search-close {
            color: #95a5a6;
            cursor: pointer;
            font-size: 1rem;
            padding: 0 5px;
        }
        
        .live-search-close:hover {
            color: #e74c3c;
        }
        
        .live-search-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .live-search-item:hover {
            background: #e8f5e9;
        }
        
        .live-search-item.selected {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        
        .live-search-item-info {
            flex: 1;
        }
        
        .live-search-item-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
            font-size: 0.95rem;
        }
        
        .live-search-item-name strong {
            background: #ffeaa7;
            padding: 0 2px;
            border-radius: 3px;
        }
        
        .live-search-item-details {
            display: flex;
            gap: 15px;
            font-size: 0.8rem;
            color: #7f8c8d;
        }
        
        .live-search-item-id i {
            margin-right: 4px;
            font-size: 0.75rem;
        }
        
        .live-search-loading {
            text-align: center;
            padding: 30px 20px;
            color: #7f8c8d;
        }
        
        .live-search-loading i {
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        
        .live-search-no-results {
            text-align: center;
            padding: 30px 20px;
            color: #7f8c8d;
        }
        
        .live-search-no-results i {
            font-size: 2.5rem;
            color: #d1f0eb;
            margin-bottom: 10px;
        }
        
        .live-search-no-results h4 {
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .live-search-no-results p {
            font-size: 0.85rem;
        }
        
        .live-search-footer {
            padding: 10px 15px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            font-size: 0.8rem;
            color: #95a5a6;
            border-radius: 0 0 8px 8px;
            text-align: right;
        }
        
        .search-input-wrapper {
            position: relative;
            width: 100%;
        }
        
        #mainSearchInput {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        
        #mainSearchInput:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .add-attendance-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        
        .add-attendance-btn:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }
        
        .report-btn-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 5px;
            position: relative;
        }
        
        .report-btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--box-shadow);
            white-space: nowrap;
        }
        
        .report-btn:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
        }
        
        .employee-filter-badge {
            background: linear-gradient(135deg, #388380 0%, #75e6da 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            box-shadow: var(--box-shadow);
        }
        
        .employee-filter-badge i {
            font-size: 1rem;
        }
        
        .remove-filter-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 8px;
            text-decoration: none;
        }
        
        .remove-filter-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 750px;
            max-width: 95%;
            margin: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        
        .modal-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            border-radius: 0 0 16px 16px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-shrink: 0;
        }
        
        .hidden-select {
            display: none;
        }
        
        .selected-employee-card {
            background: #f0f8ff;
            border: 2px solid var(--sidebar-green);
            border-radius: 12px;
            padding: 15px;
            margin: 10px 0;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .selected-employee-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .selected-employee-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .selected-employee-avatar i {
            color: white;
            font-size: 1.5rem;
        }
        
        .selected-employee-details {
            display: flex;
            flex-direction: column;
        }
        
        .selected-employee-name {
            font-weight: 700;
            color: #2c3e50;
            font-size: 1.1rem;
            margin-bottom: 4px;
        }
        
        .selected-employee-id {
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        
        .selected-employee-id i {
            margin-right: 5px;
            color: var(--sidebar-green);
            font-size: 0.85rem;
        }
        
        .btn-change-employee {
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: #ff6b6b;
            color: white;
            border: 3px solid white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            z-index: 10;
        }
        
        .btn-change-employee:hover {
            background-color: #e74c3c;
            transform: scale(1.15);
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.4);
        }
        
        .btn-change-employee i {
            font-size: 1.1rem;
        }
        
        .loading-results {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
        }
        
        .loading-results i {
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-col {
            flex: 1;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .form-label i {
            margin-right: 8px;
            color: var(--sidebar-green);
        }
        
        .form-label.required:after {
            content: " *";
            color: #e74c3c;
        }

        /* Updated status-options for centering */
        .status-options {
            display: flex;
            gap: 30px;
            margin-top: 5px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
        }
        
        .status-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-radio {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--sidebar-green);
        }
        
        .status-label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-dot.present {
            background-color: #28a745;
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
        }
        
        .status-dot.absent {
            background-color: #e74c3c;
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.2);
        }
        
        .status-dot.leave {
            background-color: #ffc107;
            box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.2);
        }

        /* UPDATED TIME SECTION WITH #75e6da LEFT BORDER */
        .time-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            border-left: 4px solid #75e6da;
        }
        
        .time-section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #75e6da;
        }
        
        .time-section-header i {
            font-size: 1.2rem;
            color: #75e6da;
        }
        
        .time-section-header h4 {
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
            font-size: 1.1rem;
        }
        
        /* UPDATED AM STATUS CONTAINER */
        .am-status-container {
            margin: 0 0 15px 0;
            padding: 10px 15px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #75e6da;
        }
        
        .am-status-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .am-status-label i {
            margin-right: 8px;
            color: #75e6da;
        }

        /* UPDATED PM STATUS CONTAINER */
        .pm-status-container {
            margin: 15px 0 10px 0;
            padding: 10px 15px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #75e6da;
        }
        
        .pm-status-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .pm-status-label i {
            margin-right: 8px;
            color: #75e6da;
        }

        /* UPDATED WORKDAY TYPE CONTAINER WITH #75e6da COLOR */
        .workday-type-container {
            margin: 15px 0;
            padding: 15px;
            background: linear-gradient(135deg, #75e6da20, #75e6da10);
            border-radius: 12px;
            border: 2px solid #75e6da;
            animation: slideDown 0.3s ease;
            box-shadow: 0 4px 12px rgba(117, 230, 218, 0.2);
        }

        .workday-type-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }

        .workday-type-label i {
            color: #2c3e50;
            background: white;
            padding: 5px;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #75e6da;
        }

        .workday-type-select-container {
            position: relative;
        }

        .workday-type-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #75e6da;
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='%232c3e50' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 20px;
            color: #2c3e50;
            font-weight: 500;
        }

        .workday-type-select:hover {
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        }

        .workday-type-select:focus {
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.3);
            outline: none;
        }

        /* UPDATED LEAVE TYPE DROPDOWN WITH #75e6da COLOR - ALWAYS VISIBLE */
        .leave-type-container {
            margin: 15px 0 25px 0;
            padding: 15px;
            background: linear-gradient(135deg, #75e6da20, #75e6da10);
            border-radius: 12px;
            border: 2px solid #75e6da;
            animation: slideDown 0.3s ease;
            box-shadow: 0 4px 12px rgba(117, 230, 218, 0.2);
        }

        .leave-type-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }

        .leave-type-label i {
            color: #2c3e50;
            background: white;
            padding: 5px;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #75e6da;
        }

        .leave-type-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #75e6da;
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='%232c3e50' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 20px;
            color: #2c3e50;
            font-weight: 500;
        }

        .leave-type-select:hover {
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.2);
        }

        .leave-type-select:focus {
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.3);
            outline: none;
        }

        /* Time input styles */
        .time-input-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .time-input-row:last-child {
            margin-bottom: 0;
        }
        
        .time-input-group {
            flex: 1;
            position: relative;
        }
        
        .time-input-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #34495e;
            width: 100%;
        }

        .time-input-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        .time-display-box {
            flex: 1;
            padding: 12px 15px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            min-height: 48px;
            width: 100%;
        }

        .time-display-content {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: flex-start;
        }

        .time-set-btn {
            background: var(--sidebar-green);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            min-width: 100px;
            justify-content: center;
        }

        @media (max-width: 768px) {
            .time-input-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .time-input-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .time-set-btn {
                width: 100%;
                margin-top: 5px;
            }
        }

        
        .time-display-box:hover {
            border-color: var(--sidebar-green);
        }
        
        .time-display-box.empty {
            background: #f8f9fa;
            color: #95a5a6;
        }
        
        .time-display-box.disabled {
            background: #f0f0f0;
            border-color: #d0d0d0;
            opacity: 0.6;
            pointer-events: none;
        }
        
        .time-display-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .time-display-content i {
            color: #95a5a6;
            font-size: 0.9rem;
        }
        
        .time-set-btn {
            background: var(--sidebar-green);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .time-set-btn:hover {
            background: var(--sidebar-dark-green);
            transform: translateY(-2px);
        }
        
        .time-set-btn i {
            font-size: 0.9rem;
        }
        
        .time-set-btn.disabled {
            background: #cccccc;
            pointer-events: none;
            opacity: 0.6;
        }
        
        .am-total-hours, .pm-total-hours {
            margin-top: 10px;
            text-align: right;
            font-size: 0.9rem;
            color: #666;
        }
        
        .total-hours-container {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: var(--border-radius);
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        
        .total-hours-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2e7d32;
        }
        
        .total-hours-header i {
            color: #28a745;
        }
        
        .total-hours-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }
        
        .total-hours-item {
            background: white;
            padding: 12px;
            border-radius: var(--border-radius);
            text-align: center;
        }
        
        .total-hours-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .total-hours-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2e7d32;
            font-family: 'Courier New', monospace;
        }
        
        .total-hours-value span {
            font-size: 0.9rem;
            font-weight: 600;
            color: #666;
        }

        .time-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .time-modal.show {
            display: flex;
        }
        
        .time-modal-content {
            background: white;
            border-radius: 16px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .time-modal-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .time-modal-title {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .time-modal-body {
            padding: 30px 25px;
        }
        
        .time-input-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .time-input-select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 90px;
            text-align: center;
        }
        
        .time-input-select:hover {
            border-color: var(--sidebar-green);
        }
        
        .time-input-select:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .time-input-separator {
            font-size: 1.5rem;
            font-weight: 700;
            color: #34495e;
        }
        
        .time-period-select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 100px;
            text-align: center;
        }
        
        .time-period-select:hover {
            border-color: var(--sidebar-green);
        }
        
        .time-period-select:focus {
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
        }
        
        .time-modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            border-radius: 0 0 16px 16px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .workday-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            white-space: normal;
            word-break: break-word;
            max-width: 150px;
            line-height: 1.3;
            background-color: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }

        /* Para sa leave badges */
        .leave-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            white-space: normal;
            word-break: break-word;
            max-width: 150px;
            line-height: 1.3;
        }

        .leave-type-badge.sick {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .leave-type-badge.vacation {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .leave-type-badge.emergency {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Table Container with horizontal scroll */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background: white;
        }

        .payroll-table-container {
            min-width: 1500px;
        }

        /* Table Base */
        .payroll-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        /* Header Styles */
        .payroll-table thead tr {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
        }

        .payroll-table th {
            color: white;
            padding: 15px 8px;
            font-size: 0.95rem;
            font-weight: 600;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,0.2);
            white-space: nowrap;
        }

        .payroll-table th:last-child {
            border-right: none;
        }

        .payroll-table th i {
            margin-right: 6px;
            font-size: 0.9rem;
        }

        /* Column Widths - UPDATED: Changed Holiday to Workday Type */
        .payroll-table th:nth-child(1) { width: 210px; }  /* Employee */
        .payroll-table th:nth-child(2) { width: 140px; }  /* Date */
        .payroll-table th:nth-child(3) { width: 140px; }  /* AM Status */
        .payroll-table th:nth-child(4) { width: 90px; }   /* AM In */
        .payroll-table th:nth-child(5) { width: 100px; }  /* AM Out */
        .payroll-table th:nth-child(6) { width: 140px; }  /* PM Status */
        .payroll-table th:nth-child(7) { width: 155px; }  /* PM/Night In */
        .payroll-table th:nth-child(8) { width: 155px; }  /* PM/Night Out */
        .payroll-table th:nth-child(9) { width: 150px; }  /* Night Status */
        .payroll-table th:nth-child(10) { width: 120px; } /* Leave */
        .payroll-table th:nth-child(11) { width: 150px; } /* Workday Type */
        .payroll-table th:nth-child(12) { width: 150px; } /* Total Hours */
        .payroll-table th:nth-child(13) { width: 110px; } /* Remarks */
        .payroll-table th:nth-child(14) { width: 120px; } /* Actions */

        /* Row Styles */
        .payroll-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
            transition: background-color 0.2s;
        }

        .payroll-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .payroll-table tbody tr:last-child {
            border-bottom: none;
        }

        .payroll-table td {
            padding: 12px 8px;
            vertical-align: middle;
            border-right: 1px solid #f0f0f0;
        }

        .payroll-table td:last-child {
            border-right: none;
        }

        /* Employee Column - One Line Only */
        .payroll-table td:first-child > div:first-child {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 4px;
        }

        .payroll-table td:first-child > div:last-child {
            font-size: 0.9rem;
            color: #666;
            white-space: nowrap;
        }

        /* Status Badges - Removed .status-holiday */
        .status-present, .status-absent, .status-no-record, .status-leave {
            font-size: 0.9rem;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            text-align: center;
            min-width: 80px;
            white-space: nowrap;
        }

        .status-present {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-absent {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-no-record {
            background-color: #e9ecef;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }

        .status-leave {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Time Columns */
        .payroll-table td:nth-child(4),
        .payroll-table td:nth-child(5) {
            font-size: 1rem;
            font-weight: 500;
            text-align: center;
        }

        /* PM/Night Times */
        .payroll-table td:nth-child(7) div,
        .payroll-table td:nth-child(8) div {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .payroll-table td:nth-child(7) div > div,
        .payroll-table td:nth-child(8) div > div {
            font-size: 1rem;
            white-space: nowrap;
        }

        .payroll-table td:nth-child(7) div > div span:first-child,
        .payroll-table td:nth-child(8) div > div span:first-child {
            display: inline-block;
            width: 45px;
            font-weight: 600;
        }

        /* Total Hours */
        .payroll-table td:nth-child(12) span {
            font-size: 1.1rem;
            font-weight: 600;
            color: #28a745;
        }

        /* Remarks */
        .payroll-table td:nth-child(13) span {
            font-size: 0.95rem;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
        }

        .btn-view, .btn-edit, .btn-download, .btn-delete {
            padding: 6px 10px;
            font-size: 0.9rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
        }

        .btn-view {
            background-color: #3498db;
        }

        .btn-edit {
            background-color: #f39c12;
        }

        .btn-download {
            background-color: #2ecc71;
        }
        
        .btn-delete {
            background-color: #dc3545;
        }

        .btn-view:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }

        .btn-edit:hover {
            background-color: #e67e22;
            transform: translateY(-2px);
        }

        .btn-download:hover {
            background-color: #27ae60;
            transform: translateY(-2px);
        }
        
        .btn-delete:hover {
            background-color: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }

        /* Scrollbar Styling */
        .table-responsive::-webkit-scrollbar {
            height: 10px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #2E7D32;
            border-radius: 5px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #1B5E20;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-state i {
            font-size: 3.5rem;
            color: #d1f0eb;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: 1.1rem;
            color: #666;
            margin: 5px 0;
        }

        .am-time {
            font-size: 0.85rem;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
            display: inline-block;
        }
        
        .pm-night-time {
            font-size: 0.85rem;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
            display: inline-block;
            margin: 0 5px;
        }
        
        .pm-night-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .pm-night-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        
        .pm-night-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #666;
            min-width: 35px;
            text-align: right;
        }
        
        .pm-night-divider {
            color: #95a5a6;
            font-size: 0.7rem;
            margin: 0 2px;
        }
        
        .payroll-table td[data-label="AM In"],
        .payroll-table td[data-label="AM Out"] {
            padding: 8px 5px;
            text-align: center;
        }
        
        .total-hours-main {
            font-size: 0.85rem;
            font-weight: 600;
            color: #28a745;
            background: #e8f5e9;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            white-space: nowrap;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        
        .btn-view {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.8rem;
            min-width: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            text-decoration: none;
        }
        
        .btn-view:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-edit {
            background-color: #f39c12;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.8rem;
            min-width: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
        }
        
        .btn-edit:hover {
            background-color: #e67e22;
            transform: translateY(-2px);
        }
        
        .btn-download {
            background-color: #2ecc71;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.8rem;
            min-width: 45px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
        }

        .btn-download:hover {
            background-color: #27ae60;
            transform: translateY(-2px);
        }
        
        .btn-delete {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.8rem;
            min-width: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
        }

        .btn-delete:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }
        
        @media (max-width: 1200px) {
            .payroll-table {
                font-size: 0.85rem;
            }
            
            .am-time, .pm-night-time {
                font-size: 0.8rem;
            }
            
            .btn-view, .btn-edit, .btn-download, .btn-delete {
                padding: 4px 6px;
                font-size: 0.7rem;
                min-width: 35px;
            }
        }

        .time-display-box.validation-error {
            border-color: #e74c3c !important;
            background-color: #fef5f5 !important;
            animation: shake 0.3s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        .field-error-message {
            color: #e74c3c;
            font-size: 0.8rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 4px;
            animation: slideDown 0.3s ease;
        }

        .field-error-message i {
            font-size: 0.9rem;
        }

        .time-input-group .field-error-message {
            position: absolute;
            bottom: -20px;
            left: 0;
            white-space: nowrap;
        }

        #notificationArea {
            margin-bottom: 20px;
        }

        .attendance-notification {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        .attendance-notification.error {
            background-color: #fef5f5;
            border: 1px solid #e74c3c;
            color: #c0392b;
        }

        .attendance-notification.error i {
            color: #e74c3c;
            font-size: 1.2rem;
        }

        .attendance-notification.success {
            background-color: #f0f9f0;
            border: 1px solid #27ae60;
            color: #27ae60;
        }

        .attendance-notification.success i {
            color: #27ae60;
        }

        .attendance-notification.info {
            background-color: #e8f4fd;
            border: 1px solid #3498db;
            color: #2980b9;
        }

        .attendance-notification.info i {
            color: #3498db;
        }

        .attendance-notification.warning {
            background-color: #fef9e7;
            border: 1px solid #f39c12;
            color: #e67e22;
        }

        .attendance-notification.warning i {
            color: #f39c12;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Night session header with optional text */
        .time-section-header span {
            font-size: 0.8rem;
            font-weight: normal;
            color: #666;
            margin-left: 10px;
        }

        /* Delete Modal Styles */
        .delete-modal .modal-content {
            width: 400px;
        }
        
        .delete-modal .modal-body {
            text-align: center;
            padding: 30px;
        }
        
        .delete-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .delete-warning {
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 0.9rem;
        }

        /* View Workday Container */
        .view-workday-container {
            margin-bottom: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-radius: 12px;
            border: 1px solid #bbdefb;
            display: none;
        }
        
        .view-workday-container div {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .view-workday-container .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #75e6da;
            display: inline-block;
        }

        /* Report Modal Styles */
        .report-option {
            transition: all 0.3s ease;
        }

        .report-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Ensure modal is visible */
        #reportModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
        }

        #reportModal.show {
            display: flex !important;
        }

        #reportModal .modal-content {
            background: white;
            border-radius: 16px;
            width: 95%;
            max-width: 1600px;
            max-height: 90vh;
            margin: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Report Preview Table */
        .report-preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.85rem;
        }

        .report-preview-table th {
            background: #2E7D32;
            color: white;
            padding: 10px 5px;
            font-weight: 600;
            text-align: center;
            border: 1px solid #1B5E20;
            white-space: nowrap;
        }

        .report-preview-table td {
            padding: 8px 5px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }

        .report-preview-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .report-preview-table .status-present {
            background-color: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }

        .report-preview-table .status-absent {
            background-color: #f8d7da;
            color: #721c24;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }

        .report-preview-table .status-leave {
            background-color: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }

        .report-preview-table .status-no-record {
            background-color: #e9ecef;
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }

        /* Improved Filter Section - gaya ng salary slip */
        .filter-section {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .filter-row {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-label i {
            margin-right: 8px;
            color: #75e6da;
            font-size: 1rem;
        }

        .filter-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }

        .filter-input:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 4px rgba(117, 230, 218, 0.2);
            outline: none;
        }

        .btn-preview {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 10px rgba(117, 230, 218, 0.3);
        }

        .btn-preview:hover {
            background: linear-gradient(135deg, #5fd9c9, #4fc5b5);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(117, 230, 218, 0.4);
        }

        .btn-excel {
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 8px rgba(39, 174, 96, 0.2);
        }

        .btn-excel:hover {
            background: linear-gradient(135deg, #219a52, #1e8449);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-print {
            background: linear-gradient(135deg, #7f8c8d, #6c7a7d);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 8px rgba(127, 140, 141, 0.2);
        }

        .btn-print:hover {
            background: linear-gradient(135deg, #6c7a7d, #5a6668);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(127, 140, 141, 0.3);
        }

        .action-buttons-container {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #75e6da;
        }

        .report-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .report-title i {
            color: #75e6da;
            margin-right: 10px;
        }

        .report-date-range {
            font-size: 1rem;
            color: #666;
            background: #f8f9fa;
            padding: 8px 20px;
            border-radius: 25px;
            border: 1px solid #e0e0e0;
        }

        .employee-select-wrapper {
            position: relative;
        }

        .employee-select-search {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: #95a5a6;
            pointer-events: none;
        }

        /* Custom Calendar Styles - gaya ng salary slip */
        .custom-date-picker {
            position: relative;
            width: 100%;
        }

        .date-input-group {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            height: 45px;
            padding: 0 10px;
        }

        .date-input-group:hover {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
        }

        .date-input-group .date-icon {
            color: #75e6da;
            font-size: 1rem;
            margin-right: 8px;
        }

        .date-input-group .date-display {
            border: none;
            background: transparent;
            flex: 1;
            padding: 0;
            font-size: 0.95rem;
            cursor: pointer;
            outline: none;
            color: #2c3e50;
        }

        .date-input-group .dropdown-icon {
            color: #95a5a6;
            font-size: 0.8rem;
            transition: transform 0.3s;
        }

        .calendar-dropdown {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            width: 300px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
            padding: 15px;
        }

        .calendar-dropdown.show {
            display: block;
            animation: slideDown 0.2s ease;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .calendar-header .month-year {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .calendar-header .nav-buttons {
            display: flex;
            gap: 5px;
        }

        .calendar-header .nav-btn {
            width: 30px;
            height: 30px;
            border: none;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2c3e50;
            transition: all 0.2s;
        }

        .calendar-header .nav-btn:hover {
            background: #75e6da;
            color: white;
        }

        .calendar-selectors {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .calendar-selectors select {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #2c3e50;
            background: white;
            cursor: pointer;
            outline: none;
        }

        .calendar-selectors select:hover {
            border-color: #75e6da;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 600;
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 8px;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            margin-bottom: 15px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: #2c3e50;
            background: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .calendar-day:hover {
            background: #e8f5e9;
            color: #2E7D32;
        }

        .calendar-day.selected {
            background: #75e6da;
            color: white;
            font-weight: 600;
        }

        .calendar-day.today {
            border: 2px solid #75e6da;
            font-weight: 600;
        }

        .calendar-day.other-month {
            color: #bdc3c7;
        }

        .calendar-day.weekend {
            color: #e74c3c;
        }

        .calendar-footer {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
        }

        .calendar-footer .btn-today,
        .calendar-footer .btn-clear {
            flex: 1;
            padding: 8px 0;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .calendar-footer .btn-today {
            background: #75e6da;
            color: white;
        }

        .calendar-footer .btn-today:hover {
            background: #5fd9c9;
        }

        .calendar-footer .btn-clear {
            background: #f8f9fa;
            color: #7f8c8d;
            border: 1px solid #e0e0e0;
        }

        .calendar-footer .btn-clear:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Workday Type Required Styles */
        .workday-type-container.required .workday-type-select {
            border-color: #75e6da;
            background-color: #fff;
        }

        .workday-type-container.required .workday-type-label:after {
            content: " *";
            color: #e74c3c;
            font-weight: bold;
        }

        .workday-type-container.required .workday-type-select:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.3);
        }

        .workday-error-message {
            color: #e74c3c;
            font-size: 0.85rem;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
            animation: slideDown 0.3s ease;
            padding: 8px 12px;
            background-color: #fef5f5;
            border-radius: 6px;
            border-left: 3px solid #e74c3c;
        }

        .workday-error-message i {
            font-size: 1rem;
        }
    </style>
</head>
<body>

<?php 
include("./includes/header.php"); 
?>

<main class="content">
    <div class="content-wrapper">
        <!-- Toast Container -->
        <div id="toastContainer" class="toast-container"></div>
        
        <!-- Display success/error messages -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Holiday Info Bar - Keep for reference -->
        <?php if ($is_holiday && $holiday_data): ?>
        <div class="holiday-info-bar">
            <i class="fas fa-gift"></i>
            <div class="holiday-info-content">
                <div class="holiday-info-title"><?= htmlspecialchars($holiday_data['holiday_name']) ?> (<?= htmlspecialchars($holiday_data['workday_type']) ?>)</div>
                <div class="holiday-info-desc"><?= htmlspecialchars($holiday_data['description'] ?? 'Today is a declared holiday') ?></div>
            </div>
            <div class="holiday-info-badge">
                <i class="fas fa-calendar-check"></i> Holiday
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Controls Container -->
        <div class="controls-container">
            <div class="search-section">
                <form method="GET" action="attendance.php" id="mainAttendanceForm" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span>Date:</span>
                        
                        <!-- MAIN DATE PICKER WITH DROPDOWN CALENDAR -->
                        <div class="main-date-picker-wrapper">
                            <div class="main-date-input-group">
                                <i class="fas fa-calendar-alt main-date-icon"></i>
                                <input type="text" 
                                       id="mainDateField" 
                                       class="main-date-field" 
                                       value="<?= date('m/d/Y', strtotime($selected_date)) ?>"
                                       placeholder="MM/DD/YYYY"
                                       autocomplete="off"
                                       readonly
                                       onclick="toggleMainCalendar()">
                                <input type="hidden" id="selectedDate" name="date" value="<?= $selected_date ?>">
                                <button type="button" class="main-calendar-dropdown-btn" onclick="toggleMainCalendar()">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <!-- Main Calendar Dropdown -->
                            <div class="main-calendar-wrapper" id="mainCalendarWrapper">
                                <div class="main-calendar-box">
                                    <div class="main-calendar-header">
                                        <div class="main-calendar-month-year" id="mainCalendarMonthYear">
                                            <?= date('F Y', strtotime($selected_date)) ?>
                                        </div>
                                        <div class="main-calendar-nav">
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMainMonth(-1)">‹</button>
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMainMonth(1)">›</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Month and Year Dropdown Selectors -->
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
                                        <div class="weekday">Sun</div>
                                        <div class="weekday">Mon</div>
                                        <div class="weekday">Tue</div>
                                        <div class="weekday">Wed</div>
                                        <div class="weekday">Thu</div>
                                        <div class="weekday">Fri</div>
                                        <div class="weekday">Sat</div>
                                    </div>
                                    
                                    <div class="main-calendar-days-grid" id="mainCalendarDaysGrid">
                                        <!-- Days will be populated here by JavaScript -->
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
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex: 1; position: relative;">
                        <div class="search-input-wrapper" style="flex: 1;">
                            <input type="text" id="mainSearchInput" name="search" placeholder="Search employee by name or ID..." 
                                   class="search-bar" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                            <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #95a5a6;"></i>
                        </div>
                    </div>

                    <button type="button" class="add-attendance-btn" onclick="openAddAttendanceModal()">
                        <i class="fas fa-plus"></i> Add Attendance
                    </button>
                    
                    <!-- REPORT BUTTON - Fixed to prevent form submission -->
                    <div class="report-btn-container">
                        <button type="button" class="report-btn" onclick="openReportModal(event)">
                            <i class="fas fa-file-export"></i> Generate Report
                        </button>
                    </div>
                </form>
                
                <!-- Live Search Results -->
                <div id="liveSearchContainer" class="live-search-container">
                    <div id="liveSearchResults" class="live-search-results">
                        <!-- Results will be populated here by JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Filter Badge -->
        <?php if ($employee_filter_id > 0 && !empty($selected_employee_name)): ?>
        <div style="margin: 0 0 15px 20px; display: flex; align-items: center;">
            <div class="employee-filter-badge">
                <i class="fas fa-user-circle"></i>
                <span>Viewing: <strong><?= htmlspecialchars($selected_employee_name) ?></strong> (ID: <?= $employee_filter_id ?>) - <?= htmlspecialchars($selected_employee_position) ?></span>
                <a href="attendance.php?date=<?= $selected_date ?>" class="remove-filter-btn" title="Clear filter">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Table Container - With Night Session as Optional -->
        <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <div class="payroll-table-container" style="min-width: 2000px;">
                <table class="payroll-table" style="width: 100%; table-layout: fixed;">
                    <thead>
                        <tr>
                            <th style="width: 210px;"><i class="fas fa-user"></i> Employee</th>
                            <th style="width: 140px;"><i class="fas fa-calendar"></i> Date</th>
                            <th style="width: 140px;"><i class="fas fa-sun"></i> AM Status</th>
                            <th style="width: 90px;"><i class="fas fa-sun"></i> AM In</th>
                            <th style="width: 100px;"><i class="fas fa-sun"></i> AM Out</th>
                            <th style="width: 140px;"><i class="fas fa-moon"></i> PM Status</th>
                            <th style="width: 155px;"><i class="fas fa-clock"></i> PM/Night In</th>
                            <th style="width: 155px;"><i class="fas fa-clock"></i> PM/Night Out</th>
                            <th style="width: 150px;"><i class="fas fa-star"></i> Night Status</th>
                            <th style="width: 120px;"><i class="fas fa-umbrella-beach"></i> Leave</th>
                            <th style="width: 150px;"><i class="fas fa-calendar-day"></i> Workday Type</th>
                            <th style="width: 150px;"><i class="fas fa-clock"></i> Total Hours</th>
                            <th style="width: 110px;"><i class="fas fa-location-dot"></i> Site</th>
                            <th style="width: 120px;"><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if($result && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                            // Handle NULL values
                            $row['middle_name'] = $row['middle_name'] ?? '';
                            $row['time_in_am'] = $row['time_in_am'] ?? '';
                            $row['time_out_am'] = $row['time_out_am'] ?? '';
                            $row['time_in_pm'] = $row['time_in_pm'] ?? '';
                            $row['time_out_pm'] = $row['time_out_pm'] ?? '';
                            $row['time_in_night'] = $row['time_in_night'] ?? '';
                            $row['time_out_night'] = $row['time_out_night'] ?? '';
                            $row['site'] = $row['site'] ?? '';
                            $row['status'] = $row['status'] ?? 'No Record';
                            $row['pm_status'] = $row['pm_status'] ?? 'No Record';
                            $row['night_status'] = $row['night_status'] ?? 'No Record';
                            $row['leave_type'] = $row['leave_type'] ?? '';
                            $row['workday_type'] = $row['workday_type'] ?? '';
                            $row['position'] = $row['position'] ?? 'N/A';
                            
                            // Display times
                            $time_in_am_display = "-";
                            $time_out_am_display = "-";
                            $time_in_pm_display = "-";
                            $time_out_pm_display = "-";
                            $time_in_night_display = "-";
                            $time_out_night_display = "-";

 // For AM times - FIXED to handle 00:00:00 properly
if (!empty($row['time_in_am'])) {
    if ($row['time_in_am'] == '00:00:00') {
        $time_in_am_display = '12:00 AM';
    } else {
        $time_in_am = strtotime($row['time_in_am']);
        $time_in_am_display = date('h:i A', $time_in_am);
    }
} else {
    $time_in_am_display = '-';
}

if (!empty($row['time_out_am'])) {
    if ($row['time_out_am'] == '00:00:00') {
        $time_out_am_display = '12:00 AM';
    } else {
        $time_out_am = strtotime($row['time_out_am']);
        $time_out_am_display = date('h:i A', $time_out_am);
    }
} else {
    $time_out_am_display = '-';
}

// For PM times - FIXED to handle 00:00:00 properly
if (!empty($row['time_in_pm'])) {
    if ($row['time_in_pm'] == '00:00:00') {
        $time_in_pm_display = '12:00 AM';
    } else {
        $time_in_pm = strtotime($row['time_in_pm']);
        $time_in_pm_display = date('h:i A', $time_in_pm);
    }
} else {
    $time_in_pm_display = '-';
}

if (!empty($row['time_out_pm'])) {
    if ($row['time_out_pm'] == '00:00:00') {
        $time_out_pm_display = '12:00 AM';
    } else {
        $time_out_pm = strtotime($row['time_out_pm']);
        $time_out_pm_display = date('h:i A', $time_out_pm);
    }
} else {
    $time_out_pm_display = '-';
}

// For Night times - FIXED to handle 00:00:00 properly
if (!empty($row['time_in_night'])) {
    if ($row['time_in_night'] == '00:00:00') {
        $time_in_night_display = '12:00 AM';
    } else {
        $time_in_night = strtotime($row['time_in_night']);
        $time_in_night_display = date('h:i A', $time_in_night);
    }
} else {
    $time_in_night_display = '-';
}

if (!empty($row['time_out_night'])) {
    if ($row['time_out_night'] == '00:00:00') {
        $time_out_night_display = '12:00 AM';
    } else {
        $time_out_night = strtotime($row['time_out_night']);
        $time_out_night_display = date('h:i A', $time_out_night);
    }
} else {
    $time_out_night_display = '-';
}
                            
                            // Determine status classes - INDEPENDENT SESSIONS
                            $am_status_class = 'status-no-record';
                            $am_status_text = 'No Record';
                            $pm_status_class = 'status-no-record';
                            $pm_status_text = 'No Record';
                            $night_status_class = 'status-no-record';
                            $night_status_text = 'No Record';

                            $has_record = !empty($row['date']);

                            if (!$has_record) {
                                // No record at all - all "No Record"
                                $am_status_class = 'status-no-record';
                                $am_status_text = 'No Record';
                                $pm_status_class = 'status-no-record';
                                $pm_status_text = 'No Record';
                                $night_status_class = 'status-no-record';
                                $night_status_text = 'No Record';
                            } else {
                                // Check if there are ANY time records for each session
                                $has_am_time = (!empty($row['time_in_am']) && $row['time_in_am'] != '00:00:00') || 
                                               (!empty($row['time_out_am']) && $row['time_out_am'] != '00:00:00');
                                $has_pm_time = (!empty($row['time_in_pm']) && $row['time_in_pm'] != '00:00:00') || 
                                               (!empty($row['time_out_pm']) && $row['time_out_pm'] != '00:00:00');
                                $has_night_time = (!empty($row['time_in_night']) && $row['time_in_night'] != '00:00:00') || 
                                                  (!empty($row['time_out_night']) && $row['time_out_night'] != '00:00:00');
                                
                                // AM STATUS - use status field
                                if (!empty($row['status'])) {
                                    if ($row['status'] == 'Present') {
                                        $am_status_class = 'status-present';
                                        $am_status_text = 'Present';
                                    } elseif ($row['status'] == 'Absent') {
                                        $am_status_class = 'status-absent';
                                        $am_status_text = 'Absent';
                                    } elseif ($row['status'] == 'On Leave') {
                                        $am_status_class = 'status-leave';
                                        $am_status_text = 'On Leave';
                                    }
                                } else {
                                    // No status set - determine based on times
                                    if ($has_am_time) {
                                        $am_status_class = 'status-present';
                                        $am_status_text = 'Present';
                                    } else {
                                        $am_status_class = 'status-no-record';
                                        $am_status_text = 'No Record';
                                    }
                                }
                                
                                // PM STATUS - use pm_status field
                                if (!empty($row['pm_status'])) {
                                    if ($row['pm_status'] == 'Present') {
                                        $pm_status_class = 'status-present';
                                        $pm_status_text = 'Present';
                                    } elseif ($row['pm_status'] == 'Absent') {
                                        $pm_status_class = 'status-absent';
                                        $pm_status_text = 'Absent';
                                    } elseif ($row['pm_status'] == 'On Leave') {
                                        $pm_status_class = 'status-leave';
                                        $pm_status_text = 'On Leave';
                                    }
                                } else {
                                    // No pm_status set - determine based on times
                                    if ($has_pm_time) {
                                        $pm_status_class = 'status-present';
                                        $pm_status_text = 'Present';
                                    } else {
                                        $pm_status_class = 'status-no-record';
                                        $pm_status_text = 'No Record';
                                    }
                                }
                                
                                // NIGHT STATUS - use night_status field
                                if (!empty($row['night_status'])) {
                                    if ($row['night_status'] == 'Present') {
                                        $night_status_class = 'status-present';
                                        $night_status_text = 'Present';
                                    } elseif ($row['night_status'] == 'Absent') {
                                        $night_status_class = 'status-absent';
                                        $night_status_text = 'Absent';
                                    } elseif ($row['night_status'] == 'On Leave') {
                                        $night_status_class = 'status-leave';
                                        $night_status_text = 'On Leave';
                                    }
                                } else {
                                    // No night_status set - determine based on times
                                    if ($has_night_time) {
                                        $night_status_class = 'status-present';
                                        $night_status_text = 'Present';
                                    } else {
                                        $night_status_class = 'status-no-record';
                                        $night_status_text = 'No Record';
                                    }
                                }
                            }
                            
                            // Calculate hours with night session
                            $total_hours = calculateTotalHours(
                                $row['time_in_am'], $row['time_out_am'], 
                                $row['time_in_pm'], $row['time_out_pm'],
                                $row['time_in_night'], $row['time_out_night']
                            );
                            ?>
                            <tr>
                                <td data-label="Employee" style="padding: 8px 5px;">
                                    <div class="payroll-info" style="font-size: 0.8rem;">
                                        <div><strong>Name:</strong> <?= htmlspecialchars(
                                            $row['first_name'] . " " . 
                                            (!empty($row['middle_name']) ? $row['middle_name'] . " " : "") . 
                                            $row['last_name']
                                        ) ?></div>
                                        <div><strong>ID:</strong> <?= $row['employee_id'] ?></div>
                                        <div><strong>Position:</strong> <?= htmlspecialchars($row['position']) ?></div>
                                    </div>
                                </td>
                                <td data-label="Date" style="text-align: center; padding: 8px 5px;">
                                    <div style="font-size: 0.9rem; font-weight: 500;">
                                        <?php 
                                        if (!empty($row['date'])) {
                                            $date_obj = strtotime($row['date']);
                                            echo date('F j, Y', $date_obj);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #666; font-style: italic;">
                                        <?= !empty($row['date']) ? date('l', strtotime($row['date'])) : '-' ?>
                                    </div>
                                </td>
                                <td data-label="AM Status" style="text-align: center; padding: 8px 5px;">
                                    <span class="<?= $am_status_class ?>" style="font-size: 0.7rem; padding: 2px 5px;">
                                        <?= $am_status_text ?>
                                    </span>
                                </td>
                                <td data-label="AM In" style="text-align: center; padding: 8px 5px;">
                                    <span style="font-size: 0.8rem;"><?= $time_in_am_display ?></span>
                                </td>
                                <td data-label="AM Out" style="text-align: center; padding: 8px 5px;">
                                    <span style="font-size: 0.8rem;"><?= $time_out_am_display ?></span>
                                </td>
                                <td data-label="PM Status" style="text-align: center; padding: 8px 5px;">
                                    <span class="<?= $pm_status_class ?>" style="font-size: 0.7rem; padding: 2px 5px;">
                                        <?= $pm_status_text ?>
                                    </span>
                                </td>
                                <td data-label="PM/Night In" style="text-align: left; padding: 8px 5px;">
                                    <div style="display: flex; flex-direction: column; gap: 3px;">
                                        <?php if ($time_in_pm_display != '-'): ?>
                                        <div style="font-size: 0.8rem;">
                                            <span style="font-weight: 600; color: #f39c12;">PM:</span>
                                            <span><?= $time_in_pm_display ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($time_in_night_display != '-'): ?>
                                        <div style="font-size: 0.8rem;">
                                            <span style="font-weight: 600; color: #9b59b6;">Night:</span>
                                            <span><?= $time_in_night_display ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($time_in_pm_display == '-' && $time_in_night_display == '-'): ?>
                                            <span style="color:#adb5bd; font-size: 0.8rem;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="PM/Night Out" style="text-align: left; padding: 8px 5px;">
                                    <div style="display: flex; flex-direction: column; gap: 3px;">
                                        <?php if ($time_out_pm_display != '-'): ?>
                                        <div style="font-size: 0.8rem;">
                                            <span style="font-weight: 600; color: #f39c12;">PM:</span>
                                            <span><?= $time_out_pm_display ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($time_out_night_display != '-'): ?>
                                        <div style="font-size: 0.8rem;">
                                            <span style="font-weight: 600; color: #9b59b6;">Night:</span>
                                            <span><?= $time_out_night_display ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($time_out_pm_display == '-' && $time_out_night_display == '-'): ?>
                                            <span style="color:#adb5bd; font-size: 0.8rem;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Night Status" style="text-align: center; padding: 8px 5px;">
                                    <span class="<?= $night_status_class ?>" style="font-size: 0.7rem; padding: 2px 5px;">
                                        <?= $night_status_text ?>
                                    </span>
                                </td>
                                <td data-label="Leave Type" style="text-align: center; padding: 8px 5px;">
                                    <?php if (!empty($row['leave_type'])): ?>
                                        <?php
                                        $leave_class = '';
                                        if (strpos(strtolower($row['leave_type']), 'sick') !== false) {
                                            $leave_class = 'sick';
                                        } elseif (strpos(strtolower($row['leave_type']), 'vacation') !== false) {
                                            $leave_class = 'vacation';
                                        } elseif (strpos(strtolower($row['leave_type']), 'emergency') !== false) {
                                            $leave_class = 'emergency';
                                        }
                                        ?>
                                        <span class="leave-type-badge <?= $leave_class ?>"><?= htmlspecialchars($row['leave_type']) ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 0.7rem; color: #adb5bd;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Workday Type" style="text-align: center; padding: 8px 5px;">
                                    <?php if (!empty($row['workday_type'])): ?>
                                        <span class="workday-type-badge"><?= htmlspecialchars($row['workday_type']) ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 0.7rem; color: #adb5bd;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total Hrs" style="text-align: center; padding: 8px 5px;">
                                    <?php if ($total_hours != '0.00'): ?>
                                        <span style="font-size: 0.8rem; font-weight: 600; color: #28a745;">
                                            <?= $total_hours ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#adb5bd; font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                              <td data-label="Site" style="text-align: center; padding: 8px 5px;">
    <span style="font-size: 0.7rem; background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 12px; display: inline-block;">
        <?= !empty($row['site']) ? htmlspecialchars($row['site']) : '-' ?>
    </span>
</td>
                                <td data-label="Actions" style="text-align: center; padding: 8px 5px;">
                                    <div class="action-buttons" style="display: flex; gap: 3px; justify-content: center;">
                                        <button type="button" class="btn-view" style="padding: 4px 6px; font-size: 0.7rem;" 
                                                onclick="viewAttendance(<?= $row['employee_id'] ?>, '<?= $selected_date ?>', '<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <button type="button" class="btn-edit" style="padding: 4px 6px; font-size: 0.7rem;" 
                                                onclick="editAttendance(<?= $row['employee_id'] ?>, '<?= $selected_date ?>', '<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php
                                        $download_month = date('m', strtotime($selected_date));
                                        $download_year = date('Y', strtotime($selected_date));
                                        ?>
                                        <a href="download_monthly_attendance.php?employee_id=<?= $row['employee_id'] ?>&month=<?= $download_month ?>&year=<?= $download_year ?>" 
                                           class="btn-download" style="padding: 4px 6px; font-size: 0.7rem;" 
                                           title="Download Monthly Attendance">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        
                                        <?php if (!empty($row['date'])): ?>
                                        <button type="button" class="btn-delete" style="padding: 4px 6px; font-size: 0.7rem;" 
                                                onclick="confirmDelete(<?= $row['employee_id'] ?>, '<?= $selected_date ?>', '<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="14" style="text-align: center; padding: 30px;">
                                <div class="empty-state">
                                    <?php if ($employee_filter_id > 0): ?>
                                        <i class="fas fa-user-clock" style="font-size: 2rem;"></i>
                                        <p>No attendance records found for <strong><?= htmlspecialchars($selected_employee_name) ?></strong> on <?= date('F j, Y', strtotime($selected_date)) ?>.</p>
                                    <?php elseif (!empty($search)): ?>
                                        <i class="fas fa-search" style="font-size: 2rem;"></i>
                                        <p>No employees found matching <strong>'<?= htmlspecialchars($search) ?>'</strong> for <?= date('F j, Y', strtotime($selected_date)) ?>.</p>
                                    <?php else: ?>
                                        <i class="fas fa-calendar-times" style="font-size: 2rem;"></i>
                                        <p>No attendance records found for <?= date('F j, Y', strtotime($selected_date)) ?>.</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit Attendance Modal -->
        <div class="modal" id="addAttendanceModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title" id="modalTitle"><i class="fas fa-calendar-plus"></i> Add Attendance Record</h3>
                    <button type="button" class="modal-close" onclick="closeAddAttendanceModal()">×</button>
                </div>
                <div class="modal-body">
                    <div id="notificationArea"></div>
                    
                    <form id="addAttendanceForm" method="POST" action="add_attendance.php">
                        <input type="hidden" id="attendanceId" name="attendance_id" value="">
                        
                        <!-- EMPLOYEE SELECTION -->
                        <div class="form-group">
                            <label class="form-label required"><i class="fas fa-user"></i> Employee</label>
                            
                            <!-- Employee Search Wrapper -->
                            <div class="employee-search-wrapper">
                                <!-- Search Input -->
                                <div class="employee-search-container" id="employeeSearchContainer">
                                    <div class="search-input-wrapper">
                                        <i class="fas fa-search search-icon"></i>
                                        <input type="text" 
                                               id="employeeSearchInput" 
                                               class="form-control employee-search-input" 
                                               placeholder="Type to search employee by name or ID..."
                                               autocomplete="off">
                                        <i class="fas fa-times clear-search" id="clearSearch" onclick="clearEmployeeSearch()" style="display: none;"></i>
                                    </div>
                                    
                                    <!-- Employee Results Dropdown -->
                                    <div class="employee-results-dropdown" id="employeeResultsDropdown">
                                        <div class="results-header">
                                            <span id="resultsCount">0 employees found</span>
                                        </div>
                                        <div class="results-list" id="employeeResultsList">
                                            <!-- Results will be populated here -->
                                        </div>
                                        <div class="results-footer">
                                            <span class="text-muted">Start typing to search employees</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Hidden select for form submission -->
                            <select id="employeeSelect" name="employee_id" class="hidden-select" required>
                                <option value="">Select Employee</option>
                                <?php 
                                $all_employees = $conn->query("SELECT id, first_name, middle_name, last_name, position FROM employees ORDER BY last_name, first_name");
                                if ($all_employees && $all_employees->num_rows > 0) {
                                    while($emp = $all_employees->fetch_assoc()): 
                                        $emp['middle_name'] = $emp['middle_name'] ?? '';
                                        $full_name = htmlspecialchars($emp['first_name'] . ' ' . 
                                                    (!empty($emp['middle_name']) ? $emp['middle_name'] . ' ' : '') . 
                                                    $emp['last_name']);
                                        $position = $emp['position'] ?? 'N/A';
                                ?>
                                    <option value="<?= $emp['id'] ?>" data-name="<?= strtolower($full_name) ?>" data-id="<?= $emp['id'] ?>" data-position="<?= htmlspecialchars($position) ?>">
                                        <?= $full_name ?> (ID: <?= $emp['id'] ?>) - <?= $position ?>
                                    </option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                            
                            <!-- SELECTED EMPLOYEE CARD -->
                            <div class="selected-employee-card" id="selectedEmployeeCard" style="display: none;">
                                <div class="selected-employee-info">
                                    <div class="selected-employee-avatar">
                                        <i class="fas fa-user-circle"></i>
                                    </div>
                                    <div class="selected-employee-details">
                                        <span class="selected-employee-name" id="selectedEmployeeName"></span>
                                        <span class="selected-employee-id" id="selectedEmployeeIdDisplay">
                                            <i class="fas fa-id-badge"></i> ID: <span id="selectedEmployeeId">--</span>
                                        </span>
                                        <span class="selected-employee-position" id="selectedEmployeePosition" style="font-size: 0.8rem; color: #666;">
                                            <i class="fas fa-briefcase"></i> <span id="selectedEmployeePositionText">--</span>
                                        </span>
                                    </div>
                                </div>
                                <button type="button" class="btn-change-employee" onclick="changeEmployee()" title="Change Employee">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <div id="employeeNameDisplay" style="display: none;"></div>
                        </div>
                        
<!-- Date and ID Row - Fixed (remove duplicate container) -->
<div class="form-row" style="display: flex; gap: 20px; align-items: stretch; margin-bottom: 20px;">
    <!-- Date Column -->
    <div style="flex: 1;">
        <label class="form-label required"><i class="fas fa-calendar"></i> Date</label>
        
        <!-- MODAL DATE PICKER - Fixed -->
        <div class="date-picker-wrapper" style="position: relative; width: 100%;">
            <div class="date-input-group" style="display: flex; align-items: center; background: white; border: 2px solid #e0e0e0; border-radius: 8px; height: 48px; padding: 0 10px; cursor: pointer;" onclick="toggleModalCalendar()">
                <i class="fas fa-calendar-alt" style="color: var(--sidebar-green); font-size: 1rem; margin-right: 8px;"></i>
                <input type="text" 
                       id="attendanceDateField" 
                       style="border: none; background: transparent; flex: 1; padding: 0; font-size: 0.95rem; cursor: pointer; outline: none; color: #2c3e50;" 
                       value="<?= date('m/d/Y', strtotime($selected_date)) ?>"
                       placeholder="MM/DD/YYYY"
                       autocomplete="off"
                       readonly>
                <input type="hidden" id="attendanceDate" name="date" value="<?= $selected_date ?>">
                <i class="fas fa-chevron-down" style="color: #95a5a6; font-size: 0.8rem;"></i>
            </div>
            
 <!-- Modal Calendar - Reduced Size -->
<div class="modal-calendar-wrapper" id="modalCalendarWrapper" style="position: absolute; top: calc(100% + 5px); left: 0; right: 0; background: white; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 12px rgba(0,0,0,0.15); z-index: 2000; display: none; width: 280px;">
    <div class="modal-calendar-box">
        <div class="modal-calendar-header" style="padding: 10px;">
            <div class="modal-calendar-month-year" id="modalCalendarMonthYear" style="font-size: 0.9rem;">
                <?= date('F Y', strtotime($selected_date)) ?>
            </div>
            <div class="modal-calendar-nav">
                <button type="button" class="modal-calendar-nav-btn" onclick="navigateModalMonth(-1)" style="width: 24px; height: 24px; font-size: 1rem;">‹</button>
                <button type="button" class="modal-calendar-nav-btn" onclick="navigateModalMonth(1)" style="width: 24px; height: 24px; font-size: 1rem;">›</button>
            </div>
        </div>
        
        <div class="modal-calendar-selectors" style="padding: 8px 10px 5px 10px; gap: 8px;">
            <select id="modalMonthSelect" class="modal-calendar-select" onchange="changeModalMonthYear()" style="padding: 5px 8px; font-size: 0.8rem;">
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
            
            <select id="modalYearSelect" class="modal-calendar-select" onchange="changeModalMonthYear()" style="padding: 5px 8px; font-size: 0.8rem;">
                <?php for($y = date('Y') - 10; $y <= date('Y') + 10; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div class="modal-calendar-weekdays" style="padding: 6px; font-size: 0.7rem;">
            <div>Sun</div>
            <div>Mon</div>
            <div>Tue</div>
            <div>Wed</div>
            <div>Thu</div>
            <div>Fri</div>
            <div>Sat</div>
        </div>
        
        <div class="modal-calendar-days-grid" id="modalCalendarDaysGrid" style="padding: 5px; gap: 1px;">
            <!-- Days will be populated by JavaScript -->
        </div>
        
        <div class="modal-calendar-footer" style="padding: 6px;">
            <button type="button" class="modal-calendar-action-btn clear" onclick="clearModalDate()" style="padding: 4px 10px; font-size: 0.7rem;">
                <i class="fas fa-times"></i> Clear
            </button>
            <button type="button" class="modal-calendar-action-btn today" onclick="setModalToday()" style="padding: 4px 10px; font-size: 0.7rem;">
                <i class="fas fa-calendar-check"></i> Today
            </button>
        </div>
    </div>
</div>
        </div>
    </div>
    
    <!-- Employee ID Column Only (keep as is) -->
    <div style="flex: 0.8;">
        <label class="form-label"><i class="fas fa-id-badge"></i> Employee ID</label>
        <div class="date-display" id="employeeIdDisplay2" style="padding: 12px 15px; background: white; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem; font-weight: 500; color: #2c3e50; height: 48px; display: flex; align-items: center;">--</div>
    </div>
</div>
                        <!-- STANDALONE LEAVE TYPE DROPDOWN - Always visible with #75e6da theme -->
                        <div class="leave-type-container" id="leaveTypeContainer">
                            <label class="leave-type-label">
                                <i class="fas fa-umbrella-beach"></i> Leave Type (Optional)
                            </label>
                            <select id="leaveType" name="leave_type" class="leave-type-select">
                                <option value="">-- No Leave Selected --</option>
                                <option value="Sick Leave">Sick Leave</option>
                                <option value="Vacation Leave">Vacation Leave</option>
                                <option value="Emergency Leave">Emergency Leave</option>
                            </select>
                        </div>
                        
                        <!-- STANDALONE WORKDAY TYPE DROPDOWN - REQUIRED -->
                        <div class="workday-type-container" id="workdayTypeContainer">
                            <label class="workday-type-label required">
                                <i class="fas fa-calendar-day"></i> Workday Type <span style="color: #e74c3c;">*</span>
                            </label>
                            
                            <!-- Workday Type Dropdown Only - No Radio Button -->
                            <div class="workday-type-select-container">
                                <select id="workdayType" name="workday_type" class="workday-type-select" required>
                                    <option value="">-- Select Workday Type (Required) --</option>
                                    <option value="Ordinary Working Day">Ordinary Working Day</option>
                                    <option value="Rest Day / Sunday">Rest Day / Sunday</option>
                                    <option value="Special (Non-Working) Day">Special (Non-Working) Day</option>
                                    <option value="Special Day that falls on Rest Day">Special Day that falls on Rest Day</option>
                                    <option value="Regular Holiday">Regular Holiday</option>
                                    <option value="Regular Holiday on the Rest Day">Regular Holiday on the Rest Day</option>
                                    <option value="Double Holiday">Double Holiday</option>
                                    <option value="Double Holiday on the Rest Day">Double Holiday on the Rest Day</option>
                                </select>
                            </div>
                            
                            <div id="workdayError" class="workday-error-message" style="display: none;">
                                <i class="fas fa-exclamation-circle"></i> Please select a Workday Type
                            </div>
                            
                            <small style="display: block; margin-top: 5px; color: #666; font-size: 0.8rem;">
                                <i class="fas fa-info-circle"></i> Workday Type is required for all attendance records.
                            </small>
                        </div>
                        
                        <!-- AM Time Section - With #75e6da left border -->
                        <div class="time-section">
                            <div class="time-section-header">
                                <i class="fas fa-sun"></i>
                                <h4>Morning Session</h4>
                            </div>
                            
                            <!-- AM Status - Without On Leave option -->
                            <div class="am-status-container">
                                <label class="am-status-label"><i class="fas fa-sun"></i> AM Status</label>
                                <div class="status-options">
                                    <div class="status-option">
                                        <input type="radio" id="statusPresent" name="status" value="Present" class="status-radio">
                                        <label for="statusPresent" class="status-label">
                                            <span class="status-dot present"></span>
                                            <span>Present</span>
                                        </label>
                                    </div>
                                    
                                    <div class="status-option">
                                        <input type="radio" id="statusAbsent" name="status" value="Absent" class="status-radio">
                                        <label for="statusAbsent" class="status-label">
                                            <span class="status-dot absent"></span>
                                            <span>Absent</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-input-row">
                                <!-- AM Time In -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <span>Time In</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeInAmDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- AM
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeInAm" name="time_in_am">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_in_am')" id="timeInAmBtn">
                                            <i class="fas fa-clock"></i> Set AM
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeInAmError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> AM Time In is required when Present is selected
                                    </div>
                                </div>
                                
                                <!-- AM Time Out -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span>Time Out</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeOutAmDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- AM
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeOutAm" name="time_out_am">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_out_am')" id="timeOutAmBtn">
                                            <i class="fas fa-clock"></i> Set AM
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeOutAmError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> AM Time Out is required when Present is selected
                                    </div>
                                </div>
                            </div>
                            
                            <!-- AM Total Hours -->
                            <div class="am-total-hours" id="amTotalHours">
                                <i class="fas fa-clock"></i> AM Total: <span id="amHoursValue">0.00</span> hrs
                            </div>
                        </div>
                        
                        <!-- PM Time Section - With #75e6da left border -->
                        <div class="time-section">
                            <div class="time-section-header">
                                <i class="fas fa-moon"></i>
                                <h4>Afternoon Session</h4>
                            </div>
                            
                            <!-- PM Status - Without On Leave option -->
                            <div class="pm-status-container">
                                <label class="pm-status-label"><i class="fas fa-moon"></i> PM Status</label>
                                <div class="status-options">
                                    <div class="status-option">
                                        <input type="radio" id="pmStatusPresent" name="pm_status" value="Present" class="status-radio">
                                        <label for="pmStatusPresent" class="status-label">
                                            <span class="status-dot present"></span>
                                            <span>Present</span>
                                        </label>
                                    </div>
                                    
                                    <div class="status-option">
                                        <input type="radio" id="pmStatusAbsent" name="pm_status" value="Absent" class="status-radio">
                                        <label for="pmStatusAbsent" class="status-label">
                                            <span class="status-dot absent"></span>
                                            <span>Absent</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-input-row">
                                <!-- PM Time In -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <span>Time In</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeInPmDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- PM
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeInPm" name="time_in_pm">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_in_pm')" id="timeInPmBtn">
                                            <i class="fas fa-clock"></i> Set PM
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeInPmError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> PM Time In is required when Present is selected
                                    </div>
                                </div>
                                
                                <!-- PM Time Out -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span>Time Out</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeOutPmDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- PM
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeOutPm" name="time_out_pm">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_out_pm')" id="timeOutPmBtn">
                                            <i class="fas fa-clock"></i> Set PM
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeOutPmError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> PM Time Out is required when Present is selected
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PM Total Hours -->
                            <div class="pm-total-hours" id="pmTotalHours">
                                <i class="fas fa-clock"></i> PM Total: <span id="pmHoursValue">0.00</span> hrs
                            </div>
                        </div>
                        
                        <!-- Night Time Section - With #75e6da left border -->
                        <div class="time-section">
                            <div class="time-section-header">
                                <i class="fas fa-star"></i>
                                <h4>Night Session <span>(Optional)</span></h4>
                            </div>
                            
                            <!-- Night Status - Without On Leave option -->
                            <div class="pm-status-container">
                                <label class="pm-status-label"><i class="fas fa-star"></i> Night Status</label>
                                <div class="status-options">
                                    <div class="status-option">
                                        <input type="radio" id="nightStatusPresent" name="night_status" value="Present" class="status-radio">
                                        <label for="nightStatusPresent" class="status-label">
                                            <span class="status-dot present"></span>
                                            <span>Present</span>
                                        </label>
                                    </div>
                                    
                                    <div class="status-option">
                                        <input type="radio" id="nightStatusAbsent" name="night_status" value="Absent" class="status-radio">
                                        <label for="nightStatusAbsent" class="status-label">
                                            <span class="status-dot absent"></span>
                                            <span>Absent</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-input-row">
                                <!-- Night Time In -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <span>Time In</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeInNightDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- --
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeInNight" name="time_in_night">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_in_night')" id="timeInNightBtn">
                                            <i class="fas fa-clock"></i> Set Night
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeInNightError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> Night Time In is required when Present is selected
                                    </div>
                                </div>
                                
                                <!-- Night Time Out -->
                                <div class="time-input-group">
                                    <div class="time-input-label">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span>Time Out</span>
                                    </div>
                                    <div class="time-input-controls">
                                        <div class="time-display-box empty" id="timeOutNightDisplay">
                                            <div class="time-display-content">
                                                <i class="far fa-clock"></i> --:-- --
                                            </div>
                                        </div>
                                        <input type="hidden" id="timeOutNight" name="time_out_night">
                                        <button type="button" class="time-set-btn" onclick="openTimeModal('time_out_night')" id="timeOutNightBtn">
                                            <i class="fas fa-clock"></i> Set Night
                                        </button>
                                    </div>
                                    <div class="field-error-message" id="timeOutNightError" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> Night Time Out is required when Present is selected
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Night Total Hours -->
                            <div class="pm-total-hours" id="nightTotalHours">
                                <i class="fas fa-clock"></i> Night Total: <span id="nightHoursValue">0.00</span> hrs
                            </div>
                        </div>
                        
                        <!-- Total Hours for the Day -->
                        <div class="total-hours-container">
                            <div class="total-hours-header">
                                <i class="fas fa-clock"></i>
                                <span>Total Work Hours for the Day</span>
                            </div>
                            <div class="total-hours-grid">
                                <div class="total-hours-item">
                                    <div class="total-hours-label">AM Session</div>
                                    <div class="total-hours-value" id="totalAmHoursDisplay">0.00 <span>hrs</span></div>
                                </div>
                                <div class="total-hours-item">
                                    <div class="total-hours-label">PM Session</div>
                                    <div class="total-hours-value" id="totalPmHoursDisplay">0.00 <span>hrs</span></div>
                                </div>
                                <div class="total-hours-item">
                                    <div class="total-hours-label">Night Session</div>
                                    <div class="total-hours-value" id="totalNightHoursDisplay">0.00 <span>hrs</span></div>
                                </div>
                                <div class="total-hours-item">
                                    <div class="total-hours-label">Total</div>
                                    <div class="total-hours-value" id="totalHoursDisplay">0.00 <span>hrs</span></div>
                                </div>
                            </div>
                        </div>
                        
<!-- Site Dropdown - Dynamically loaded from site_monitoring table -->
<div class="form-group">
    <label class="form-label"><i class="fas fa-location-dot"></i> Site (Optional)</label>
    <select name="site" id="site" class="filter-input" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem;">
        <option value="">-- Select Site --</option>
        <?php
        // Fetch sites from site_monitoring table (same table used in home.php)
        $sites_sql = "SELECT id, site_name FROM site_monitoring WHERE is_active = 1 OR is_active IS NULL ORDER BY site_name";
        $sites_result = $conn->query($sites_sql);
        if ($sites_result && $sites_result->num_rows > 0) {
            while ($site_row = $sites_result->fetch_assoc()) {
                $site_name = htmlspecialchars($site_row['site_name']);
                echo '<option value="' . $site_name . '">' . $site_name . '</option>';
            }
        } else {
            // Fallback options if no sites in database
            echo '<option value="Main Office">Main Office</option>';
            echo '<option value="Branch A">Branch A</option>';
            echo '<option value="Branch B">Branch B</option>';
        }
        ?>
    </select>
    <small style="display: block; margin-top: 5px; color: #666; font-size: 0.8rem;">
        <i class="fas fa-info-circle"></i> Sites are managed in Employee Tracking
    </small>
</div>

<!-- Remarks Field - ADD THIS BACK -->
<div class="form-group">
    <label class="form-label"><i class="fas fa-comment"></i> Remarks (Optional)</label>
    <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Add any remarks or notes..." style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; font-family: inherit;"></textarea>
</div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddAttendanceModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-success" onclick="submitAttendanceForm()">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>
            </div>
        </div>
        
        <!-- View Attendance Modal (Read-Only) -->
        <div class="modal" id="viewAttendanceModal">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #75e6da, #5fd9c9);">
                    <h3 class="modal-title"><i class="fas fa-eye"></i> View Attendance Details</h3>
                    <button type="button" class="modal-close" onclick="closeViewAttendanceModal()">×</button>
                </div>
                <div class="modal-body">
                    <div id="viewNotificationArea"></div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> Employee</label>
                        <div class="selected-employee-card" style="background: #f8f9fa; border-color: #75e6da;">
                            <div class="selected-employee-info">
                                <div class="selected-employee-avatar" style="background: linear-gradient(135deg, #75e6da, #5fd9c9);">
                                    <i class="fas fa-user-circle"></i>
                                </div>
                                <div class="selected-employee-details">
                                    <span class="selected-employee-name" id="viewEmployeeName"></span>
                                    <span class="selected-employee-id" id="viewEmployeeIdDisplay">
                                        <i class="fas fa-id-badge"></i> ID: <span id="viewEmployeeId">--</span>
                                    </span>
                                    <span class="selected-employee-position" id="viewEmployeePosition" style="font-size: 0.8rem; color: #666;">
                                        <i class="fas fa-briefcase"></i> <span id="viewEmployeePositionText">--</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label"><i class="fas fa-calendar"></i> Date</label>
                            <div class="date-display" id="viewDate" style="padding: 12px 15px; background: #f8f9fa; border-radius: 8px;">--</div>
                        </div>
                        <div class="form-col">
                            <label class="form-label"><i class="fas fa-id-badge"></i> Record ID</label>
                            <div class="date-display" id="viewRecordId" style="padding: 12px 15px; background: #f8f9fa; border-radius: 8px;">--</div>
                        </div>
                    </div>
                    
                    <!-- Leave Status Display -->
                    <div id="viewLeaveContainer" style="margin-bottom: 20px; padding: 15px; background: #fff9e6; border-radius: 12px; border: 1px solid #ffeaa7; display: none;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="status-dot leave" style="width: 12px; height: 12px;"></span>
                            <span style="font-weight: 600; color: #856404;">On Leave</span>
                            <span id="viewLeaveType" style="background: #ffeaa7; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem;"></span>
                        </div>
                    </div>
                    
                    <!-- Workday Type Display -->
                    <div id="viewWorkdayContainer" style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-radius: 12px; border: 1px solid #bbdefb; display: none;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="status-dot" style="width: 12px; height: 12px; background-color: #1976d2;"></span>
                            <span style="font-weight: 600; color: #1976d2;">Workday Type</span>
                            <span id="viewWorkdayType" style="background: #bbdefb; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; color: #1976d2;"></span>
                        </div>
                    </div>
                    
                    <!-- AM Time Section -->
                    <div class="time-section">
                        <div class="time-section-header">
                            <i class="fas fa-sun"></i>
                            <h4>Morning Session</h4>
                        </div>
                        
                        <div class="am-status-container" style="border-left-color: #75e6da;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div>
                                    <span class="status-dot present" id="viewAmStatusDot" style="display: none;"></span>
                                    <span class="status-dot absent" id="viewAmStatusDotAbsent" style="display: none;"></span>
                                    <span class="status-dot leave" id="viewAmStatusDotLeave" style="display: none;"></span>
                                </div>
                                <div><strong>Status:</strong> <span id="viewAmStatus">--</span></div>
                            </div>
                        </div>
                        
                        <div class="time-input-row">
                            <div class="time-input-group">
                                <div class="time-input-label">
                                    <i class="fas fa-sign-in-alt"></i> Time In
                                </div>
                                <div class="time-display-box" id="viewTimeInAmDisplay" style="background: #f8f9fa;">
                                    <div class="time-display-content">
                                        <i class="far fa-clock"></i> --
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-input-group">
                                <div class="time-input-label">
                                    <i class="fas fa-sign-out-alt"></i> Time Out
                                </div>
                                <div class="time-display-box" id="viewTimeOutAmDisplay" style="background: #f8f9fa;">
                                    <div class="time-display-content">
                                        <i class="far fa-clock"></i> --
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="am-total-hours">
                            AM Total: <span id="viewAmHours">0.00</span> hrs
                        </div>
                    </div>
                    
                    <!-- PM and Night Combined Section -->
                    <div class="time-section">
                        <div class="time-section-header">
                            <i class="fas fa-moon"></i>
                            <h4>Afternoon & Night Sessions</h4>
                        </div>
                        
                        <div class="pm-status-container" style="border-left-color: #f39c12;">
                            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="status-dot present" id="viewPmStatusDot" style="display: none;"></span>
                                    <span class="status-dot absent" id="viewPmStatusDotAbsent" style="display: none;"></span>
                                    <span class="status-dot leave" id="viewPmStatusDotLeave" style="display: none;"></span>
                                    <span><strong>PM Status:</strong> <span id="viewPmStatus">--</span></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="status-dot present" id="viewNightStatusDot" style="display: none;"></span>
                                    <span class="status-dot absent" id="viewNightStatusDotAbsent" style="display: none;"></span>
                                    <span class="status-dot leave" id="viewNightStatusDotLeave" style="display: none;"></span>
                                    <span><strong>Night Status:</strong> <span id="viewNightStatus">--</span></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PM Times -->
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <i class="fas fa-sun" style="color: #f39c12;"></i>
                                <span style="font-weight: 600;">Afternoon Session</span>
                            </div>
                            <div class="time-input-row">
                                <div class="time-input-group">
                                    <div class="time-input-label" style="font-size: 0.8rem;">Time In</div>
                                    <div class="time-display-box" id="viewTimeInPmDisplay" style="background: #f8f9fa;">
                                        <div class="time-display-content">
                                            <i class="far fa-clock"></i> --
                                        </div>
                                    </div>
                                </div>
                                <div class="time-input-group">
                                    <div class="time-input-label" style="font-size: 0.8rem;">Time Out</div>
                                    <div class="time-display-box" id="viewTimeOutPmDisplay" style="background: #f8f9fa;">
                                        <div class="time-display-content">
                                            <i class="far fa-clock"></i> --
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 0.9rem; color: #666; margin-top: 5px;">
                                PM Total: <strong><span id="viewPmHours">0.00</span> hrs</strong>
                            </div>
                        </div>
                        
                        <!-- Night Times -->
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <i class="fas fa-moon" style="color: #9b59b6;"></i>
                                <span style="font-weight: 600;">Night Session</span>
                            </div>
                            <div class="time-input-row">
                                <div class="time-input-group">
                                    <div class="time-input-label" style="font-size: 0.8rem;">Time In</div>
                                    <div class="time-display-box" id="viewTimeInNightDisplay" style="background: #f8f9fa;">
                                        <div class="time-display-content">
                                            <i class="far fa-clock"></i> --
                                        </div>
                                    </div>
                                </div>
                                <div class="time-input-group">
                                    <div class="time-input-label" style="font-size: 0.8rem;">Time Out</div>
                                    <div class="time-display-box" id="viewTimeOutNightDisplay" style="background: #f8f9fa;">
                                        <div class="time-display-content">
                                            <i class="far fa-clock"></i> --
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 0.9rem; color: #666; margin-top: 5px;">
                                Night Total: <strong><span id="viewNightHours">0.00</span> hrs</strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Hours -->
                    <div class="total-hours-container" style="border-left-color: #75e6da;">
                        <div class="total-hours-header">
                            <i class="fas fa-clock"></i>
                            <span>Total Work Hours</span>
                        </div>
                        <div class="total-hours-grid">
                            <div class="total-hours-item">
                                <div class="total-hours-label">AM</div>
                                <div class="total-hours-value" id="viewTotalAmHours">0.00</div>
                            </div>
                            <div class="total-hours-item">
                                <div class="total-hours-label">PM</div>
                                <div class="total-hours-value" id="viewTotalPmHours">0.00</div>
                            </div>
                            <div class="total-hours-item">
                                <div class="total-hours-label">Night</div>
                                <div class="total-hours-value" id="viewTotalNightHours">0.00</div>
                            </div>
                            <div class="total-hours-item">
                                <div class="total-hours-label">Total</div>
                                <div class="total-hours-value" id="viewTotalHours">0.00</div>
                            </div>
                        </div>
                    </div>
                    
<!-- Site Display -->
<div class="form-group">
    <label class="form-label"><i class="fas fa-location-dot"></i> Site</label>
    <div class="date-display" id="viewSite" style="padding: 12px 15px; background: #f8f9fa; border-radius: 8px;">--</div>
</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeViewAttendanceModal()">
                        Close
                    </button>
                    <button type="button" class="btn btn-primary" onclick="editFromView()" id="editFromViewBtn">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Delete Confirmation Modal -->
        <div class="modal delete-modal" id="deleteConfirmModal">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                    <h3 class="modal-title"><i class="fas fa-trash"></i> Confirm Delete</h3>
                    <button type="button" class="modal-close" onclick="closeDeleteModal()">×</button>
                </div>
                <div class="modal-body">
                    <div class="delete-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h4 style="margin-bottom: 15px;">Are you sure?</h4>
                    <p>You are about to delete the attendance record for:</p>
                    <p><strong id="deleteEmployeeName"></strong></p>
                    <p><strong id="deleteDate"></strong></p>
                    <div class="delete-warning">
                        <i class="fas fa-info-circle"></i> This action cannot be undone.
                    </div>
                    <input type="hidden" id="deleteEmployeeId" value="">
                    <input type="hidden" id="deleteAttendanceDate" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteAttendance()" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Time Input Modal -->
        <div class="time-modal" id="timeModal">
            <div class="time-modal-content">
                <div class="time-modal-header">
                    <h3 class="time-modal-title" id="timeModalTitle">
                        <i class="fas fa-clock"></i> Set Time
                    </h3>
                    <button type="button" class="modal-close" onclick="closeTimeModal()">×</button>
                </div>
                <div class="time-modal-body">
                    <div class="time-input-group">
                        <select id="timeHour" class="time-input-select"></select>
                        <span class="time-input-separator">:</span>
                        <select id="timeMinute" class="time-input-select"></select>
                        <select id="timePeriod" class="time-period-select">
                            <option value="AM">AM</option>
                            <option value="PM">PM</option>
                        </select>
                    </div>
                </div>
                <div class="time-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeTimeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveTime()">
                        <i class="fas fa-check"></i> Set Time
                    </button>
                </div>
            </div>
        </div>

        <!-- Generate Attendance Report Modal - With Custom Calendar (gaya ng salary slip) -->
        <div class="modal" id="reportModal" style="z-index: 10000;">
            <div class="modal-content" style="width: 95%; max-width: 1600px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #75e6da, #5fd9c9);">
                    <h3 class="modal-title"><i class="fas fa-file-export"></i> Attendance Report</h3>
                    <button type="button" class="modal-close" onclick="closeReportModal()">×</button>
                </div>
                <div class="modal-body" style="max-height: 80vh; overflow-y: auto; padding: 25px;">
                    
                    <!-- Filter Section - With Custom Calendar (gaya ng salary slip) -->
                    <div class="filter-section" style="background: linear-gradient(135deg, #f8f9fa, #ffffff); border-radius: 16px; padding: 25px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                        <div style="display: flex; gap: 25px; flex-wrap: wrap; align-items: flex-end;">
                            
                            <!-- DATE FROM - Custom Calendar -->
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #2c3e50; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-calendar-alt" style="margin-right: 8px; color: #75e6da;"></i> DATE FROM
                                </label>
                                
                                <!-- Custom Date Picker -->
                                <div class="custom-date-picker">
                                    <div class="date-input-group" onclick="toggleFromCalendar()">
                                      
                                        <input type="text" id="reportDateFromDisplay" class="date-display" 
                                               value="<?= date('m/d/Y', strtotime($selected_date)) ?>" 
                                               readonly placeholder="MM/DD/YYYY">
                                        <i class="fas fa-chevron-down dropdown-icon"></i>
                                    </div>
                                    <input type="hidden" id="reportDateFrom" value="<?= $selected_date ?>">
                                    
                                    <!-- From Calendar Dropdown -->
                                    <div class="calendar-dropdown" id="fromCalendar">
                                        <div class="calendar-header">
                                            <span class="month-year" id="fromMonthYear"><?= date('F Y', strtotime($selected_date)) ?></span>
                                            <div class="nav-buttons">
                                                <button type="button" class="nav-btn" onclick="navigateFromMonth(-1)">‹</button>
                                                <button type="button" class="nav-btn" onclick="navigateFromMonth(1)">›</button>
                                            </div>
                                        </div>
                                        
                                        <div class="calendar-selectors">
                                            <select id="fromMonthSelect" class="month-select" onchange="changeFromMonthYear()">
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
                                            
                                            <select id="fromYearSelect" class="year-select" onchange="changeFromMonthYear()">
                                                <?php for($y = date('Y') - 5; $y <= date('Y') + 5; $y++): ?>
                                                    <option value="<?= $y ?>" <?= $y == date('Y', strtotime($selected_date)) ? 'selected' : '' ?>><?= $y ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="calendar-weekdays">
                                            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                                        </div>
                                        
                                        <div class="calendar-days" id="fromCalendarDays">
                                            <!-- Days will be populated by JavaScript -->
                                        </div>
                                        
                                        <div class="calendar-footer">
                                            <button type="button" class="btn-today" onclick="setFromToday()">Today</button>
                                            <button type="button" class="btn-clear" onclick="clearFromDate()">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- DATE TO - Custom Calendar -->
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #2c3e50; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-calendar-alt" style="margin-right: 8px; color: #75e6da;"></i> DATE TO
                                </label>
                                
                                <!-- Custom Date Picker -->
                                <div class="custom-date-picker">
                                    <div class="date-input-group" onclick="toggleToCalendar()">
                                      
                                        <input type="text" id="reportDateToDisplay" class="date-display" 
                                               value="<?= date('m/d/Y', strtotime($selected_date)) ?>" 
                                               readonly placeholder="MM/DD/YYYY">
                                        <i class="fas fa-chevron-down dropdown-icon"></i>
                                    </div>
                                    <input type="hidden" id="reportDateTo" value="<?= $selected_date ?>">
                                    
                                    <!-- To Calendar Dropdown -->
                                    <div class="calendar-dropdown" id="toCalendar">
                                        <div class="calendar-header">
                                            <span class="month-year" id="toMonthYear"><?= date('F Y', strtotime($selected_date)) ?></span>
                                            <div class="nav-buttons">
                                                <button type="button" class="nav-btn" onclick="navigateToMonth(-1)">‹</button>
                                                <button type="button" class="nav-btn" onclick="navigateToMonth(1)">›</button>
                                            </div>
                                        </div>
                                        
                                        <div class="calendar-selectors">
                                            <select id="toMonthSelect" class="month-select" onchange="changeToMonthYear()">
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
                                            
                                            <select id="toYearSelect" class="year-select" onchange="changeToMonthYear()">
                                                <?php for($y = date('Y') - 5; $y <= date('Y') + 5; $y++): ?>
                                                    <option value="<?= $y ?>" <?= $y == date('Y', strtotime($selected_date)) ? 'selected' : '' ?>><?= $y ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="calendar-weekdays">
                                            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                                        </div>
                                        
                                        <div class="calendar-days" id="toCalendarDays">
                                            <!-- Days will be populated by JavaScript -->
                                        </div>
                                        
                                        <div class="calendar-footer">
                                            <button type="button" class="btn-today" onclick="setToToday()">Today</button>
                                            <button type="button" class="btn-clear" onclick="clearToDate()">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- EMPLOYEE Dropdown -->
                            <div style="flex: 1.5; min-width: 250px;">
                                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #2c3e50; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-user" style="margin-right: 8px; color: #75e6da;"></i> EMPLOYEE
                                </label>
                                <div class="employee-select-wrapper">
                                    <select id="reportEmployeeId" class="filter-input" style="height: 45px; padding: 0 15px;">
                                        <option value="0">All Employees</option>
                                        <?php 
                                        // Re-fetch employees for the dropdown
                                        $emp_dropdown = $conn->query("SELECT id, first_name, middle_name, last_name FROM employees ORDER BY last_name, first_name");
                                        if ($emp_dropdown && $emp_dropdown->num_rows > 0) {
                                            while($emp = $emp_dropdown->fetch_assoc()) {
                                                $full_name = trim($emp['first_name'] . ' ' . ($emp['middle_name'] ?? '') . ' ' . $emp['last_name']);
                                                $selected = ($employee_filter_id == $emp['id']) ? 'selected' : '';
                                                echo '<option value="' . $emp['id'] . '" ' . $selected . '>' . htmlspecialchars($full_name) . ' (ID: ' . $emp['id'] . ')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                   
                                </div>
                            </div>
                            
                            <!-- GENERATE Button -->
                            <div style="flex: 0.8; min-width: 120px;">
                                <button class="btn-preview" onclick="loadReportData()" style="height: 45px;">
                                    <i class="fas fa-sync-alt"></i> GENERATE
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Preview Container - Auto Loaded -->
                    <div id="reportPreviewContainer">
                        <!-- Loading indicator -->
                        <div style="text-align: center; padding: 50px;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #75e6da;"></i>
                            <p style="margin-top: 15px; color: #666;">Loading attendance records...</p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons-container" id="reportActionButtons" style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                        <button class="btn-print" onclick="printReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <a href="#" id="downloadExcelLink" class="btn-excel">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </a>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()" style="padding: 10px 25px; background: #95a5a6; color: white; border: none; border-radius: 8px; cursor: pointer;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include("./includes/footer.php"); ?>

<script>
// ============================================
// GLOBAL VARIABLES
// ============================================
let currentTimeField = null;
let isEditMode = false;
let isViewMode = false;
let currentEmployeeId = null;
let employeeData = [];
let searchTimeout;
let liveSearchTimeout;

// MAIN CALENDAR VARIABLES
let mainCurrentDate = new Date('<?= $selected_date ?>');
let mainSelectedDate = '<?= $selected_date ?>';

// MODAL CALENDAR VARIABLES
let modalCurrentDate = new Date('<?= $selected_date ?>');
let modalSelectedDate = '<?= $selected_date ?>';

// REPORT CALENDAR VARIABLES
let fromCurrentDate = new Date('<?= $selected_date ?>');
let toCurrentDate = new Date('<?= $selected_date ?>');
let fromSelectedDate = '<?= $selected_date ?>';
let toSelectedDate = '<?= $selected_date ?>';

// Function to load sites from database
function loadSitesDropdown() {
    fetch('get_sites.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.sites) {
                const siteSelect = document.getElementById('site');
                if (siteSelect) {
                    // Clear existing options except the first one
                    while (siteSelect.options.length > 1) {
                        siteSelect.remove(1);
                    }
                    
                    // Add new options
                    data.sites.forEach(site => {
                        const option = document.createElement('option');
                        option.value = site.name;
                        option.textContent = site.name;
                        siteSelect.appendChild(option);
                    });
                }
            }
        })
        .catch(error => console.error('Error loading sites:', error));
}

// Call this when opening the modal
function openAddAttendanceModal() {
    // ... existing code ...
    
    // Load fresh sites list
    loadSitesDropdown();
    
    // ... rest of existing code ...
}

// ============================================
// SIMPLIFIED WORKDAY FUNCTIONS
// ============================================
function clearWorkdayInput() {
    const select = document.getElementById('workdayType');
    if (select) {
        select.value = '';
    }
}

// ============================================
// REPORT CALENDAR FUNCTIONS
// ============================================

// Toggle FROM Calendar
function toggleFromCalendar() {
    const calendar = document.getElementById('fromCalendar');
    const toCalendar = document.getElementById('toCalendar');
    
    if (toCalendar) toCalendar.classList.remove('show');
    
    if (calendar) {
        calendar.classList.toggle('show');
        if (calendar.classList.contains('show')) {
            generateFromCalendarDays();
        }
    }
}

// Toggle TO Calendar
function toggleToCalendar() {
    const calendar = document.getElementById('toCalendar');
    const fromCalendar = document.getElementById('fromCalendar');
    
    if (fromCalendar) fromCalendar.classList.remove('show');
    
    if (calendar) {
        calendar.classList.toggle('show');
        if (calendar.classList.contains('show')) {
            generateToCalendarDays();
        }
    }
}

// Navigate FROM Month
function navigateFromMonth(direction) {
    fromCurrentDate.setMonth(fromCurrentDate.getMonth() + direction);
    generateFromCalendarDays();
}

// Navigate TO Month
function navigateToMonth(direction) {
    toCurrentDate.setMonth(toCurrentDate.getMonth() + direction);
    generateToCalendarDays();
}

// Change FROM Month/Year
function changeFromMonthYear() {
    const monthSelect = document.getElementById('fromMonthSelect');
    const yearSelect = document.getElementById('fromYearSelect');
    
    const newMonth = parseInt(monthSelect.value);
    const newYear = parseInt(yearSelect.value);
    
    fromCurrentDate = new Date(newYear, newMonth, 1);
    generateFromCalendarDays();
}

// Change TO Month/Year
function changeToMonthYear() {
    const monthSelect = document.getElementById('toMonthSelect');
    const yearSelect = document.getElementById('toYearSelect');
    
    const newMonth = parseInt(monthSelect.value);
    const newYear = parseInt(yearSelect.value);
    
    toCurrentDate = new Date(newYear, newMonth, 1);
    generateToCalendarDays();
}

// Generate FROM Calendar Days
function generateFromCalendarDays() {
    const year = fromCurrentDate.getFullYear();
    const month = fromCurrentDate.getMonth();
    const daysGrid = document.getElementById('fromCalendarDays');
    const monthYearDisplay = document.getElementById('fromMonthYear');
    const monthSelect = document.getElementById('fromMonthSelect');
    const yearSelect = document.getElementById('fromYearSelect');
    
    if (!daysGrid || !monthYearDisplay) return;
    
    monthYearDisplay.textContent = fromCurrentDate.toLocaleDateString('en-US', { 
        month: 'long', 
        year: 'numeric' 
    });
    
    if (monthSelect) monthSelect.value = month;
    if (yearSelect) yearSelect.value = year;
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    
    let html = '';
    
    // Previous month days
    const prevMonthDays = new Date(year, month, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectFromDate('${dateStr}')">${day}</div>`;
    }
    
    // Current month days
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
        const isSelected = dateStr === fromSelectedDate;
        const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
        
        let classes = 'calendar-day';
        if (isToday) classes += ' today';
        if (isSelected) classes += ' selected';
        if (isWeekend) classes += ' weekend';
        
        html += `<div class="${classes}" onclick="selectFromDate('${dateStr}')">${day}</div>`;
    }
    
    // Next month days
    const totalCells = 42;
    const cellsUsed = firstDay + daysInMonth;
    const nextMonthDays = totalCells - cellsUsed;
    for (let day = 1; day <= nextMonthDays; day++) {
        const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectFromDate('${dateStr}')">${day}</div>`;
    }
    
    daysGrid.innerHTML = html;
}

// Generate TO Calendar Days
function generateToCalendarDays() {
    const year = toCurrentDate.getFullYear();
    const month = toCurrentDate.getMonth();
    const daysGrid = document.getElementById('toCalendarDays');
    const monthYearDisplay = document.getElementById('toMonthYear');
    const monthSelect = document.getElementById('toMonthSelect');
    const yearSelect = document.getElementById('toYearSelect');
    
    if (!daysGrid || !monthYearDisplay) return;
    
    monthYearDisplay.textContent = toCurrentDate.toLocaleDateString('en-US', { 
        month: 'long', 
        year: 'numeric' 
    });
    
    if (monthSelect) monthSelect.value = month;
    if (yearSelect) yearSelect.value = year;
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    
    let html = '';
    
    // Previous month days
    const prevMonthDays = new Date(year, month, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectToDate('${dateStr}')">${day}</div>`;
    }
    
    // Current month days
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
        const isSelected = dateStr === toSelectedDate;
        const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
        
        let classes = 'calendar-day';
        if (isToday) classes += ' today';
        if (isSelected) classes += ' selected';
        if (isWeekend) classes += ' weekend';
        
        html += `<div class="${classes}" onclick="selectToDate('${dateStr}')">${day}</div>`;
    }
    
    // Next month days
    const totalCells = 42;
    const cellsUsed = firstDay + daysInMonth;
    const nextMonthDays = totalCells - cellsUsed;
    for (let day = 1; day <= nextMonthDays; day++) {
        const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="calendar-day other-month" onclick="selectToDate('${dateStr}')">${day}</div>`;
    }
    
    daysGrid.innerHTML = html;
}

// Select FROM Date
function selectFromDate(dateStr) {
    const date = new Date(dateStr);
    const formattedDisplay = date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    document.getElementById('reportDateFromDisplay').value = formattedDisplay;
    document.getElementById('reportDateFrom').value = dateStr;
    
    fromSelectedDate = dateStr;
    fromCurrentDate = new Date(dateStr);
    
    generateFromCalendarDays();
    document.getElementById('fromCalendar').classList.remove('show');
}

// Select TO Date
function selectToDate(dateStr) {
    const date = new Date(dateStr);
    const formattedDisplay = date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    document.getElementById('reportDateToDisplay').value = formattedDisplay;
    document.getElementById('reportDateTo').value = dateStr;
    
    toSelectedDate = dateStr;
    toCurrentDate = new Date(dateStr);
    
    generateToCalendarDays();
    document.getElementById('toCalendar').classList.remove('show');
}

// Set FROM Today
function setFromToday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    
    fromCurrentDate = new Date(dateStr);
    selectFromDate(dateStr);
}

// Set TO Today
function setToToday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    
    toCurrentDate = new Date(dateStr);
    selectToDate(dateStr);
}

// Clear FROM Date
function clearFromDate() {
    document.getElementById('reportDateFromDisplay').value = '';
    document.getElementById('reportDateFrom').value = '';
    fromSelectedDate = '';
    document.getElementById('fromCalendar').classList.remove('show');
}

// Clear TO Date
function clearToDate() {
    document.getElementById('reportDateToDisplay').value = '';
    document.getElementById('reportDateTo').value = '';
    toSelectedDate = '';
    document.getElementById('toCalendar').classList.remove('show');
}

// ============================================
// REPORT MODAL FUNCTIONS - AUTO LOAD
// ============================================
function openReportModal(event) {
    // Prevent any default behavior and stop propagation
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const modal = document.getElementById('reportModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        
        // Reset calendar dates
        const currentDate = '<?= $selected_date ?>';
        const dateObj = new Date(currentDate);
        const formattedDisplay = dateObj.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
        
        document.getElementById('reportDateFromDisplay').value = formattedDisplay;
        document.getElementById('reportDateFrom').value = currentDate;
        document.getElementById('reportDateToDisplay').value = formattedDisplay;
        document.getElementById('reportDateTo').value = currentDate;
        
        fromCurrentDate = new Date(currentDate);
        toCurrentDate = new Date(currentDate);
        fromSelectedDate = currentDate;
        toSelectedDate = currentDate;
        
        // Auto-load report data for current date
        setTimeout(() => {
            loadReportData();
        }, 100); // Small delay to ensure modal is rendered
        
        console.log('Report modal opened - auto-loading data');
    } else {
        console.error('Report modal not found!');
    }
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = 'auto'; // Restore scrolling
        
        // Close any open calendars
        const fromCalendar = document.getElementById('fromCalendar');
        const toCalendar = document.getElementById('toCalendar');
        if (fromCalendar) fromCalendar.classList.remove('show');
        if (toCalendar) toCalendar.classList.remove('show');
        
        console.log('Report modal closed');
    }
}

// Load Report Data Function
function loadReportData() {
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;
    const employeeId = document.getElementById('reportEmployeeId').value;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both From and To dates');
        return;
    }
    
    // Show loading
    document.getElementById('reportPreviewContainer').innerHTML = `
        <div style="text-align: center; padding: 50px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #75e6da;"></i>
            <p style="margin-top: 15px; color: #666;">Loading attendance records...</p>
        </div>
    `;
    
    // Update download link
    const downloadLink = document.getElementById('downloadExcelLink');
    downloadLink.href = `download_attendance_report.php?date_from=${dateFrom}&date_to=${dateTo}&employee_id=${employeeId}`;
    
    // AJAX call to get report data
    fetch(`get_attendance_report.php?date_from=${dateFrom}&date_to=${dateTo}&employee_id=${employeeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReportPreview(data);
            } else {
                document.getElementById('reportPreviewContainer').innerHTML = `
                    <div style="text-align: center; padding: 50px; color: #e74c3c;">
                        <i class="fas fa-exclamation-circle" style="font-size: 3rem; margin-bottom: 15px;"></i>
                        <h4>No records found</h4>
                        <p>${data.message || 'No attendance records for the selected period'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('reportPreviewContainer').innerHTML = `
                <div style="text-align: center; padding: 50px; color: #e74c3c;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <h4>Error loading report</h4>
                    <p>Please try again</p>
                </div>
            `;
        });
}

// Display Report Preview
function displayReportPreview(data) {
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;
    const employeeId = document.getElementById('reportEmployeeId').value;
    const employeeSelect = document.getElementById('reportEmployeeId');
    const employeeName = employeeSelect.options[employeeSelect.selectedIndex].text;
    
    let html = `
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-calendar-check"></i> Attendance Report
            </div>
            <div class="report-date-range">
                ${formatDate(dateFrom)} to ${formatDate(dateTo)}
            </div>
        </div>
        
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 12px; display: flex; gap: 25px; flex-wrap: wrap; border: 1px solid #e0e0e0;">
            <div><strong style="color: #2c3e50;">Employee:</strong> <span style="color: #75e6da; font-weight: 600;">${employeeName}</span></div>
            <div><strong style="color: #2c3e50;">Total Records:</strong> <span style="font-weight: 600;">${data.records.length}</span></div>
            <div><strong style="color: #2c3e50;">Total Hours:</strong> <span style="color: #27ae60; font-weight: 600;">${data.total_hours} hrs</span></div>
            <div><strong style="color: #2c3e50;">Present:</strong> <span style="color: #28a745; font-weight: 600;">${data.present_count || 0}</span></div>
            <div><strong style="color: #2c3e50;">Absent:</strong> <span style="color: #dc3545; font-weight: 600;">${data.absent_count || 0}</span></div>
            <div><strong style="color: #2c3e50;">On Leave:</strong> <span style="color: #ffc107; font-weight: 600;">${data.leave_count || 0}</span></div>
        </div>
    `;
    
    if (data.records.length > 0) {
        html += `
            <div style="overflow-x: auto; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: linear-gradient(135deg, #75e6da, #5fd9c9); color: white;">
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Employee</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Date</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">AM Status</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">AM In</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">AM Out</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">PM Status</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">PM/Night In</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">PM/Night Out</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Night Status</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Leave</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Workday Type</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Total Hrs</th>
                            <th style="padding: 12px 8px; border: 1px solid #5fd9c9;">Site</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        data.records.forEach(record => {
            // Format status classes
            let amStatusClass = 'status-no-record';
            let amStatusText = record.status || 'No Record';
            if (record.status === 'Present') amStatusClass = 'status-present';
            else if (record.status === 'Absent') amStatusClass = 'status-absent';
            else if (record.status === 'On Leave') amStatusClass = 'status-leave';
            
            let pmStatusClass = 'status-no-record';
            let pmStatusText = record.pm_status || 'No Record';
            if (record.pm_status === 'Present') pmStatusClass = 'status-present';
            else if (record.pm_status === 'Absent') pmStatusClass = 'status-absent';
            else if (record.pm_status === 'On Leave') pmStatusClass = 'status-leave';
            
            let nightStatusClass = 'status-no-record';
            let nightStatusText = record.night_status || 'No Record';
            if (record.night_status === 'Present') nightStatusClass = 'status-present';
            else if (record.night_status === 'Absent') nightStatusClass = 'status-absent';
            else if (record.night_status === 'On Leave') nightStatusClass = 'status-leave';
            
            html += `
                <tr style="border-bottom: 1px solid #e0e0e0; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0;">
                        ${record.employee_name}<br>
                        <small style="color: #666;">ID: ${record.employee_id}</small>
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;">
                        ${formatDate(record.date)}
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;">
                        <span class="${amStatusClass}" style="padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; display: inline-block; font-weight: 600;">${amStatusText}</span>
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center; font-family: monospace;">${record.time_in_am_display || '-'}</td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center; font-family: monospace;">${record.time_out_am_display || '-'}</td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;">
                        <span class="${pmStatusClass}" style="padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; display: inline-block; font-weight: 600;">${pmStatusText}</span>
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0;">
                        ${record.time_in_pm_display ? '<div><span style="color: #f39c12; font-weight: 600;">PM:</span> ' + record.time_in_pm_display + '</div>' : ''}
                        ${record.time_in_night_display ? '<div><span style="color: #9b59b6; font-weight: 600;">Night:</span> ' + record.time_in_night_display + '</div>' : ''}
                        ${!record.time_in_pm_display && !record.time_in_night_display ? '-' : ''}
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0;">
                        ${record.time_out_pm_display ? '<div><span style="color: #f39c12; font-weight: 600;">PM:</span> ' + record.time_out_pm_display + '</div>' : ''}
                        ${record.time_out_night_display ? '<div><span style="color: #9b59b6; font-weight: 600;">Night:</span> ' + record.time_out_night_display + '</div>' : ''}
                        ${!record.time_out_pm_display && !record.time_out_night_display ? '-' : ''}
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;">
                        <span class="${nightStatusClass}" style="padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; display: inline-block; font-weight: 600;">${nightStatusText}</span>
                    </td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;">${record.leave_type || '-'}</td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;"><span style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 12px;">${record.workday_type || '-'}</span></td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0; text-align: center;"><strong style="color: #27ae60;">${record.total_hours || '0.00'}</strong></td>
                    <td style="padding: 10px 8px; border: 1px solid #e0e0e0;">${record.site || '-'}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
    } else {
        html += `
            <div style="text-align: center; padding: 60px; color: #7f8c8d; border: 2px dashed #e0e0e0; border-radius: 12px; background: #f8f9fa;">
                <i class="fas fa-calendar-times" style="font-size: 4rem; margin-bottom: 15px; color: #75e6da;"></i>
                <h4 style="color: #2c3e50; margin-bottom: 10px;">No records found</h4>
                <p>No attendance records for the selected period</p>
            </div>
        `;
    }
    
    document.getElementById('reportPreviewContainer').innerHTML = html;
    document.getElementById('reportActionButtons').style.display = 'flex';
}

// Print Report
function printReport() {
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;
    const employeeId = document.getElementById('reportEmployeeId').value;
    
    const printWindow = window.open(`print_attendance_report.php?date_from=${dateFrom}&date_to=${dateTo}&employee_id=${employeeId}`, '_blank');
    if (printWindow) {
        printWindow.focus();
    }
}

// Format Date Helper
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// ============================================
// HELPER FUNCTIONS
//============================================
function calculateHours(timeIn, timeOut) {
    if (!timeIn || !timeOut || timeIn === '00:00:00' || timeOut === '00:00:00') {
        // If timeOut is exactly 00:00:00 (midnight), we need to calculate it specially
        if (timeOut === '00:00:00' && timeIn && timeIn !== '00:00:00') {
            // Calculate hours from timeIn to midnight (24:00)
            const [inHour, inMinute] = timeIn.split(':').map(Number);
            const inMinutes = inHour * 60 + inMinute;
            const midnightMinutes = 24 * 60; // 24:00
            const totalMinutes = midnightMinutes - inMinutes;
            return parseFloat((totalMinutes / 60).toFixed(2));
        }
        return 0;
    }
    
    const [inHour, inMinute] = timeIn.split(':').map(Number);
    const [outHour, outMinute] = timeOut.split(':').map(Number);
    
    let inMinutes = inHour * 60 + inMinute;
    let outMinutes = outHour * 60 + outMinute;
    
    // Handle midnight (00:00) as 24:00
    if (outHour === 0 && outMinute === 0 && inMinutes > 0) {
        outMinutes = 24 * 60;
    }
    
    // Handle overnight shifts
    if (outMinutes < inMinutes) {
        outMinutes += 24 * 60;
    }
    
    const totalMinutes = outMinutes - inMinutes;
    return parseFloat((totalMinutes / 60).toFixed(2));
}

function updateTotalHours() {
    const timeInAm = document.getElementById('timeInAm').value;
    const timeOutAm = document.getElementById('timeOutAm').value;
    const timeInPm = document.getElementById('timeInPm').value;
    const timeOutPm = document.getElementById('timeOutPm').value;
    const timeInNight = document.getElementById('timeInNight').value;
    const timeOutNight = document.getElementById('timeOutNight').value;
    
    // Use the improved calculateHours function
    const amHours = calculateHours(timeInAm, timeOutAm);
    const pmHours = calculateHours(timeInPm, timeOutPm);
    const nightHours = calculateHours(timeInNight, timeOutNight);
    const totalHours = amHours + pmHours + nightHours;
    
    document.getElementById('amHoursValue').textContent = amHours.toFixed(2);
    document.getElementById('pmHoursValue').textContent = pmHours.toFixed(2);
    document.getElementById('nightHoursValue').textContent = nightHours.toFixed(2);
    document.getElementById('totalAmHoursDisplay').innerHTML = amHours.toFixed(2) + ' <span>hrs</span>';
    document.getElementById('totalPmHoursDisplay').innerHTML = pmHours.toFixed(2) + ' <span>hrs</span>';
    document.getElementById('totalNightHoursDisplay').innerHTML = nightHours.toFixed(2) + ' <span>hrs</span>';
    document.getElementById('totalHoursDisplay').innerHTML = totalHours.toFixed(2) + ' <span>hrs</span>';
}

function convertTimeTo24Hour(hour, minute, period) {
    let hour24 = parseInt(hour);
    if (period === 'PM' && hour24 !== 12) {
        hour24 += 12;
    }
    if (period === 'AM' && hour24 === 12) {
        hour24 = 0;  // 12:00 AM becomes 00:00:00
    }
    return `${hour24.toString().padStart(2, '0')}:${minute}:00`;
}
function disableTimeInputs(session, disabled) {
    if (session === 'am') {
        const amInputs = ['timeInAm', 'timeOutAm'];
        const amDisplayBoxes = ['timeInAmDisplay', 'timeOutAmDisplay'];
        const amButtons = ['timeInAmBtn', 'timeOutAmBtn'];
        
        amInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) element.disabled = disabled;
        });
        
        amDisplayBoxes.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                } else {
                    element.classList.remove('disabled');
                }
            }
        });
        
        amButtons.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                    element.disabled = true;
                } else {
                    element.classList.remove('disabled');
                    element.disabled = false;
                }
            }
        });
    } else if (session === 'pm') {
        const pmInputs = ['timeInPm', 'timeOutPm'];
        const pmDisplayBoxes = ['timeInPmDisplay', 'timeOutPmDisplay'];
        const pmButtons = ['timeInPmBtn', 'timeOutPmBtn'];
        
        pmInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) element.disabled = disabled;
        });
        
        pmDisplayBoxes.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                } else {
                    element.classList.remove('disabled');
                }
            }
        });
        
        pmButtons.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                    element.disabled = true;
                } else {
                    element.classList.remove('disabled');
                    element.disabled = false;
                }
            }
        });
    } else if (session === 'night') {
        const nightInputs = ['timeInNight', 'timeOutNight'];
        const nightDisplayBoxes = ['timeInNightDisplay', 'timeOutNightDisplay'];
        const nightButtons = ['timeInNightBtn', 'timeOutNightBtn'];
        
        nightInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) element.disabled = disabled;
        });
        
        nightDisplayBoxes.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                } else {
                    element.classList.remove('disabled');
                }
            }
        });
        
        nightButtons.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                if (disabled) {
                    element.classList.add('disabled');
                    element.disabled = true;
                } else {
                    element.classList.remove('disabled');
                    element.disabled = false;
                }
            }
        });
    }
}

function resetTimeFields(session) {
    if (session === 'am' || !session) {
        const timeInAmDisplay = document.getElementById('timeInAmDisplay');
        const timeOutAmDisplay = document.getElementById('timeOutAmDisplay');
        const timeInAm = document.getElementById('timeInAm');
        const timeOutAm = document.getElementById('timeOutAm');
        
        if (timeInAmDisplay) {
            timeInAmDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- AM</div>';
            timeInAmDisplay.classList.add('empty');
            timeInAmDisplay.classList.remove('validation-error');
        }
        if (timeOutAmDisplay) {
            timeOutAmDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- AM</div>';
            timeOutAmDisplay.classList.add('empty');
            timeOutAmDisplay.classList.remove('validation-error');
        }
        if (timeInAm) timeInAm.value = '';
        if (timeOutAm) timeOutAm.value = '';
        
        const timeInAmBtn = document.getElementById('timeInAmBtn');
        const timeOutAmBtn = document.getElementById('timeOutAmBtn');
        
        if (timeInAmBtn) {
            timeInAmBtn.innerHTML = '<i class="fas fa-clock"></i> Set AM';
            timeInAmBtn.style.background = '';
        }
        if (timeOutAmBtn) {
            timeOutAmBtn.innerHTML = '<i class="fas fa-clock"></i> Set AM';
            timeOutAmBtn.style.background = '';
        }
        
        document.getElementById('timeInAmError').style.display = 'none';
        document.getElementById('timeOutAmError').style.display = 'none';
    }
    
    if (session === 'pm' || !session) {
        const timeInPmDisplay = document.getElementById('timeInPmDisplay');
        const timeOutPmDisplay = document.getElementById('timeOutPmDisplay');
        const timeInPm = document.getElementById('timeInPm');
        const timeOutPm = document.getElementById('timeOutPm');
        
        if (timeInPmDisplay) {
            timeInPmDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- PM</div>';
            timeInPmDisplay.classList.add('empty');
            timeInPmDisplay.classList.remove('validation-error');
        }
        if (timeOutPmDisplay) {
            timeOutPmDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- PM</div>';
            timeOutPmDisplay.classList.add('empty');
            timeOutPmDisplay.classList.remove('validation-error');
        }
        if (timeInPm) timeInPm.value = '';
        if (timeOutPm) timeOutPm.value = '';
        
        const timeInPmBtn = document.getElementById('timeInPmBtn');
        const timeOutPmBtn = document.getElementById('timeOutPmBtn');
        
        if (timeInPmBtn) {
            timeInPmBtn.innerHTML = '<i class="fas fa-clock"></i> Set PM';
            timeInPmBtn.style.background = '';
        }
        if (timeOutPmBtn) {
            timeOutPmBtn.innerHTML = '<i class="fas fa-clock"></i> Set PM';
            timeOutPmBtn.style.background = '';
        }
        
        document.getElementById('timeInPmError').style.display = 'none';
        document.getElementById('timeOutPmError').style.display = 'none';
    }
    
    if (session === 'night' || !session) {
        const timeInNightDisplay = document.getElementById('timeInNightDisplay');
        const timeOutNightDisplay = document.getElementById('timeOutNightDisplay');
        const timeInNight = document.getElementById('timeInNight');
        const timeOutNight = document.getElementById('timeOutNight');
        
        if (timeInNightDisplay) {
            timeInNightDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- -- </div>';
            timeInNightDisplay.classList.add('empty');
            timeInNightDisplay.classList.remove('validation-error');
        }
        if (timeOutNightDisplay) {
            timeOutNightDisplay.innerHTML = '<div class="time-display-content"><i class="far fa-clock"></i> --:-- -- </div>';
            timeOutNightDisplay.classList.add('empty');
            timeOutNightDisplay.classList.remove('validation-error');
        }
        if (timeInNight) timeInNight.value = '';
        if (timeOutNight) timeOutNight.value = '';
        
        const timeInNightBtn = document.getElementById('timeInNightBtn');
        const timeOutNightBtn = document.getElementById('timeOutNightBtn');
        
        if (timeInNightBtn) {
            timeInNightBtn.innerHTML = '<i class="fas fa-clock"></i> Set Night';
            timeInNightBtn.style.background = '';
        }
        if (timeOutNightBtn) {
            timeOutNightBtn.innerHTML = '<i class="fas fa-clock"></i> Set Night';
            timeOutNightBtn.style.background = '';
        }
        
        document.getElementById('timeInNightError').style.display = 'none';
        document.getElementById('timeOutNightError').style.display = 'none';
    }
    
    updateTotalHours();
}

function clearValidationHighlights() {
    const timeFields = ['timeInAmDisplay', 'timeOutAmDisplay', 'timeInPmDisplay', 'timeOutPmDisplay', 'timeInNightDisplay', 'timeOutNightDisplay'];
    timeFields.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.classList.remove('validation-error');
        }
    });
    
    document.getElementById('timeInAmError').style.display = 'none';
    document.getElementById('timeOutAmError').style.display = 'none';
    document.getElementById('timeInPmError').style.display = 'none';
    document.getElementById('timeOutPmError').style.display = 'none';
    document.getElementById('timeInNightError').style.display = 'none';
    document.getElementById('timeOutNightError').style.display = 'none';
}

function showNotification(message, type = 'info', target = 'notificationArea') {
    const notificationArea = document.getElementById(target);
    if (!notificationArea) return;
    
    const notification = document.createElement('div');
    notification.className = `attendance-notification ${type}`;
    
    let icon = '';
    if (type === 'success') icon = '<i class="fas fa-check-circle"></i>';
    else if (type === 'error') icon = '<i class="fas fa-exclamation-circle"></i>';
    else if (type === 'info') icon = '<i class="fas fa-info-circle"></i>';
    else if (type === 'warning') icon = '<i class="fas fa-exclamation-triangle"></i>';
    
    notification.innerHTML = icon + ' ' + message;
    
    notificationArea.innerHTML = '';
    notificationArea.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 500);
        }
    }, 5000);
}

// ============================================
// LEAVE TYPE AND WORKDAY HANDLING 
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const statusAbsent = document.getElementById('statusAbsent');
    const statusPresent = document.getElementById('statusPresent');
    const pmStatusPresent = document.getElementById('pmStatusPresent');
    const pmStatusAbsent = document.getElementById('pmStatusAbsent');
    const nightStatusPresent = document.getElementById('nightStatusPresent');
    const nightStatusAbsent = document.getElementById('nightStatusAbsent');
    
    // Workday Type is now standalone - no radio button
    
    if (statusAbsent) {
        statusAbsent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('am', true);
                resetTimeFields('am');
                // Don't reset PM and Night - keep them as is
                clearValidationHighlights();
            }
        });
    }
    
    if (statusPresent) {
        statusPresent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('am', false);
                clearValidationHighlights();
            }
        });
    }
    
    if (pmStatusPresent) {
        pmStatusPresent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('pm', false);
                clearValidationHighlights();
            }
        });
    }
    
    if (pmStatusAbsent) {
        pmStatusAbsent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('pm', true);
                resetTimeFields('pm');
                clearValidationHighlights();
            }
        });
    }
    
    if (nightStatusPresent) {
        nightStatusPresent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('night', false);
                clearValidationHighlights();
            }
        });
    }
    
    if (nightStatusAbsent) {
        nightStatusAbsent.addEventListener('change', function() {
            if (this.checked) {
                disableTimeInputs('night', true);
                resetTimeFields('night');
                clearValidationHighlights();
            }
        });
    }
});

// ============================================
// MAIN DATE FILTER CALENDAR FUNCTIONS
// ============================================
function toggleMainCalendar() {
    const calendar = document.getElementById('mainCalendarWrapper');
    if (calendar) {
        if (calendar.style.display === 'block') {
            calendar.style.display = 'none';
        } else {
            const modalCalendar = document.getElementById('modalCalendarWrapper');
            if (modalCalendar) modalCalendar.style.display = 'none';
            
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
    
    const dateField = document.getElementById('mainDateField');
    const dateHidden = document.getElementById('selectedDate');
    
    if (dateField) dateField.value = formattedDisplay;
    if (dateHidden) dateHidden.value = dateStr;
    
    mainSelectedDate = dateStr;
    mainCurrentDate = new Date(dateStr);
    
    generateMainCalendarDays();
    
    const calendar = document.getElementById('mainCalendarWrapper');
    if (calendar) calendar.style.display = 'none';
    
    document.getElementById('mainAttendanceForm').submit();
}

function clearMainDate() {
    const dateField = document.getElementById('mainDateField');
    const dateHidden = document.getElementById('selectedDate');
    
    if (dateField) dateField.value = '';
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

// ============================================
// MODAL CALENDAR FUNCTIONS
// ============================================
function toggleModalCalendar() {
    const calendar = document.getElementById('modalCalendarWrapper');
    if (calendar) {
        if (calendar.style.display === 'block') {
            calendar.style.display = 'none';
        } else {
            const mainCalendar = document.getElementById('mainCalendarWrapper');
            if (mainCalendar) mainCalendar.style.display = 'none';
            
            updateModalCalendarSelectors();
            generateModalCalendarDays();
            calendar.style.display = 'block';
        }
    }
}

function updateModalCalendarSelectors() {
    const monthSelect = document.getElementById('modalMonthSelect');
    const yearSelect = document.getElementById('modalYearSelect');
    
    if (monthSelect) {
        monthSelect.value = modalCurrentDate.getMonth();
    }
    if (yearSelect) {
        yearSelect.value = modalCurrentDate.getFullYear();
    }
}

function changeModalMonthYear() {
    const monthSelect = document.getElementById('modalMonthSelect');
    const yearSelect = document.getElementById('modalYearSelect');
    
    if (monthSelect && yearSelect) {
        const newMonth = parseInt(monthSelect.value);
        const newYear = parseInt(yearSelect.value);
        
        modalCurrentDate = new Date(newYear, newMonth, 1);
        generateModalCalendarDays();
    }
}

function generateModalCalendarDays() {
    const year = modalCurrentDate.getFullYear();
    const month = modalCurrentDate.getMonth();
    const daysGrid = document.getElementById('modalCalendarDaysGrid');
    const monthYearDisplay = document.getElementById('modalCalendarMonthYear');
    
    if (!daysGrid || !monthYearDisplay) return;
    
    monthYearDisplay.textContent = modalCurrentDate.toLocaleDateString('en-US', { 
        month: 'long', 
        year: 'numeric' 
    });
    
    updateModalCalendarSelectors();
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    
    let html = '';
    
    const prevMonthDays = new Date(year, month, 0).getDate();
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="modal-calendar-day other-month" onclick="selectModalDate('${dateStr}')">${day}</div>`;
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
        const isSelected = dateStr === modalSelectedDate;
        const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
        
        let classes = 'modal-calendar-day';
        if (isToday) classes += ' today';
        if (isSelected) classes += ' selected';
        if (isWeekend) classes += ' weekend';
        
        html += `<div class="${classes}" onclick="selectModalDate('${dateStr}')">${day}</div>`;
    }
    
    const totalCells = 42;
    const cellsUsed = firstDay + daysInMonth;
    const nextMonthDays = totalCells - cellsUsed;
    for (let day = 1; day <= nextMonthDays; day++) {
        const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += `<div class="modal-calendar-day other-month" onclick="selectModalDate('${dateStr}')">${day}</div>`;
    }
    
    daysGrid.innerHTML = html;
}

function navigateModalMonth(direction) {
    modalCurrentDate.setMonth(modalCurrentDate.getMonth() + direction);
    generateModalCalendarDays();
}

function selectModalDate(dateStr) {
    const date = new Date(dateStr);
    const formattedDisplay = date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    const dateField = document.getElementById('attendanceDateField');
    const dateHidden = document.getElementById('attendanceDate');
    
    if (dateField) dateField.value = formattedDisplay;
    if (dateHidden) dateHidden.value = dateStr;
    
    modalSelectedDate = dateStr;
    modalCurrentDate = new Date(dateStr);
    
    generateModalCalendarDays();
    
    const calendar = document.getElementById('modalCalendarWrapper');
    if (calendar) calendar.style.display = 'none';
}

function clearModalDate() {
    const dateField = document.getElementById('attendanceDateField');
    const dateHidden = document.getElementById('attendanceDate');
    
    if (dateField) dateField.value = '';
    if (dateHidden) dateHidden.value = '';
    
    modalSelectedDate = '';
    
    const calendar = document.getElementById('modalCalendarWrapper');
    if (calendar) calendar.style.display = 'none';
}

function setModalToday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    
    modalCurrentDate = new Date(dateStr);
    selectModalDate(dateStr);
}

// ============================================
// SEARCHABLE EMPLOYEE DROPDOWN FUNCTIONS
// ============================================
function initEmployeeSearch() {
    const select = document.getElementById('employeeSelect');
    if (!select) return;
    
    employeeData = [];
    const options = select.options;
    
    for (let i = 1; i < options.length; i++) {
        const option = options[i];
        const position = option.getAttribute('data-position') || 'N/A';
        employeeData.push({
            id: option.value,
            name: option.textContent.replace(/\(ID:.*\)/, '').trim(),
            fullText: option.textContent,
            position: position,
            searchText: (option.getAttribute('data-name') || option.textContent.toLowerCase()) + ' ' + position.toLowerCase()
        });
    }
    
    const dropdown = document.getElementById('employeeResultsDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

function searchEmployees(query) {
    const resultsList = document.getElementById('employeeResultsList');
    const resultsCount = document.getElementById('resultsCount');
    const dropdown = document.getElementById('employeeResultsDropdown');
    const clearBtn = document.getElementById('clearSearch');
    
    if (!query || query.length < 1) {
        dropdown.style.display = 'none';
        if (clearBtn) clearBtn.style.display = 'none';
        return;
    }
    
    if (clearBtn) clearBtn.style.display = 'block';
    
    resultsList.innerHTML = '<div class="loading-results"><i class="fas fa-spinner"></i> Searching...</div>';
    dropdown.style.display = 'block';
    
    clearTimeout(searchTimeout);
    
    searchTimeout = setTimeout(() => {
        const searchLower = query.toLowerCase();
        
        const results = employeeData.filter(emp => 
            emp.searchText.includes(searchLower) || 
            emp.id.includes(searchLower)
        ).slice(0, 15);
        
        resultsCount.textContent = `${results.length} employee${results.length !== 1 ? 's' : ''} found`;
        
        if (results.length > 0) {
            let html = '';
            results.forEach(emp => {
                let displayName = emp.name;
                if (searchLower) {
                    const regex = new RegExp(`(${searchLower})`, 'gi');
                    displayName = emp.name.replace(regex, '<strong>$1</strong>');
                }
                
                html += `
                    <div class="employee-result-item" onclick="selectEmployee('${emp.id}', '${emp.name.replace(/'/g, "\\'")}', '${emp.position.replace(/'/g, "\\'")}')">
                        <div class="employee-result-info">
                            <div class="employee-result-name">${displayName}</div>
                            <div class="employee-result-details">
                                <span class="employee-result-id"><i class="fas fa-id-badge"></i> ID: ${emp.id}</span>
                                <span class="employee-result-position"><i class="fas fa-briefcase"></i> ${emp.position}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            resultsList.innerHTML = html;
        } else {
            resultsList.innerHTML = `
                <div class="no-results">
                    <i class="fas fa-user-slash"></i>
                    <h4>No employees found</h4>
                    <p>No results matching "${query}"</p>
                </div>
            `;
        }
    }, 150);
}

function selectEmployee(id, name, position) {
    const select = document.getElementById('employeeSelect');
    if (select) {
        select.value = id;
    }
    
    const selectedCard = document.getElementById('selectedEmployeeCard');
    const selectedName = document.getElementById('selectedEmployeeName');
    const selectedId = document.getElementById('selectedEmployeeId');
    const selectedPositionText = document.getElementById('selectedEmployeePositionText');
    const searchContainer = document.getElementById('employeeSearchContainer');
    const dropdown = document.getElementById('employeeResultsDropdown');
    const clearBtn = document.getElementById('clearSearch');
    const employeeIdDisplay = document.getElementById('employeeIdDisplay2');
    
    if (selectedCard) {
        selectedCard.style.display = 'block';
        selectedName.textContent = name;
        selectedId.textContent = id;
        selectedPositionText.textContent = position || 'N/A';
    }
    
    if (searchContainer) {
        searchContainer.style.display = 'none';
    }
    
    if (dropdown) dropdown.style.display = 'none';
    if (clearBtn) clearBtn.style.display = 'none';
    
    if (employeeIdDisplay) {
        employeeIdDisplay.textContent = id;
    }
    
    updateEmployeeId();
}

function changeEmployee() {
    const selectedCard = document.getElementById('selectedEmployeeCard');
    if (selectedCard) {
        selectedCard.style.display = 'none';
    }
    
    const select = document.getElementById('employeeSelect');
    if (select) {
        select.value = '';
    }
    
    const searchContainer = document.getElementById('employeeSearchContainer');
    if (searchContainer) {
        searchContainer.style.display = 'block';
    }
    
    const searchInput = document.getElementById('employeeSearchInput');
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    
    const employeeIdDisplay = document.getElementById('employeeIdDisplay2');
    if (employeeIdDisplay) {
        employeeIdDisplay.textContent = '--';
    }
    
    updateEmployeeId();
    
    showNotification('Select another employee', 'info');
}

function clearEmployeeSearch() {
    const searchInput = document.getElementById('employeeSearchInput');
    const dropdown = document.getElementById('employeeResultsDropdown');
    const clearBtn = document.getElementById('clearSearch');
    
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    
    if (dropdown) {
        dropdown.style.display = 'none';
    }
    
    if (clearBtn) {
        clearBtn.style.display = 'none';
    }
}

// ============================================
// LIVE SEARCH FUNCTIONS
// ============================================
function initLiveSearch() {
    const searchInput = document.getElementById('mainSearchInput');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        clearTimeout(liveSearchTimeout);
        
        if (query.length === 0) {
            hideLiveSearchResults();
            return;
        }
        
        performLiveSearch(query);
    });
    
    searchInput.addEventListener('focus', function(e) {
        const query = e.target.value.trim();
        if (query.length >= 1) {
            performLiveSearch(query);
        }
    });
    
    document.addEventListener('click', function(e) {
        const container = document.getElementById('liveSearchContainer');
        const searchInput = document.getElementById('mainSearchInput');
        const searchWrapper = document.querySelector('.search-input-wrapper');
        
        if (container && searchInput && !container.contains(e.target) && 
            e.target !== searchInput && !searchWrapper?.contains(e.target)) {
            hideLiveSearchResults();
        }
    });
    
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('mainAttendanceForm').submit();
        }
    });
}

function performLiveSearch(query) {
    if (!query || query.length < 1) return;
    
    const searchLower = query.toLowerCase();
    const resultsDiv = document.getElementById('liveSearchResults');
    const container = document.getElementById('liveSearchContainer');
    
    if (!resultsDiv || !container) return;
    
    container.classList.add('active');
    resultsDiv.innerHTML = `
        <div class="live-search-header">
            <span><i class="fas fa-spinner fa-spin"></i> Searching...</span>
            <span class="live-search-close" onclick="hideLiveSearchResults()"><i class="fas fa-times"></i></span>
        </div>
    `;
    
    clearTimeout(liveSearchTimeout);
    
    liveSearchTimeout = setTimeout(() => {
        const results = employeeData.filter(emp => 
            emp.searchText.includes(searchLower) || 
            emp.id.includes(searchLower)
        ).slice(0, 10);
        
        if (results.length > 0) {
            let html = `
                <div class="live-search-header">
                    <span><i class="fas fa-users"></i> ${results.length} employee${results.length !== 1 ? 's' : ''} found</span>
                    <span class="live-search-close" onclick="hideLiveSearchResults()"><i class="fas fa-times"></i></span>
                </div>
            `;
            
            results.forEach(emp => {
                let displayName = emp.name;
                if (searchLower) {
                    const regex = new RegExp(`(${searchLower})`, 'gi');
                    displayName = emp.name.replace(regex, '<strong>$1</strong>');
                }
                
                html += `
                    <div class="live-search-item" onclick="selectLiveSearchResult('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')">
                        <div class="live-search-item-info">
                            <div class="live-search-item-name">${displayName}</div>
                            <div class="live-search-item-details">
                                <span class="live-search-item-id"><i class="fas fa-id-badge"></i> ID: ${emp.id}</span>
                                <span class="live-search-item-position"><i class="fas fa-briefcase"></i> ${emp.position}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += `
                <div class="live-search-footer">
                    <i class="fas fa-enter"></i> Press Enter
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `
                <div class="live-search-header">
                    <span><i class="fas fa-exclamation-circle"></i> No employees found</span>
                    <span class="live-search-close" onclick="hideLiveSearchResults()"><i class="fas fa-times"></i></span>
                </div>
                <div class="live-search-no-results">
                    <i class="fas fa-user-slash"></i>
                    <h4>No results found</h4>
                    <p>No employees matching <strong>"${query}"</strong></p>
                </div>
                <div class="live-search-footer">
                    <i class="fas fa-enter"></i> Press Enter
                </div>
            `;
        }
    }, 200);
}

function selectLiveSearchResult(id, name) {
    const searchInput = document.getElementById('mainSearchInput');
    if (searchInput) {
        searchInput.value = name;
    }
    
    hideLiveSearchResults();
    
    const currentDate = document.getElementById('selectedDate')?.value || '<?= $selected_date ?>';
    window.location.href = `attendance.php?date=${currentDate}&employee_id=${id}`;
}

function hideLiveSearchResults() {
    const container = document.getElementById('liveSearchContainer');
    if (container) {
        container.classList.remove('active');
        const resultsDiv = document.getElementById('liveSearchResults');
        if (resultsDiv) {
            resultsDiv.innerHTML = '';
        }
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openAddAttendanceModal() {
    const modal = document.getElementById('addAttendanceModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        isEditMode = false;
        
        const modalTitle = document.getElementById('modalTitle');
        if (modalTitle) {
            modalTitle.innerHTML = '<i class="fas fa-calendar-plus"></i> Add Attendance Record';
        }
        
        // Set date from the current filter
        const currentDate = document.getElementById('selectedDate').value;
        const dateObj = new Date(currentDate);
        
        const dateField = document.getElementById('attendanceDateField');
        const dateHidden = document.getElementById('attendanceDate');
        
        if (dateField) {
            dateField.value = dateObj.toLocaleDateString('en-US', {
                month: '2-digit',
                day: '2-digit',
                year: 'numeric'
            });
        }
        if (dateHidden) {
            dateHidden.value = currentDate;
        }
        
        modalCurrentDate = new Date(currentDate);
        modalSelectedDate = currentDate;
        
        resetAttendanceForm();
        
        const notificationArea = document.getElementById('notificationArea');
        if (notificationArea) {
            notificationArea.innerHTML = '';
        }
        
        const searchContainer = document.getElementById('employeeSearchContainer');
        if (searchContainer) {
            searchContainer.style.display = 'block';
        }
        
        setTimeout(() => {
            const searchInput = document.getElementById('employeeSearchInput');
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
        }, 300);
    }
}

function closeAddAttendanceModal() {
    const modal = document.getElementById('addAttendanceModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        
        const dropdown = document.getElementById('employeeResultsDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
        
        const modalCalendar = document.getElementById('modalCalendarWrapper');
        if (modalCalendar) {
            modalCalendar.style.display = 'none';
        }
    }
}

function closeTimeModal() {
    const modal = document.getElementById('timeModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        currentTimeField = null;
    }
}

// ============================================
// DELETE FUNCTIONS
// ============================================
function confirmDelete(employeeId, date, employeeName) {
    document.getElementById('deleteEmployeeName').textContent = employeeName;
    document.getElementById('deleteDate').textContent = new Date(date).toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
    document.getElementById('deleteEmployeeId').value = employeeId;
    document.getElementById('deleteAttendanceDate').value = date;
    
    document.getElementById('deleteConfirmModal').style.display = 'flex';
    document.getElementById('deleteConfirmModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    document.getElementById('deleteConfirmModal').classList.remove('show');
}

function deleteAttendance() {
    const employeeId = document.getElementById('deleteEmployeeId').value;
    const date = document.getElementById('deleteAttendanceDate').value;
    
    if (!employeeId || !date) {
        showNotification('Missing information', 'error');
        closeDeleteModal();
        return;
    }
    
    // Show loading state
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    const originalText = deleteBtn.innerHTML;
    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    deleteBtn.disabled = true;
    
    fetch('delete_attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'employee_id=' + employeeId + '&date=' + date
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showNotification(data.message, 'error');
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        }
        closeDeleteModal();
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error deleting record', 'error');
        deleteBtn.innerHTML = originalText;
        deleteBtn.disabled = false;
        closeDeleteModal();
    });
}

// ============================================
// VIEW ATTENDANCE FUNCTION (FIXED)
// ============================================
function viewAttendance(employeeId, date, employeeName) {
    const modal = document.getElementById('viewAttendanceModal');
    const addModal = document.getElementById('addAttendanceModal');
    if (addModal) addModal.style.display = 'none';
    
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        isViewMode = true;
        
        // Clear previous data
        document.getElementById('viewEmployeeName').textContent = employeeName;
        document.getElementById('viewEmployeeId').textContent = employeeId;
        
        // Get position
        const select = document.getElementById('employeeSelect');
        if (select) {
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value == employeeId) {
                    const position = select.options[i].getAttribute('data-position') || 'N/A';
                    document.getElementById('viewEmployeePositionText').textContent = position;
                    break;
                }
            }
        }
        
        const dateObj = new Date(date);
        const formattedDate = dateObj.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        document.getElementById('viewDate').textContent = formattedDate;
        
        document.getElementById('viewSite').textContent = '--';
        
        // Reset displays
        document.getElementById('viewAmStatus').textContent = '--';
        document.getElementById('viewPmStatus').textContent = '--';
        document.getElementById('viewNightStatus').textContent = '--';
        
        document.getElementById('viewTimeInAmDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        document.getElementById('viewTimeOutAmDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        document.getElementById('viewTimeInPmDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        document.getElementById('viewTimeOutPmDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        document.getElementById('viewTimeInNightDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        document.getElementById('viewTimeOutNightDisplay').querySelector('.time-display-content').innerHTML = '<i class="far fa-clock"></i> --';
        
        document.getElementById('viewAmHours').textContent = '0.00';
        document.getElementById('viewPmHours').textContent = '0.00';
        document.getElementById('viewNightHours').textContent = '0.00';
        document.getElementById('viewTotalAmHours').textContent = '0.00';
        document.getElementById('viewTotalPmHours').textContent = '0.00';
        document.getElementById('viewTotalNightHours').textContent = '0.00';
        document.getElementById('viewTotalHours').textContent = '0.00';
        
        document.getElementById('viewLeaveContainer').style.display = 'none';
        document.getElementById('viewWorkdayContainer').style.display = 'none';
        
        document.getElementById('viewAmStatusDot').style.display = 'none';
        document.getElementById('viewAmStatusDotAbsent').style.display = 'none';
        document.getElementById('viewAmStatusDotLeave').style.display = 'none';
        document.getElementById('viewPmStatusDot').style.display = 'none';
        document.getElementById('viewPmStatusDotAbsent').style.display = 'none';
        document.getElementById('viewPmStatusDotLeave').style.display = 'none';
        document.getElementById('viewNightStatusDot').style.display = 'none';
        document.getElementById('viewNightStatusDotAbsent').style.display = 'none';
        document.getElementById('viewNightStatusDotLeave').style.display = 'none';
        
        showNotification('Loading attendance data...', 'info', 'viewNotificationArea');
        
        fetchViewAttendanceData(employeeId, date);
    }
}

async function fetchViewAttendanceData(employeeId, date) {
    try {
        const response = await fetch(`get_attendance.php?employee_id=${employeeId}&date=${date}`);
        const data = await response.json();
        
        if (data.success) {
            // Set record ID
            document.getElementById('viewRecordId').textContent = data.id || '--';
            
            // Handle Leave - display if leave_type exists
            if (data.leave_type && data.leave_type !== '') {
                document.getElementById('viewLeaveContainer').style.display = 'block';
                document.getElementById('viewLeaveType').textContent = data.leave_type;
            } else {
                document.getElementById('viewLeaveContainer').style.display = 'none';
            }
            
            // Handle Workday Type - always show if exists
            if (data.workday_type && data.workday_type !== '') {
                document.getElementById('viewWorkdayContainer').style.display = 'block';
                document.getElementById('viewWorkdayType').textContent = data.workday_type || '';
            } else {
                document.getElementById('viewWorkdayContainer').style.display = 'none';
            }
            
            // ============================================
            // AM STATUS - Display
            // ============================================
            if (data.status) {
                document.getElementById('viewAmStatus').textContent = data.status;
                if (data.status === 'Present') {
                    document.getElementById('viewAmStatusDot').style.display = 'inline-block';
                    document.getElementById('viewAmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewAmStatusDotLeave').style.display = 'none';
                } else if (data.status === 'Absent') {
                    document.getElementById('viewAmStatusDot').style.display = 'none';
                    document.getElementById('viewAmStatusDotAbsent').style.display = 'inline-block';
                    document.getElementById('viewAmStatusDotLeave').style.display = 'none';
                } else if (data.status === 'On Leave') {
                    document.getElementById('viewAmStatusDot').style.display = 'none';
                    document.getElementById('viewAmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewAmStatusDotLeave').style.display = 'inline-block';
                }
            } else {
                const hasAmTime = (data.time_in_am && data.time_in_am !== '') || 
                                 (data.time_out_am && data.time_out_am !== '');
                if (hasAmTime) {
                    document.getElementById('viewAmStatus').textContent = 'Present';
                    document.getElementById('viewAmStatusDot').style.display = 'inline-block';
                    document.getElementById('viewAmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewAmStatusDotLeave').style.display = 'none';
                } else {
                    document.getElementById('viewAmStatus').textContent = 'No Record';
                    document.getElementById('viewAmStatusDot').style.display = 'none';
                    document.getElementById('viewAmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewAmStatusDotLeave').style.display = 'none';
                }
            }
            
            // ============================================
            // PM STATUS - Display
            // ============================================
            if (data.pm_status) {
                document.getElementById('viewPmStatus').textContent = data.pm_status;
                if (data.pm_status === 'Present') {
                    document.getElementById('viewPmStatusDot').style.display = 'inline-block';
                    document.getElementById('viewPmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewPmStatusDotLeave').style.display = 'none';
                } else if (data.pm_status === 'Absent') {
                    document.getElementById('viewPmStatusDot').style.display = 'none';
                    document.getElementById('viewPmStatusDotAbsent').style.display = 'inline-block';
                    document.getElementById('viewPmStatusDotLeave').style.display = 'none';
                } else if (data.pm_status === 'On Leave') {
                    document.getElementById('viewPmStatusDot').style.display = 'none';
                    document.getElementById('viewPmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewPmStatusDotLeave').style.display = 'inline-block';
                }
            } else {
                const hasPmTime = (data.time_in_pm && data.time_in_pm !== '') || 
                                 (data.time_out_pm && data.time_out_pm !== '');
                if (hasPmTime) {
                    document.getElementById('viewPmStatus').textContent = 'Present';
                    document.getElementById('viewPmStatusDot').style.display = 'inline-block';
                    document.getElementById('viewPmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewPmStatusDotLeave').style.display = 'none';
                } else {
                    document.getElementById('viewPmStatus').textContent = 'No Record';
                    document.getElementById('viewPmStatusDot').style.display = 'none';
                    document.getElementById('viewPmStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewPmStatusDotLeave').style.display = 'none';
                }
            }
            
            // ============================================
            // NIGHT STATUS - Display
            // ============================================
            if (data.night_status) {
                document.getElementById('viewNightStatus').textContent = data.night_status;
                if (data.night_status === 'Present') {
                    document.getElementById('viewNightStatusDot').style.display = 'inline-block';
                    document.getElementById('viewNightStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewNightStatusDotLeave').style.display = 'none';
                } else if (data.night_status === 'Absent') {
                    document.getElementById('viewNightStatusDot').style.display = 'none';
                    document.getElementById('viewNightStatusDotAbsent').style.display = 'inline-block';
                    document.getElementById('viewNightStatusDotLeave').style.display = 'none';
                } else if (data.night_status === 'On Leave') {
                    document.getElementById('viewNightStatusDot').style.display = 'none';
                    document.getElementById('viewNightStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewNightStatusDotLeave').style.display = 'inline-block';
                }
            } else {
                const hasNightTime = (data.time_in_night && data.time_in_night !== '') || 
                                    (data.time_out_night && data.time_out_night !== '');
                if (hasNightTime) {
                    document.getElementById('viewNightStatus').textContent = 'Present';
                    document.getElementById('viewNightStatusDot').style.display = 'inline-block';
                    document.getElementById('viewNightStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewNightStatusDotLeave').style.display = 'none';
                } else {
                    document.getElementById('viewNightStatus').textContent = 'No Record';
                    document.getElementById('viewNightStatusDot').style.display = 'none';
                    document.getElementById('viewNightStatusDotAbsent').style.display = 'none';
                    document.getElementById('viewNightStatusDotLeave').style.display = 'none';
                }
            }
            
            // ============================================
            // AM TIME DISPLAYS - Fixed for 00:00:00
            // ============================================
            
            // AM Time In
            if (data.time_in_am !== null && data.time_in_am !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_am);
                document.getElementById('viewTimeInAmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeInAmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
            // AM Time Out
            if (data.time_out_am !== null && data.time_out_am !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_am);
                document.getElementById('viewTimeOutAmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeOutAmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
            // ============================================
            // PM TIME DISPLAYS - Fixed for 00:00:00
            // ============================================
            
            // PM Time In
            if (data.time_in_pm !== null && data.time_in_pm !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_pm);
                document.getElementById('viewTimeInPmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeInPmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
            // PM Time Out
            if (data.time_out_pm !== null && data.time_out_pm !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_pm);
                document.getElementById('viewTimeOutPmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeOutPmDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
            // ============================================
            // NIGHT TIME DISPLAYS - Fixed for 00:00:00
            // ============================================
            
            // Night Time In
            if (data.time_in_night !== null && data.time_in_night !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_night);
                document.getElementById('viewTimeInNightDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeInNightDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
            // Night Time Out
            if (data.time_out_night !== null && data.time_out_night !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_night);
                document.getElementById('viewTimeOutNightDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> ${timeDisplay}`;
            } else {
                document.getElementById('viewTimeOutNightDisplay').querySelector('.time-display-content').innerHTML = `<i class="far fa-clock"></i> --:-- --`;
            }
            
           // ============================================
// Site
if (data.site) {
    document.getElementById('viewSite').textContent = data.site;
} else {
    document.getElementById('viewSite').textContent = '--';
}
            
            // ============================================
            // HOURS CALCULATION
            // ============================================
            const amHours = calculateHours(data.time_in_am, data.time_out_am);
            const pmHours = calculateHours(data.time_in_pm, data.time_out_pm);
            const nightHours = calculateHours(data.time_in_night, data.time_out_night);
            const totalHours = amHours + pmHours + nightHours;
            
            document.getElementById('viewAmHours').textContent = amHours.toFixed(2);
            document.getElementById('viewPmHours').textContent = pmHours.toFixed(2);
            document.getElementById('viewNightHours').textContent = nightHours.toFixed(2);
            document.getElementById('viewTotalAmHours').textContent = amHours.toFixed(2);
            document.getElementById('viewTotalPmHours').textContent = pmHours.toFixed(2);
            document.getElementById('viewTotalNightHours').textContent = nightHours.toFixed(2);
            document.getElementById('viewTotalHours').textContent = totalHours.toFixed(2);
            
            // Store data for edit button
            window.currentViewData = {
                employeeId: employeeId,
                date: date,
                employeeName: document.getElementById('viewEmployeeName').textContent
            };
            
            showNotification('Data loaded successfully', 'success', 'viewNotificationArea');
        } else {
            showNotification('Error loading data: ' + (data.message || 'Unknown'), 'error', 'viewNotificationArea');
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('Error loading data', 'error', 'viewNotificationArea');
    }
}

function editFromView() {
    if (window.currentViewData) {
        closeViewAttendanceModal();
        editAttendance(
            window.currentViewData.employeeId, 
            window.currentViewData.date, 
            window.currentViewData.employeeName
        );
    }
}

// ============================================
// EDIT ATTENDANCE FUNCTION
// ============================================
function editAttendance(employeeId, date, employeeName) {
    const modal = document.getElementById('addAttendanceModal');
    const viewModal = document.getElementById('viewAttendanceModal');
    if (viewModal) viewModal.style.display = 'none';
    
    resetAttendanceForm();
    
    currentEmployeeId = employeeId;
    isEditMode = true;
    isViewMode = false;
    
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        const modalTitle = document.getElementById('modalTitle');
        if (modalTitle) {
            modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Attendance Record';
        }
        
        const dateObj = new Date(date);
        const formattedDisplay = dateObj.toLocaleDateString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });
        
        const dateField = document.getElementById('attendanceDateField');
        const dateHidden = document.getElementById('attendanceDate');
        
        if (dateField) dateField.value = formattedDisplay;
        if (dateHidden) dateHidden.value = date;
        
        modalCurrentDate = new Date(date);
        modalSelectedDate = date;
        
        const searchContainer = document.getElementById('employeeSearchContainer');
        if (searchContainer) {
            searchContainer.style.display = 'none';
        }
        
        const dropdown = document.getElementById('employeeResultsDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
        
        const modalCalendar = document.getElementById('modalCalendarWrapper');
        if (modalCalendar) {
            modalCalendar.style.display = 'none';
        }
        
        const select = document.getElementById('employeeSelect');
        if (select) {
            select.value = employeeId;
        }
        
        const selectedCard = document.getElementById('selectedEmployeeCard');
        const selectedName = document.getElementById('selectedEmployeeName');
        const selectedId = document.getElementById('selectedEmployeeId');
        const employeeIdDisplay = document.getElementById('employeeIdDisplay2');
        const selectedPositionText = document.getElementById('selectedEmployeePositionText');
        
        const selectedOption = select.options[select.selectedIndex];
        const position = selectedOption ? selectedOption.getAttribute('data-position') || 'N/A' : 'N/A';
        
        if (selectedCard) {
            selectedCard.style.display = 'block';
            selectedName.textContent = employeeName;
            selectedId.textContent = employeeId;
            selectedPositionText.textContent = position;
        }
        
        if (employeeIdDisplay) {
            employeeIdDisplay.textContent = employeeId;
        }
        
        updateEmployeeId();
        
        showNotification('Loading data...', 'info');
        
        fetchAttendanceData(employeeId, date);
    }
}
async function fetchAttendanceData(employeeId, date) {
    try {
        const response = await fetch(`get_attendance.php?employee_id=${employeeId}&date=${date}`);
        const data = await response.json();
        
        if (data.success) {
            clearNotification();
            
            resetTimeFields('am');
            resetTimeFields('pm');
            resetTimeFields('night');
            
            // STORE WORKDAY TYPE AND LEAVE TYPE VALUES
            const storedWorkdayType = data.workday_type || '';
            const storedLeaveType = data.leave_type || '';
            
            // Set AM Status
            if (data.status) {
                if (data.status === 'Absent') {
                    document.getElementById('statusAbsent').checked = true;
                    disableTimeInputs('am', true);
                } else if (data.status === 'On Leave') {
                    document.getElementById('statusPresent').checked = false;
                    document.getElementById('statusAbsent').checked = false;
                    // Don't set any radio button for On Leave since it's removed
                    disableTimeInputs('am', true);
                    disableTimeInputs('pm', true);
                    disableTimeInputs('night', true);
                } else if (data.status === 'Present') {
                    document.getElementById('statusPresent').checked = true;
                    disableTimeInputs('am', false);
                }
            }
            
            // Set leave type dropdown value
            if (storedLeaveType) {
                document.getElementById('leaveType').value = storedLeaveType;
            }
            
            // WORKDAY TYPE - Set the standalone dropdown value
            if (storedWorkdayType) {
                document.getElementById('workdayType').value = storedWorkdayType;
                console.log('Workday loaded - workday type set to:', storedWorkdayType);
            }
            
            // Set PM status if available in data
            if (data.pm_status) {
                if (data.pm_status === 'Present') {
                    document.getElementById('pmStatusPresent').checked = true;
                    disableTimeInputs('pm', false);
                } else if (data.pm_status === 'Absent') {
                    document.getElementById('pmStatusAbsent').checked = true;
                    disableTimeInputs('pm', true);
                    resetTimeFields('pm');
                } else if (data.pm_status === 'On Leave') {
                    // No radio button for On Leave, just disable
                    disableTimeInputs('pm', true);
                    resetTimeFields('pm');
                }
            }
            
            // Set Night status if available in data
            if (data.night_status) {
                if (data.night_status === 'Present') {
                    document.getElementById('nightStatusPresent').checked = true;
                    disableTimeInputs('night', false);
                } else if (data.night_status === 'Absent') {
                    document.getElementById('nightStatusAbsent').checked = true;
                    disableTimeInputs('night', true);
                    resetTimeFields('night');
                } else if (data.night_status === 'On Leave') {
                    // No radio button for On Leave, just disable
                    disableTimeInputs('night', true);
                    resetTimeFields('night');
                }
            }
            
            // Set attendance ID if editing
            if (data.id) {
                document.getElementById('attendanceId').value = data.id;
            }
            
            // ============================================
            // AM TIME FIELDS - Fixed to handle 00:00:00
            // ============================================
            
            // AM Time In - allow 00:00:00 (midnight)
            if (data.time_in_am !== null && data.time_in_am !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_am);
                const timeElement = document.getElementById('timeInAm');
                const displayElement = document.getElementById('timeInAmDisplay');
                
                if (timeElement) timeElement.value = data.time_in_am;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeInAmBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set AM';
                    btn.style.background = '#28a745';
                }
            }
            
            // AM Time Out - allow 00:00:00 (midnight)
            if (data.time_out_am !== null && data.time_out_am !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_am);
                const timeElement = document.getElementById('timeOutAm');
                const displayElement = document.getElementById('timeOutAmDisplay');
                
                if (timeElement) timeElement.value = data.time_out_am;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeOutAmBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set AM';
                    btn.style.background = '#28a745';
                }
            }
            
            // ============================================
            // PM TIME FIELDS - Fixed to handle 00:00:00
            // ============================================
            
            // PM Time In - allow 00:00:00 (midnight)
            if (data.time_in_pm !== null && data.time_in_pm !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_pm);
                const timeElement = document.getElementById('timeInPm');
                const displayElement = document.getElementById('timeInPmDisplay');
                
                if (timeElement) timeElement.value = data.time_in_pm;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeInPmBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set PM';
                    btn.style.background = '#28a745';
                }
            }
            
            // PM Time Out - allow 00:00:00 (midnight)
            if (data.time_out_pm !== null && data.time_out_pm !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_pm);
                const timeElement = document.getElementById('timeOutPm');
                const displayElement = document.getElementById('timeOutPmDisplay');
                
                if (timeElement) timeElement.value = data.time_out_pm;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeOutPmBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set PM';
                    btn.style.background = '#28a745';
                }
            }
            
            // ============================================
            // NIGHT TIME FIELDS - Fixed to handle 00:00:00
            // ============================================
            
            // Night Time In - allow 00:00:00 (midnight)
            if (data.time_in_night !== null && data.time_in_night !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_in_night);
                const timeElement = document.getElementById('timeInNight');
                const displayElement = document.getElementById('timeInNightDisplay');
                
                if (timeElement) timeElement.value = data.time_in_night;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeInNightBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set Night';
                    btn.style.background = '#28a745';
                }
            }
            
            // Night Time Out - allow 00:00:00 (midnight)
            if (data.time_out_night !== null && data.time_out_night !== '') {
                const timeDisplay = formatTimeForDisplay(data.time_out_night);
                const timeElement = document.getElementById('timeOutNight');
                const displayElement = document.getElementById('timeOutNightDisplay');
                
                if (timeElement) timeElement.value = data.time_out_night;
                if (displayElement) {
                    displayElement.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
                    displayElement.classList.remove('empty');
                    displayElement.classList.remove('validation-error');
                }
                
                const btn = document.getElementById('timeOutNightBtn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Set Night';
                    btn.style.background = '#28a745';
                }
            }
            
// Set site dropdown
if (data.site) {
    document.getElementById('site').value = data.site;
} else {
    document.getElementById('site').value = '';
}
            
            // Update total hours calculation
            updateTotalHours();
            
            showNotification('Attendance data loaded successfully', 'success');
        } else {
            showNotification('Error loading attendance data: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error fetching attendance data:', error);
        showNotification('Error loading attendance data. Please try again.', 'error');
    }
}
function clearNotification() {
    const notificationArea = document.getElementById('notificationArea');
    if (notificationArea) {
        notificationArea.innerHTML = '';
    }
}

function closeViewAttendanceModal() {
    const modal = document.getElementById('viewAttendanceModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// FORM FUNCTIONS
// ============================================
function updateEmployeeId() {
    const select = document.getElementById('employeeSelect');
    const display = document.getElementById('employeeIdDisplay2');
    if (select && display) {
        if (select.value) {
            display.textContent = select.value;
        } else {
            display.textContent = '--';
        }
    }
}

function resetAttendanceForm() {
    const form = document.getElementById('addAttendanceForm');
    if (form) form.reset();
    
    // Clear employee selection
    const selectedCard = document.getElementById('selectedEmployeeCard');
    if (selectedCard) {
        selectedCard.style.display = 'none';
    }
    
    const searchContainer = document.getElementById('employeeSearchContainer');
    if (searchContainer) {
        searchContainer.style.display = 'block';
    }
    
    const idDisplay = document.getElementById('employeeIdDisplay2');
    if (idDisplay) idDisplay.textContent = '--';
    
    resetTimeFields('am');
    resetTimeFields('pm');
    resetTimeFields('night');
    
    // DO NOT auto-check any radio buttons
    // Let them all be unchecked initially
    
// Reset site dropdown
const siteSelect = document.getElementById('site');
if (siteSelect) siteSelect.value = '';

    // Reset leave type dropdown
    const leaveSelect = document.getElementById('leaveType');
    if (leaveSelect) leaveSelect.value = '';
    
    // Workday type is standalone - just reset the dropdown
    const workdaySelect = document.getElementById('workdayType');
    if (workdaySelect) workdaySelect.value = '';
    
    // Enable all time inputs by default
    disableTimeInputs('am', false);
    disableTimeInputs('pm', false);
    disableTimeInputs('night', false);
    
    const attendanceId = document.getElementById('attendanceId');
    if (attendanceId) attendanceId.value = '';
    
    clearValidationHighlights();
    
    // Hide workday error message
    const workdayError = document.getElementById('workdayError');
    if (workdayError) {
        workdayError.style.display = 'none';
    }
}

// ============================================
// TIME MODAL FUNCTIONS
// ============================================
function openTimeModal(field) {
    if (isViewMode) return;
    
    currentTimeField = field;
    const modal = document.getElementById('timeModal');
    const title = document.getElementById('timeModalTitle');
    
    let fieldName = '';
    
    if (field === 'time_in_am' || field === 'time_out_am') {
        fieldName = 'AM Time';
    } else if (field === 'time_in_pm' || field === 'time_out_pm') {
        fieldName = 'PM Time';
    } else if (field === 'time_in_night' || field === 'time_out_night') {
        fieldName = 'Night Time';
    }
    
    if (modal) {
        title.innerHTML = `<i class="fas fa-clock"></i> Set ${fieldName}`;
        modal.style.display = 'flex';
        modal.classList.add('show');
        populateTimeDropdowns();
    }
}

function populateTimeDropdowns() {
    const hourSelect = document.getElementById('timeHour');
    const minuteSelect = document.getElementById('timeMinute');
    const periodSelect = document.getElementById('timePeriod');
    
    if (!hourSelect || !minuteSelect || !periodSelect) return;
    
    hourSelect.innerHTML = '';
    minuteSelect.innerHTML = '';
    
    // Create hours 1-12
    for (let i = 1; i <= 12; i++) {
        const option = document.createElement('option');
        option.value = i.toString().padStart(2, '0');
        option.textContent = i.toString().padStart(2, '0');
        hourSelect.appendChild(option);
    }
    
    // Create minutes 0-59
    for (let i = 0; i < 60; i++) {
        const option = document.createElement('option');
        option.value = i.toString().padStart(2, '0');
        option.textContent = i.toString().padStart(2, '0');
        minuteSelect.appendChild(option);
    }
    
    // Set defaults
    if (currentTimeField && currentTimeField.includes('am')) {
        periodSelect.value = 'AM';
        hourSelect.value = '12';  // Set to 12 for AM times
    } else if (currentTimeField && currentTimeField.includes('pm')) {
        periodSelect.value = 'PM';
        hourSelect.value = '01';
    } else if (currentTimeField && currentTimeField.includes('night')) {
        periodSelect.value = 'PM';
        hourSelect.value = '06';
    }
    
    minuteSelect.value = '00';
}
function saveTime() {
    if (!currentTimeField) return;
    
    const hour = document.getElementById('timeHour').value;
    const minute = document.getElementById('timeMinute').value;
    const period = document.getElementById('timePeriod').value;
    
    if (!hour || !minute || !period) {
        showNotification('Please select a valid time', 'error');
        return;
    }
    
    const displayHour = parseInt(hour);
    // Fix: Show 12:00 AM correctly
    const timeDisplay = `${displayHour}:${minute} ${period}`;
    const time24 = convertTimeTo24Hour(hour, minute, period);
    
    // Rest of the function remains the same...

    switch(currentTimeField) {
        case 'time_in_am':
            updateTimeDisplay('timeInAmDisplay', 'timeInAm', timeDisplay, time24, 'AM');
            break;
        case 'time_out_am':
            updateTimeDisplay('timeOutAmDisplay', 'timeOutAm', timeDisplay, time24, 'AM');
            break;
        case 'time_in_pm':
            updateTimeDisplay('timeInPmDisplay', 'timeInPm', timeDisplay, time24, 'PM');
            break;
        case 'time_out_pm':
            updateTimeDisplay('timeOutPmDisplay', 'timeOutPm', timeDisplay, time24, 'PM');
            break;
        case 'time_in_night':
            updateTimeDisplay('timeInNightDisplay', 'timeInNight', timeDisplay, time24, 'Night');
            break;
        case 'time_out_night':
            updateTimeDisplay('timeOutNightDisplay', 'timeOutNight', timeDisplay, time24, 'Night');
            break;
    }
    
    closeTimeModal();
}

function updateTimeDisplay(displayId, inputId, timeDisplay, time24, period) {
    const display = document.getElementById(displayId);
    const input = document.getElementById(inputId);
    const btn = document.getElementById(inputId + 'Btn');
    
    if (display) {
        display.innerHTML = `<div class="time-display-content"><i class="far fa-clock"></i> ${timeDisplay}</div>`;
        display.classList.remove('empty');
        display.classList.remove('validation-error');
    }
    if (input) input.value = time24;
    if (btn) {
        btn.innerHTML = `<i class="fas fa-check"></i> Set ${period}`;
        btn.style.background = '#28a745';
    }
    
    const errorId = inputId + 'Error';
    const errorElement = document.getElementById(errorId);
    if (errorElement) {
        errorElement.style.display = 'none';
    }
    
    updateTotalHours();
}

// ============================================
// FORM SUBMISSION - WITH WORKDAY TYPE REQUIRED
// ============================================
function submitAttendanceForm() {
    if (isViewMode) return;
    
    const employeeSelect = document.getElementById('employeeSelect');
    const statusPresent = document.getElementById('statusPresent');
    const statusAbsent = document.getElementById('statusAbsent');
    const pmStatusPresent = document.getElementById('pmStatusPresent');
    const pmStatusAbsent = document.getElementById('pmStatusAbsent');
    const nightStatusPresent = document.getElementById('nightStatusPresent');
    const nightStatusAbsent = document.getElementById('nightStatusAbsent');
    const attendanceDate = document.getElementById('attendanceDate').value;
    
    // WORKDAY TYPE - REQUIRED
    const workdayType = document.getElementById('workdayType').value;
    
    const timeInAm = document.getElementById('timeInAm').value;
    const timeOutAm = document.getElementById('timeOutAm').value;
    const timeInPm = document.getElementById('timeInPm').value;
    const timeOutPm = document.getElementById('timeOutPm').value;
    const timeInNight = document.getElementById('timeInNight').value;
    const timeOutNight = document.getElementById('timeOutNight').value;
    
    clearValidationHighlights();
    
    // Hide workday error message initially
    const workdayError = document.getElementById('workdayError');
    if (workdayError) {
        workdayError.style.display = 'none';
    }
    
    if (!employeeSelect.value) {
        showNotification('Please select an employee', 'error');
        if (!isEditMode) {
            document.getElementById('employeeSearchInput').focus();
        }
        return false;
    }
    
    if (!attendanceDate) {
        showNotification('Please select a date', 'error');
        document.getElementById('attendanceDateField').focus();
        return false;
    }
    
    // WORKDAY TYPE VALIDATION - REQUIRED FOR ALL
    if (!workdayType || workdayType === '') {
        if (workdayError) {
            workdayError.style.display = 'flex';
        }
        // Highlight the workday select
        document.getElementById('workdayType').style.borderColor = '#e74c3c';
        document.getElementById('workdayType').focus();
        
        // Scroll to workday section
        document.getElementById('workdayTypeContainer').scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        showNotification('Please select a Workday Type', 'error');
        return false;
    } else {
        document.getElementById('workdayType').style.borderColor = '#75e6da';
    }
    
    // AM Status validation - only require if Present is checked and no times
    if (statusPresent && statusPresent.checked) {
        let hasError = false;
        
        if (!timeInAm) {
            document.getElementById('timeInAmDisplay').classList.add('validation-error');
            document.getElementById('timeInAmError').style.display = 'flex';
            hasError = true;
        }
        if (!timeOutAm) {
            document.getElementById('timeOutAmDisplay').classList.add('validation-error');
            document.getElementById('timeOutAmError').style.display = 'flex';
            hasError = true;
        }
        
        if (hasError) {
            document.querySelector('.time-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
    }
    
    // PM Status validation - only if Present is checked
    if (pmStatusPresent && pmStatusPresent.checked) {
        let hasError = false;
        
        if (!timeInPm) {
            document.getElementById('timeInPmDisplay').classList.add('validation-error');
            document.getElementById('timeInPmError').style.display = 'flex';
            hasError = true;
        }
        if (!timeOutPm) {
            document.getElementById('timeOutPmDisplay').classList.add('validation-error');
            document.getElementById('timeOutPmError').style.display = 'flex';
            hasError = true;
        }
        
        if (hasError) {
            const pmSection = document.querySelectorAll('.time-section')[1];
            if (pmSection) {
                pmSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
    }
    
    // Night Status validation - only if Present is checked
    if (nightStatusPresent && nightStatusPresent.checked) {
        let hasError = false;
        
        if (!timeInNight) {
            document.getElementById('timeInNightDisplay').classList.add('validation-error');
            document.getElementById('timeInNightError').style.display = 'flex';
            hasError = true;
        }
        if (!timeOutNight) {
            document.getElementById('timeOutNightDisplay').classList.add('validation-error');
            document.getElementById('timeOutNightError').style.display = 'flex';
            hasError = true;
        }
        
        if (hasError) {
            const nightSection = document.querySelectorAll('.time-section')[2];
            if (nightSection) {
                nightSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
    }
    
    // Leave type is optional - no validation required
    
    // Handle absent - clear only that session's times
    if (statusAbsent && statusAbsent.checked) {
        document.getElementById('timeInAm').value = '';
        document.getElementById('timeOutAm').value = '';
    }
    
    if (pmStatusAbsent && pmStatusAbsent.checked) {
        document.getElementById('timeInPm').value = '';
        document.getElementById('timeOutPm').value = '';
    }
    
    if (nightStatusAbsent && nightStatusAbsent.checked) {
        document.getElementById('timeInNight').value = '';
        document.getElementById('timeOutNight').value = '';
    }
    
    document.getElementById('addAttendanceForm').submit();
    return true;
}

// ============================================
// UTILITY FUNCTIONS
// ============================================
function formatDateForDisplay(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
}

function formatTimeForDisplay(timeString) {
    // Check if timeString is null, undefined, or empty string
    if (timeString === null || timeString === undefined || timeString === '') {
        return '--:-- --';
    }
    
    // Handle midnight specially - 00:00:00 should display as 12:00 AM
    if (timeString === '00:00:00') {
        return '12:00 AM';
    }
    
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const period = hour >= 12 ? 'PM' : 'AM';
    let hour12 = hour % 12;
    if (hour12 === 0) {
        hour12 = 12;
    }
    
    return `${hour12.toString().padStart(2, '0')}:${minutes} ${period}`;
}

// ============================================
// EVENT LISTENERS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    initEmployeeSearch();
    initLiveSearch();
    
    generateMainCalendarDays();
    generateModalCalendarDays();
    
    // Reset workday border on change
    const workdaySelect = document.getElementById('workdayType');
    if (workdaySelect) {
        workdaySelect.addEventListener('change', function() {
            this.style.borderColor = '#75e6da';
            const workdayError = document.getElementById('workdayError');
            if (workdayError) {
                workdayError.style.display = 'none';
            }
        });
    }
    
    const searchInput = document.getElementById('employeeSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length === 0) {
                const dropdown = document.getElementById('employeeResultsDropdown');
                const clearBtn = document.getElementById('clearSearch');
                if (dropdown) dropdown.style.display = 'none';
                if (clearBtn) clearBtn.style.display = 'none';
                return;
            }
            
            const clearBtn = document.getElementById('clearSearch');
            if (clearBtn) clearBtn.style.display = 'block';
            
            searchEmployees(query);
        });
        
        searchInput.addEventListener('focus', function(e) {
            const query = e.target.value.trim();
            if (query.length >= 1) {
                searchEmployees(query);
            }
        });
        
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.employee-search-wrapper');
            const dropdown = document.getElementById('employeeResultsDropdown');
            
            if (wrapper && dropdown && !wrapper.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }
    
    // Close calendars when clicking outside
    document.addEventListener('click', function(e) {
        const fromCalendar = document.getElementById('fromCalendar');
        const toCalendar = document.getElementById('toCalendar');
        const fromInput = document.querySelector('[onclick="toggleFromCalendar()"]');
        const toInput = document.querySelector('[onclick="toggleToCalendar()"]');
        
        if (fromCalendar && !fromCalendar.contains(e.target) && !fromInput?.contains(e.target)) {
            fromCalendar.classList.remove('show');
        }
        
        if (toCalendar && !toCalendar.contains(e.target) && !toInput?.contains(e.target)) {
            toCalendar.classList.remove('show');
        }
    });
    
    document.addEventListener('click', function(e) {
        const mainDatePicker = document.querySelector('.main-date-picker-wrapper');
        const mainCalendar = document.getElementById('mainCalendarWrapper');
        
        if (mainDatePicker && mainCalendar && !mainDatePicker.contains(e.target)) {
            mainCalendar.style.display = 'none';
        }
    });
    
    document.addEventListener('click', function(e) {
        const datePicker = document.querySelector('.date-picker-wrapper');
        const modalCalendar = document.getElementById('modalCalendarWrapper');
        
        if (datePicker && modalCalendar && !datePicker.contains(e.target)) {
            modalCalendar.style.display = 'none';
        }
    });
    
    window.addEventListener('click', function(event) {
        const addModal = document.getElementById('addAttendanceModal');
        const deleteModal = document.getElementById('deleteConfirmModal');
        const timeModal = document.getElementById('timeModal');
        const viewModal = document.getElementById('viewAttendanceModal');
        const reportModal = document.getElementById('reportModal');
        
        if (event.target === addModal) {
            closeAddAttendanceModal();
        }
        
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
        
        if (event.target === timeModal) {
            closeTimeModal();
        }
        
        if (event.target === viewModal) {
            closeViewAttendanceModal();
        }
        
        if (event.target === reportModal) {
            closeReportModal();
        }
    });
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const addModal = document.getElementById('addAttendanceModal');
            const deleteModal = document.getElementById('deleteConfirmModal');
            const timeModal = document.getElementById('timeModal');
            const viewModal = document.getElementById('viewAttendanceModal');
            const reportModal = document.getElementById('reportModal');
            const fromCalendar = document.getElementById('fromCalendar');
            const toCalendar = document.getElementById('toCalendar');
            const mainCalendar = document.getElementById('mainCalendarWrapper');
            const dropdown = document.getElementById('employeeResultsDropdown');
            const modalCalendar = document.getElementById('modalCalendarWrapper');
            const liveSearch = document.getElementById('liveSearchContainer');
            
            if (addModal && addModal.style.display === 'flex') {
                closeAddAttendanceModal();
            }
            
            if (deleteModal && deleteModal.style.display === 'flex') {
                closeDeleteModal();
            }
            
            if (timeModal && timeModal.style.display === 'flex') {
                closeTimeModal();
            }
            
            if (viewModal && viewModal.style.display === 'flex') {
                closeViewAttendanceModal();
            }
            
            if (reportModal && reportModal.style.display === 'flex') {
                closeReportModal();
            }
            
            if (fromCalendar) fromCalendar.classList.remove('show');
            if (toCalendar) toCalendar.classList.remove('show');
            
            if (mainCalendar && mainCalendar.style.display === 'block') {
                mainCalendar.style.display = 'none';
            }
            
            if (dropdown && dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            }
            
            if (modalCalendar && modalCalendar.style.display === 'block') {
                modalCalendar.style.display = 'none';
            }
            
            if (liveSearch && liveSearch.classList.contains('active')) {
                hideLiveSearchResults();
            }
        }
    });
    
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 500);
        });
    }, 5000);
});

// Make functions globally accessible - UPDATED
window.openAddAttendanceModal = openAddAttendanceModal;
window.closeAddAttendanceModal = closeAddAttendanceModal;
window.viewAttendance = viewAttendance;
window.editAttendance = editAttendance;
window.editFromView = editFromView;
window.closeViewAttendanceModal = closeViewAttendanceModal;
window.updateEmployeeId = updateEmployeeId;
window.openTimeModal = openTimeModal;
window.closeTimeModal = closeTimeModal;
window.saveTime = saveTime;
window.submitAttendanceForm = submitAttendanceForm;
window.formatDateForDisplay = formatDateForDisplay;
window.formatTimeForDisplay = formatTimeForDisplay;
window.showNotification = showNotification;
window.selectEmployee = selectEmployee;
window.clearEmployeeSearch = clearEmployeeSearch;
window.changeEmployee = changeEmployee;
window.selectLiveSearchResult = selectLiveSearchResult;
window.hideLiveSearchResults = hideLiveSearchResults;
window.clearWorkdayInput = clearWorkdayInput;
window.confirmDelete = confirmDelete;
window.closeDeleteModal = closeDeleteModal;
window.deleteAttendance = deleteAttendance;
window.openReportModal = openReportModal;
window.closeReportModal = closeReportModal;
window.loadReportData = loadReportData;
window.printReport = printReport;

// Calendar functions
window.toggleFromCalendar = toggleFromCalendar;
window.toggleToCalendar = toggleToCalendar;
window.navigateFromMonth = navigateFromMonth;
window.navigateToMonth = navigateToMonth;
window.changeFromMonthYear = changeFromMonthYear;
window.changeToMonthYear = changeToMonthYear;
window.selectFromDate = selectFromDate;
window.selectToDate = selectToDate;
window.setFromToday = setFromToday;
window.setToToday = setToToday;
window.clearFromDate = clearFromDate;
window.clearToDate = clearToDate;

// Main calendar functions
window.toggleMainCalendar = toggleMainCalendar;
window.navigateMainMonth = navigateMainMonth;
window.selectMainDate = selectMainDate;
window.clearMainDate = clearMainDate;
window.setMainToday = setMainToday;
window.changeMainMonthYear = changeMainMonthYear;

// Modal calendar functions
window.toggleModalCalendar = toggleModalCalendar;
window.navigateModalMonth = navigateModalMonth;
window.selectModalDate = selectModalDate;
window.clearModalDate = clearModalDate;
window.setModalToday = setModalToday;
window.changeModalMonthYear = changeModalMonthYear;
</script>

<?php
// Close statements
if (isset($stmt)) {
    $stmt->close();
}
if (isset($employee_result)) {
    $employee_result->close();
}
$conn->close();
?>
</body>
</html>