<?php 
session_start();

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

include 'connection.php';

// Function to get site employees from ATTENDANCE table (not site_employee)
function getSiteEmployeesFromAttendance($conn, $site_id, $date = null) {
    $employees = [];
    
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    // Get site name first
    $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_id);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    $site = $site_result->fetch_assoc();
    $site_stmt->close();
    
    if (!$site) {
        return $employees;
    }
    
    $site_name = $site['site_name'];
    
    // Query attendance records where employee was assigned to this site in any session
    $query = "SELECT DISTINCT 
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
                    e.first_name,
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
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssss", $date, $site_name, $site_name, $site_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    return $employees;
}

// Function to get employee count from ATTENDANCE table
function getSiteEmployeeCountByDate($conn, $site_id, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    // Get site name first
    $site_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
    $site_stmt = $conn->prepare($site_query);
    $site_stmt->bind_param("i", $site_id);
    $site_stmt->execute();
    $site_result = $site_stmt->get_result();
    $site = $site_result->fetch_assoc();
    $site_stmt->close();
    
    if (!$site) {
        return 0;
    }
    
    $site_name = $site['site_name'];
    
    $query = "SELECT COUNT(DISTINCT a.employee_id) as count 
              FROM attendance a
              WHERE DATE(a.date) = DATE(?)
              AND (
                  a.site_assignment_am = ? OR 
                  a.site_assignment_pm = ? OR 
                  a.site_assignment_night = ?
              )";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssss", $date, $site_name, $site_name, $site_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    return $data ? $data['count'] : 0;
}

// Function to get others/assignment details
function getSiteOthersDetails($conn, $site_id) {
    $table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
    if ($table_check->num_rows == 0) {
        return null;
    }
    
    $query = "SELECT * FROM site_others WHERE site_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Save site/assignment to database
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new site
    if (isset($_POST['save_site'])) {
        $site_name = $_POST['site_name'];
        $manager = $_POST['site_manager'];
        $address = $_POST['site_address'];

        $query = "INSERT INTO site_monitoring (site_name, site_manager, site_address, is_others) VALUES (?, ?, ?, 0)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $site_name, $manager, $address);

        if ($stmt->execute()) {
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Site added successfully!'];
        } else {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error adding site: ' . $conn->error];
        }
        header("Location: site_monitoring.php");
        exit;
    }
    
    // Add new assignment (Others)
    if (isset($_POST['save_others'])) {
        $assignment_type = $_POST['assignment_type'];
        $person_group = $_POST['person_group'];
        $manager = $_POST['assignment_manager'];
        $location = $_POST['assignment_location'];

        $table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
        if ($table_check->num_rows == 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS site_others (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                assignment_type ENUM('Meeting','Project','Activities') NOT NULL,
                person_group VARCHAR(255) NOT NULL,
                manager VARCHAR(255) NOT NULL,
                location VARCHAR(255) NOT NULL,
                FOREIGN KEY (site_id) REFERENCES site_monitoring(id) ON DELETE CASCADE
            )");
        }

        $conn->begin_transaction();
        
        try {
            $site_name = $assignment_type;
            $query = "INSERT INTO site_monitoring (site_name, site_manager, site_address, is_others) VALUES (?, ?, ?, 1)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $site_name, $manager, $location);
            $stmt->execute();
            
            $site_id = $conn->insert_id;
            
            $others_query = "INSERT INTO site_others (site_id, assignment_type, person_group, manager, location) 
                            VALUES (?, ?, ?, ?, ?)";
            $others_stmt = $conn->prepare($others_query);
            $others_stmt->bind_param("issss", $site_id, $assignment_type, $person_group, $manager, $location);
            $others_stmt->execute();
            
            $conn->commit();
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Assignment created successfully!'];
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error creating assignment: ' . $conn->error];
        }
        
        header("Location: site_monitoring.php");
        exit;
    }
    
    // Update site information
if (isset($_POST['update_site'])) {
    $site_id = $_POST['site_id'];
    $site_name = $_POST['edit_site_name'];
    $manager = $_POST['edit_site_manager'];
    $address = $_POST['edit_site_address'];
    $is_others = isset($_POST['is_others']) ? $_POST['is_others'] : 0;
    
    $conn->begin_transaction();
    
    try {
        // Get the OLD site name before updating
        $old_name_query = "SELECT site_name FROM site_monitoring WHERE id = ?";
        $old_stmt = $conn->prepare($old_name_query);
        $old_stmt->bind_param("i", $site_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        $old_site = $old_result->fetch_assoc();
        $old_site_name = $old_site['site_name'];
        $old_stmt->close();
        
        if ($is_others) {
            $assignment_type = $_POST['edit_assignment_type'];
            $person_group = $_POST['edit_person_group'];
            $location = $address;
            
            $query = "UPDATE site_monitoring SET 
                      site_name = ?, 
                      site_manager = ?, 
                      site_address = ?
                      WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sssi", $assignment_type, $manager, $location, $site_id);
            $stmt->execute();
            
            $table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
            if ($table_check->num_rows > 0) {
                $check_query = "SELECT id FROM site_others WHERE site_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("i", $site_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $others_query = "UPDATE site_others SET 
                                    assignment_type = ?,
                                    person_group = ?,
                                    manager = ?,
                                    location = ?
                                    WHERE site_id = ?";
                    $others_stmt = $conn->prepare($others_query);
                    $others_stmt->bind_param("ssssi", $assignment_type, $person_group, $manager, $location, $site_id);
                    $others_stmt->execute();
                }
            }
        } else {
            $query = "UPDATE site_monitoring SET 
                      site_name = ?, 
                      site_manager = ?, 
                      site_address = ?
                      WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sssi", $site_name, $manager, $address, $site_id);
            $stmt->execute();
        }
        
        // CRITICAL: Update all attendance records that have the old site name
        // Update AM site assignments
        $update_am = "UPDATE attendance SET site_assignment_am = ? WHERE site_assignment_am = ?";
        $am_stmt = $conn->prepare($update_am);
        $am_stmt->bind_param("ss", $site_name, $old_site_name);
        $am_stmt->execute();
        $am_stmt->close();
        
        // Update PM site assignments
        $update_pm = "UPDATE attendance SET site_assignment_pm = ? WHERE site_assignment_pm = ?";
        $pm_stmt = $conn->prepare($update_pm);
        $pm_stmt->bind_param("ss", $site_name, $old_site_name);
        $pm_stmt->execute();
        $pm_stmt->close();
        
        // Update Night site assignments
        $update_night = "UPDATE attendance SET site_assignment_night = ? WHERE site_assignment_night = ?";
        $night_stmt = $conn->prepare($update_night);
        $night_stmt->bind_param("ss", $site_name, $old_site_name);
        $night_stmt->execute();
        $night_stmt->close();
        
        $affected_am = $conn->affected_rows;
        
        if (isset($_POST['remove_employees']) && !empty($_POST['remove_employees'])) {
            foreach ($_POST['remove_employees'] as $employee_id) {
                $delete_query = "DELETE FROM site_employee WHERE site_id = ? AND employee_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("ii", $site_id, $employee_id);
                $delete_stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Add info about updated records to notification
        $message = 'Site updated successfully!';
        if ($affected_am > 0) {
            $message .= " Updated $affected_am attendance record(s).";
        }
        $_SESSION['notification'] = ['type' => 'success', 'message' => $message];
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error updating site: ' . $conn->error];
    }
    
    header("Location: site_monitoring.php");
    exit;
}
}

// Delete site
if (isset($_POST['confirm_delete'])) {
    $site_id = (int)$_POST['delete_site_id'];

    $delete_site_employee = "DELETE FROM site_employee WHERE site_id = ?";
    $stmt1 = $conn->prepare($delete_site_employee);
    $stmt1->bind_param("i", $site_id);
    $stmt1->execute();

    $delete_site = "DELETE FROM site_monitoring WHERE id = ?";
    $stmt2 = $conn->prepare($delete_site);
    $stmt2->bind_param("i", $site_id);

    if ($stmt2->execute()) {
        $_SESSION['notification'] = ['type' => 'success', 'message' => 'Site deleted successfully!'];
    } else {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error deleting site: ' . $conn->error];
    }
    header("Location: site_monitoring.php");
    exit;
}

// Get site details for editing
$site_details = null;
$site_employees = [];
$site_others = null;
if (isset($_GET['edit'])) {
    $site_id = (int)$_GET['edit'];
    
    $query = "SELECT * FROM site_monitoring WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $site_details = $result->fetch_assoc();
    
    if ($site_details) {
        $site_employees = getSiteEmployeesFromAttendance($conn, $site_id);
        
        if ($site_details['is_others'] == 1) {
            $site_others = getSiteOthersDetails($conn, $site_id);
        }
    }
}

// Search, sort, and date filter
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'site_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');

// Extract month, year, day for calendar
$month = date('m', strtotime($filter_date));
$year = date('Y', strtotime($filter_date));

$valid_sort_columns = ['site_name', 'site_manager', 'site_address', 'id', 'is_others'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'site_name';
}
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

// Main query
$table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
$has_site_others = $table_check->num_rows > 0;

if ($has_site_others) {
    $query = "SELECT sm.*, so.assignment_type, so.person_group 
              FROM site_monitoring sm 
              LEFT JOIN site_others so ON sm.id = so.site_id";
} else {
    $query = "SELECT sm.*, NULL as assignment_type, NULL as person_group 
              FROM site_monitoring sm";
}

$params = [];
$types = "";

if (!empty($search_query)) {
    if ($has_site_others) {
        $query .= " WHERE sm.site_name LIKE ? OR sm.site_manager LIKE ? OR sm.site_address LIKE ? OR so.assignment_type LIKE ? OR so.person_group LIKE ?";
        $search_term = '%' . $search_query . '%';
        $params = [$search_term, $search_term, $search_term, $search_term, $search_term];
        $types = "sssss";
    } else {
        $query .= " WHERE sm.site_name LIKE ? OR sm.site_manager LIKE ? OR sm.site_address LIKE ?";
        $search_term = '%' . $search_query . '%';
        $params = [$search_term, $search_term, $search_term];
        $types = "sss";
    }
}

$query .= " ORDER BY $sort_by $sort_order";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Get all sites for dropdown
$all_sites = [];
$sites_query = "SELECT sm.id, sm.site_name, so.assignment_type 
                FROM site_monitoring sm 
                LEFT JOIN site_others so ON sm.id = so.site_id 
                ORDER BY sm.site_name";
$sites_result = $conn->query($sites_query);
if ($sites_result) {
    while ($row = $sites_result->fetch_assoc()) {
        $all_sites[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitoring</title>
    <link rel="stylesheet" href="./assets/css/site_monitoring.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================ */
        /* MAIN PAGE STYLES - ORIGINAL DESIGN RETAINED */
        /* ============================================ */
        
        /* Notification Styles */
        .notification-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }
        
        .notification {
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideUp 0.3s ease;
            border-left: 4px solid;
        }
        
        .notification.success { border-left-color: #27ae60; }
        .notification.error { border-left-color: #e74c3c; }
        .notification.warning { border-left-color: #f39c12; }
        .notification.info { border-left-color: #2196F3; }
        
        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Controls Container - ORIGINAL */
        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0;
            padding: 30px 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .search-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .search-container {
            display: flex;
            align-items: center;
            border: 2px solid var(--sidebar-dark-green);
            border-radius: 25px;
            padding: 5px 15px;
            background-color: white;
            flex: 1;
            max-width: 400px;
        }
        
        .search-bar {
            border: none;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
            background-color: transparent;
            padding: 8px;
        }
        
        .search-btn {
            background: none;
            border: none;
            color: var(--sidebar-green);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 5px;
        }
        
        /* Date Filter Styles - ORIGINAL */
        .date-filter-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 15px;
        }
        
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
        
        .main-date-icon {
            position: absolute;
            left: 12px;
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
        }
        
        .main-calendar-wrapper {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            z-index: 2000;
            display: none;
            width: 320px;
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
            padding: 12px;
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
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .main-calendar-nav-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .main-calendar-selectors {
            display: flex;
            gap: 10px;
            padding: 12px;
            background: white;
        }
        
        .main-calendar-select {
            flex: 1;
            padding: 6px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #2c3e50;
            background: white;
            cursor: pointer;
        }
        
        .main-calendar-select:hover {
            border-color: var(--sidebar-green);
        }
        
        .main-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 8px;
            text-align: center;
            font-weight: 600;
            font-size: 0.8rem;
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
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 0.9rem;
            color: #2c3e50;
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
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
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
        }
        
        .main-calendar-action-btn.today {
            background: var(--sidebar-green);
            color: white;
        }
        
        /* Sort Controls - ORIGINAL */
        .sort-container {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f5f5f5;
            padding: 5px 12px;
            border-radius: 25px;
            border: 1px solid #e0e0e0;
        }
        
        .sort-select {
            padding: 6px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 0.85rem;
            background: white;
            cursor: pointer;
        }
        
        .sort-btn {
            padding: 6px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            background: white;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }
        
        .sort-btn:hover {
            background: var(--light-green);
            border-color: var(--accent-green);
            color: var(--accent-green);
        }
        
        /* Add Site Button - ORIGINAL */
        .add-site-btn {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
        }
        
        .add-site-btn i {
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }
        
        .add-site-btn:hover {
            background: linear-gradient(135deg, #1B5E20, #0D3B0F);
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(46, 125, 50, 0.4);
        }
        
        .add-site-btn:hover i {
            transform: scale(1.1);
        }
        
        /* Site Type Selector - ORIGINAL */
        .site-type-selector {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 60px;
        }
        
        .site-type-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .site-type-btn i {
            font-size: 1.1rem;
        }
        
        .site-type-btn.active {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            box-shadow: 0 4px 12px rgba(46, 125, 139, 0.3);
        }
        
        .site-type-btn.others-btn {
            background: #f0f0f0;
            color: #666;
        }
        
        .site-type-btn.others-btn.active {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            box-shadow: 0 4px 12px rgba(46, 125, 139, 0.3);
        }
        
        .site-type-btn.others-btn:hover {
            background: #e0e0e0;
        }
        
        /* Table Styling - ORIGINAL */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .site-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        
        .site-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .site-table thead {
            background: linear-gradient(to right, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
        }
        
        .site-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .site-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
        }
        
        .site-table tbody tr:hover {
            background-color: var(--light-green);
        }
        
        .site-table td {
            padding: 15px;
            vertical-align: top;
        }
        
        .site-info {
            display: grid;
            gap: 6px;
        }
        
        .site-info strong {
            color: var(--sidebar-dark-green);
            font-weight: 600;
            min-width: 80px;
            display: inline-block;
        }
        
        /* Action Buttons - ORIGINAL */
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            color: white;
            transition: all 0.2s ease;
        }
        
        .view-btn { background-color: #2196F3; }
        .view-btn:hover { background-color: #1976D2; transform: translateY(-2px); }
        .edit-btn { background-color: var(--warning-color); }
        .edit-btn:hover { background-color: #e67e22; transform: translateY(-2px); }
        .delete-btn { background-color: var(--danger-color); }
        .delete-btn:hover { background-color: #c0392b; transform: translateY(-2px); }
        
        /* Modal Styles - ORIGINAL for main modals */
        .modal {
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
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 95%;
            margin: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
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
            border-radius: 0 0 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-shrink: 0;
        }
        
        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.8rem;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .form-group label i {
            margin-right: 8px;
            color: var(--sidebar-green);
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            border-color: var(--sidebar-green);
            outline: none;
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
        }
        
        .btn-cancel {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-cancel:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }
        
        .btn-save, .btn-update {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border: none;
            padding: 10px 28px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-save:hover, .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 139, 0.3);
        }
        
        /* Delete Modal - ORIGINAL */
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
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }
        
        /* ============================================ */
        /* VIEW MODAL ONLY - ENHANCED PROFESSIONAL DESIGN */
        /* #75e6da, Green Icons (no background), Larger Icons, White Cards */
        /* ============================================ */
        
        /* View Modal Specific Styles - Override only for view modal */
        #viewEmployeesModal .modal-header {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
        }
        
        #viewEmployeesModal .modal-header h3 {
            color: #2c3e50;
        }
        
        #viewEmployeesModal .modal-header h3 i {
            color: white;
        }
        
        #viewEmployeesModal .modal-close {
            color: #2c3e50;
        }
        
        /* Site Header Card - #75e6da */
        #viewEmployeesModal .site-header-card {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            color: #2c3e50;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3);
        }
        
        #viewEmployeesModal .site-header-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
        }
        
        #viewEmployeesModal .site-header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        
        #viewEmployeesModal .site-title-section h2 {
            margin: 0 0 8px 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #2c3e50;
        }
        
        #viewEmployeesModal .site-title-section h2 i {
            color: white;
        }
        
        #viewEmployeesModal .site-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.25);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            color: #2c3e50;
        }
        
        #viewEmployeesModal .site-meta {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        #viewEmployeesModal .site-meta i {
            margin-right: 5px;
            color: white;
        }
        
        #viewEmployeesModal .date-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 18px;
            border-radius: 12px;
            text-align: center;
            backdrop-filter: blur(10px);
            color: #2c3e50;
        }
        
        #viewEmployeesModal .date-badge .date-day {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        
        #viewEmployeesModal .date-badge .date-month {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        /* Stats Cards - White backgrounds with Green icons (NO BACKGROUND, LARGER ICONS) */
        #viewEmployeesModal .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        #viewEmployeesModal .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e8f5e9;
        }
        
        #viewEmployeesModal .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            border-color: #75e6da;
        }
        
        /* Icon styles - NO BACKGROUND, LARGER SIZE */
        #viewEmployeesModal .stat-icon {
            width: auto;
            height: auto;
            border-radius: 0;
            background: transparent !important;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 2.5rem;
        }
        
        /* All stat icons - Green color, no background, larger */
        #viewEmployeesModal .stat-icon.total { color: #27ae60; background: transparent !important; }
        #viewEmployeesModal .stat-icon.present { color: #27ae60; background: transparent !important; }
        #viewEmployeesModal .stat-icon.absent { color: #27ae60; background: transparent !important; }
        #viewEmployeesModal .stat-icon.hours { color: #27ae60; background: transparent !important; }
        
        #viewEmployeesModal .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        #viewEmployeesModal .stat-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Employee Table Container - White background */
        #viewEmployeesModal .employees-table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            margin-top: 10px;
        }
        
        #viewEmployeesModal .employees-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        /* Table Header - #75e6da */
        #viewEmployeesModal .employees-table th {
            background: linear-gradient(135deg, #75e6da, #5fd9c9);
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #4bc5b5;
            font-size: 0.85rem;
        }
        
        #viewEmployeesModal .employees-table th i {
            margin-right: 6px;
            color: white;
        }
        
        #viewEmployeesModal .employees-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            background: white;
        }
        
        #viewEmployeesModal .employees-table tr:hover td {
            background-color: #e8f5e9;
        }
        
        #viewEmployeesModal .employee-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        #viewEmployeesModal .employee-position {
            font-size: 0.7rem;
            color: #95a5a6;
        }
        
        #viewEmployeesModal .employee-id {
            font-size: 0.7rem;
            color: #bdc3c7;
            font-family: monospace;
        }
        
        /* Status Badges */
        #viewEmployeesModal .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        #viewEmployeesModal .status-present {
            background-color: #e8f5e9;
            color: #27ae60;
            border: 1px solid #c8e6c9;
        }
        
        #viewEmployeesModal .status-absent {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        
        #viewEmployeesModal .status-norecord {
            background-color: #f5f5f5;
            color: #757575;
            border: 1px solid #e0e0e0;
        }
        
        #viewEmployeesModal .time-cell {
            font-family: 'Courier New', monospace;
            font-weight: 500;
            color: #2c3e50;
            font-size: 0.8rem;
        }
        
        #viewEmployeesModal .hours-cell {
            font-weight: 700;
            color: #27ae60;
            font-size: 0.9rem;
        }
        
        #viewEmployeesModal .remarks-cell {
            max-width: 180px;
            font-size: 0.75rem;
            color: #7f8c8d;
        }
        
        /* Info Note - Light Green background */
        #viewEmployeesModal .info-note {
            background: #e8f5e9;
            border-radius: 12px;
            padding: 14px 18px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: #2e7d32;
            border-left: 4px solid #75e6da;
        }
        
        #viewEmployeesModal .info-note i {
            font-size: 1.2rem;
            color: #75e6da;
        }
        
        /* Empty State for View Modal */
        #viewEmployeesModal .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        
        #viewEmployeesModal .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 15px;
            color: #75e6da;
        }
        
        #viewEmployeesModal .empty-state p {
            margin: 5px 0;
        }
        
        /* Spinner for View Modal */
        #viewEmployeesModal .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #75e6da;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .controls-container {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
                padding: 15px;
            }
            
            .search-container {
                max-width: 100%;
            }
            
            .add-site-btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            .date-filter-container {
                margin-left: 0;
            }
            
            #viewEmployeesModal .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            #viewEmployeesModal .employees-table {
                font-size: 0.7rem;
            }
            
            #viewEmployeesModal .employees-table th,
            #viewEmployeesModal .employees-table td {
                padding: 8px 6px;
            }
            
            #viewEmployeesModal .site-header-content {
                flex-direction: column;
            }
        }
        
        /* Site info item styles - ORIGINAL */
        .site-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        
        .site-info-item i {
            width: 24px;
            color: var(--sidebar-green);
            font-size: 1rem;
        }
        
        .site-info-item strong {
            color: #2c3e50;
            min-width: 70px;
        }
    </style>
</head>
<body>
    <?php include_once("./includes/header.php"); ?>
    
    <div class="notification-container" id="notificationContainer"></div>
    
    <?php
    if (isset($_SESSION['notification'])) {
        $notification = $_SESSION['notification'];
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                showNotification('" . addslashes($notification['message']) . "', '" . $notification['type'] . "');
            });
        </script>";
        unset($_SESSION['notification']);
    }
    ?>
     
    <main class="content">
        <div class="content-wrapper">
            <!-- Controls Container -->
            <div class="controls-container">
                <div class="search-section">
                    <div class="search-container">
                        <form method="GET" action="site_monitoring.php" style="display: flex; align-items: center; width: 100%;">
                            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
                            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                            <input type="hidden" name="filter_date" id="filterDateHidden" value="<?php echo htmlspecialchars($filter_date); ?>">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search Site..." class="search-bar">
                            <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                    
                    <!-- Date Filter -->
                    <div class="date-filter-container">
                        <span style="font-size: 0.9rem; color: #666;">
                            <i class="fas fa-calendar-alt"></i> As of Date:
                        </span>
                        <div class="main-date-picker-wrapper">
                            <div class="main-date-input-group">
                                <i class="fas fa-calendar-alt main-date-icon"></i>
                                <input type="text" 
                                       id="filterDateField" 
                                       class="main-date-field" 
                                       value="<?php echo date('m/d/Y', strtotime($filter_date)); ?>"
                                       placeholder="MM/DD/YYYY"
                                       autocomplete="off"
                                       readonly
                                       onclick="toggleMainCalendar()">
                                <button type="button" class="main-calendar-dropdown-btn" onclick="toggleMainCalendar()">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <div class="main-calendar-wrapper" id="mainCalendarWrapper">
                                <div class="main-calendar-box">
                                    <div class="main-calendar-header">
                                        <div class="main-calendar-month-year" id="mainCalendarMonthYear">
                                            <?php echo date('F Y', strtotime($filter_date)); ?>
                                        </div>
                                        <div class="main-calendar-nav">
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMonth(-1)">‹</button>
                                            <button type="button" class="main-calendar-nav-btn" onclick="navigateMonth(1)">›</button>
                                        </div>
                                    </div>
                                    
                                    <div class="main-calendar-selectors">
                                        <select id="mainMonthSelect" class="main-calendar-select" onchange="changeMonthYear()">
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
                                        
                                        <select id="mainYearSelect" class="main-calendar-select" onchange="changeMonthYear()">
                                            <?php for($y = date('Y') - 10; $y <= date('Y') + 10; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="main-calendar-weekdays">
                                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                                    </div>
                                    
                                    <div class="main-calendar-days-grid" id="mainCalendarDaysGrid"></div>
                                    
                                    <div class="main-calendar-footer">
                                        <button type="button" class="main-calendar-action-btn clear" onclick="clearDate()">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <button type="button" class="main-calendar-action-btn today" onclick="setToday()">
                                            <i class="fas fa-calendar-check"></i> Today
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sort Controls -->
                    <div class="sort-container">
                        <form method="GET" action="site_monitoring.php" id="sortForm" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                            <input type="hidden" name="filter_date" id="sortFilterDate" value="<?php echo htmlspecialchars($filter_date); ?>">
                            <select name="sort_by" class="sort-select" onchange="document.getElementById('sortForm').submit();">
                                <option value="site_name" <?php echo $sort_by == 'site_name' ? 'selected' : ''; ?>>Name</option>
                                <option value="site_manager" <?php echo $sort_by == 'site_manager' ? 'selected' : ''; ?>>Manager</option>
                                <option value="site_address" <?php echo $sort_by == 'site_address' ? 'selected' : ''; ?>>Address</option>
                                <option value="is_others" <?php echo $sort_by == 'is_others' ? 'selected' : ''; ?>>Type</option>
                            </select>
                            <button type="submit" name="sort_order" value="<?php echo $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>" class="sort-btn">
                                <i class="fas fa-sort-amount-<?php echo $sort_order == 'ASC' ? 'down' : 'up'; ?>"></i>
                                <?php echo $sort_order == 'ASC' ? 'Asc' : 'Desc'; ?>
                            </button>
                        </form>
                    </div>
                </div>
                
                <button class="add-site-btn" onclick="showAddSiteModal()">
                    <i class="fas fa-plus-circle"></i>
                    Add Site
                </button>
            </div>

            <!-- Site Table -->
            <div class="table-responsive">
                <div class="site-table-container">
                    <table class="site-table">
                        <thead>
                            <tr><th>Site / Assignment Information</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $employee_count = getSiteEmployeeCountByDate($conn, $row['id'], $filter_date);
                                $is_others = $row['is_others'] == 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="site-info">
                                        <?php if ($is_others && isset($row['assignment_type']) && $row['assignment_type'] !== null): ?>
                                            <div><strong>Assignment:</strong> <span class="assignment-type"><?php echo htmlspecialchars($row['assignment_type']); ?></span> <span class="assignment-badge"><i class="fas fa-tasks"></i> Others</span></div>
                                            <div><strong>Person/Group:</strong> <?php echo htmlspecialchars($row['person_group']); ?></div>
                                        <?php else: ?>
                                            <div><strong>Site Name:</strong> <?php echo htmlspecialchars($row['site_name']); ?></div>
                                        <?php endif; ?>
                                        <div><strong>Manager:</strong> <?php echo htmlspecialchars($row['site_manager']); ?></div>
                                        <div><strong>Address:</strong> <?php echo htmlspecialchars($row['site_address']); ?></div>
                                        <div><strong>Employees (as of <?php echo date('M d, Y', strtotime($filter_date)); ?>):</strong> <span style="color: var(--info-color); font-weight: 600;"><?php echo $employee_count; ?> assigned</span></div>
                                    </div>
                                 </div>
                                <td>
                                    <div class="action-buttons">
                                        <button class='action-btn view-btn' 
                                                onclick='showViewEmployeesModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars(addslashes($is_others && isset($row['assignment_type']) ? $row['assignment_type'] : $row['site_name'])); ?>", "<?php echo htmlspecialchars(addslashes($row['site_manager'])); ?>", "<?php echo htmlspecialchars(addslashes($row['site_address'])); ?>", "<?php echo $filter_date; ?>")' 
                                                title="View Employees">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <a href='site_monitoring.php?edit=<?php echo $row['id']; ?>' 
                                           class='action-btn edit-btn' 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <button type="button" 
                                                class='action-btn delete-btn' 
                                                title="Delete"
                                                onclick='showDeleteModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars(addslashes($is_others && isset($row['assignment_type']) ? $row['assignment_type'] : $row['site_name'])); ?>")'>
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                 </div>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="2"><div class="empty-state"><i class="fas fa-map-marked-alt"></i><p>No sites or assignments found</p></div></div> </div></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <?php include_once("./includes/footer.php"); ?>
    <?php include_once("./modal/logout-modal.php"); ?>

    <!-- Add Site Modal - ORIGINAL DESIGN -->
    <div id="addSiteModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-map-marked-alt"></i> <span id="modalTitle">Add New Site</span></h3>
                <button class="modal-close" onclick="closeAddSiteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="site-type-selector">
                    <button type="button" class="site-type-btn active" id="regularSiteBtn" onclick="switchSiteType('regular')">
                        <i class="fas fa-building"></i> Regular Site
                    </button>
                    <button type="button" class="site-type-btn others-btn" id="othersBtn" onclick="switchSiteType('others')">
                        <i class="fas fa-tasks"></i> Others (Assignment)
                    </button>
                </div>
                <form method="POST" action="site_monitoring.php" id="regularSiteForm">
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Site Name *</label>
                        <input type="text" name="site_name" placeholder="Enter site name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Manager *</label>
                        <input type="text" name="site_manager" placeholder="Enter manager name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address *</label>
                        <input type="text" name="site_address" placeholder="Enter site address" required>
                    </div>
                    <div class="modal-footer" style="padding: 20px 0 0 0;">
                        <button type="button" class="btn-cancel" onclick="closeAddSiteModal()">Cancel</button>
                        <button type="submit" name="save_site" class="btn-save">Save Site</button>
                    </div>
                </form>
                <form method="POST" action="site_monitoring.php" id="othersForm" style="display: none;">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Assignment Type *</label>
                        <select name="assignment_type" required>
                            <option value="">-- Select Assignment Type --</option>
                            <option value="Meeting">📅 Meeting</option>
                            <option value="Project">📁 Project</option>
                            <option value="Activities">🎯 Activities</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-users"></i> Person or Group *</label>
                        <input type="text" name="person_group" placeholder="Enter person or group name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Manager/Officer *</label>
                        <input type="text" name="assignment_manager" placeholder="Enter manager name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-location-dot"></i> Address/Location *</label>
                        <input type="text" name="assignment_location" placeholder="Enter location" required>
                    </div>
                    <div class="modal-footer" style="padding: 20px 0 0 0;">
                        <button type="button" class="btn-cancel" onclick="closeAddSiteModal()">Cancel</button>
                        <button type="submit" name="save_others" class="btn-save">Create Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Site Modal - ORIGINAL DESIGN -->
    <?php if ($site_details): ?>
    <div id="editSiteModal" class="modal" style="display: flex;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Site</h3>
                <button class="modal-close" onclick="closeEditSiteModal()">&times;</button>
            </div>
            <form method="POST" action="site_monitoring.php">
                <input type="hidden" name="site_id" value="<?php echo $site_details['id']; ?>">
                <input type="hidden" name="is_others" value="<?php echo $site_details['is_others']; ?>">
                <div class="modal-body">
                    <?php if ($site_details['is_others'] == 1 && $site_others): ?>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Assignment Type</label>
                            <select name="edit_assignment_type" required>
                                <option value="Meeting" <?php echo $site_others['assignment_type'] == 'Meeting' ? 'selected' : ''; ?>>Meeting</option>
                                <option value="Project" <?php echo $site_others['assignment_type'] == 'Project' ? 'selected' : ''; ?>>Project</option>
                                <option value="Activities" <?php echo $site_others['assignment_type'] == 'Activities' ? 'selected' : ''; ?>>Activities</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Person/Group</label>
                            <input type="text" name="edit_person_group" value="<?php echo htmlspecialchars($site_others['person_group']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Manager</label>
                            <input type="text" name="edit_site_manager" value="<?php echo htmlspecialchars($site_details['site_manager']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-dot"></i> Address</label>
                            <input type="text" name="edit_site_address" value="<?php echo htmlspecialchars($site_details['site_address']); ?>" required>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Site Name</label>
                            <input type="text" name="edit_site_name" value="<?php echo htmlspecialchars($site_details['site_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Manager</label>
                            <input type="text" name="edit_site_manager" value="<?php echo htmlspecialchars($site_details['site_manager']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Address</label>
                            <input type="text" name="edit_site_address" value="<?php echo htmlspecialchars($site_details['site_address']); ?>" required>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeEditSiteModal()">Cancel</button>
                    <button type="submit" name="update_site" class="btn-update">Update</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Confirmation Modal - ORIGINAL DESIGN -->
    <div id="deleteConfirmModal" class="modal delete-modal">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                <h3><i class="fas fa-trash"></i> Confirm Delete</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="delete-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <h4>Are you sure?</h4>
                <p>You are about to delete the site:</p>
                <p><strong id="deleteSiteName"></strong></p>
                <div class="delete-warning"><i class="fas fa-info-circle"></i> This will also unassign all employees from this site. This action cannot be undone.</div>
                <form method="POST" action="site_monitoring.php" id="deleteForm">
                    <input type="hidden" name="delete_site_id" id="deleteSiteId" value="">
                    <input type="hidden" name="confirm_delete" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn-danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <!-- ENHANCED View Employees Modal - ONLY THIS MODAL HAS THE NEW DESIGN -->
    <div id="viewEmployeesModal" class="modal">
        <div class="modal-content" style="max-width: 1300px;">
            <div class="modal-header">
                <div>
                    <h3><i class="fas fa-users"></i> Employee Tracking - <span id="viewSiteName"></span></h3>
                </div>
                <button class="modal-close" onclick="closeViewEmployeesModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewEmployeesModalBody">
                <div class="spinner"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeViewEmployeesModal()">Close</button>
            </div>
        </div>
    </div>

  <script>
    // ============================================
    // CALENDAR VARIABLES
    // ============================================
    let mainCurrentDate = new Date('<?php echo $filter_date; ?>');
    let mainSelectedDate = '<?php echo $filter_date; ?>';
    
    // ============================================
    // CALENDAR FUNCTIONS
    // ============================================
    function toggleMainCalendar() {
        const calendar = document.getElementById('mainCalendarWrapper');
        if (calendar) {
            calendar.style.display = calendar.style.display === 'block' ? 'none' : 'block';
            if (calendar.style.display === 'block') generateCalendarDays();
        }
    }
    
    function generateCalendarDays() {
        const year = mainCurrentDate.getFullYear();
        const month = mainCurrentDate.getMonth();
        const daysGrid = document.getElementById('mainCalendarDaysGrid');
        const monthYearDisplay = document.getElementById('mainCalendarMonthYear');
        
        if (!daysGrid || !monthYearDisplay) return;
        
        monthYearDisplay.textContent = mainCurrentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        
        // Update selectors
        document.getElementById('mainMonthSelect').value = month;
        document.getElementById('mainYearSelect').value = year;
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        let html = '';
        
        // Previous month days
        const prevMonthDays = new Date(year, month, 0).getDate();
        for (let i = firstDay - 1; i >= 0; i--) {
            const day = prevMonthDays - i;
            const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            html += `<div class="main-calendar-day other-month" onclick="selectDate('${dateStr}')">${day}</div>`;
        }
        
        // Current month days
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            let classes = 'main-calendar-day';
            if (today.getFullYear() === year && today.getMonth() === month && today.getDate() === day) classes += ' today';
            if (dateStr === mainSelectedDate) classes += ' selected';
            if (new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6) classes += ' weekend';
            html += `<div class="${classes}" onclick="selectDate('${dateStr}')">${day}</div>`;
        }
        
        // Next month days
        const totalCells = 42;
        const cellsUsed = firstDay + daysInMonth;
        for (let day = 1; day <= totalCells - cellsUsed; day++) {
            const nextMonth = month + 1;
            const nextYear = nextMonth > 11 ? year + 1 : year;
            const actualNextMonth = nextMonth > 11 ? 0 : nextMonth;
            const dateStr = `${nextYear}-${String(actualNextMonth + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            html += `<div class="main-calendar-day other-month" onclick="selectDate('${dateStr}')">${day}</div>`;
        }
        daysGrid.innerHTML = html;
    }
    
    function navigateMonth(direction) {
        mainCurrentDate.setMonth(mainCurrentDate.getMonth() + direction);
        generateCalendarDays();
    }
    
    function changeMonthYear() {
        const monthSelect = document.getElementById('mainMonthSelect');
        const yearSelect = document.getElementById('mainYearSelect');
        if (monthSelect && yearSelect) {
            mainCurrentDate = new Date(parseInt(yearSelect.value), parseInt(monthSelect.value), 1);
            generateCalendarDays();
        }
    }
    
    function selectDate(dateStr) {
        const date = new Date(dateStr);
        document.getElementById('filterDateField').value = date.toLocaleDateString('en-US');
        document.getElementById('filterDateHidden').value = dateStr;
        document.getElementById('sortFilterDate').value = dateStr;
        mainSelectedDate = dateStr;
        mainCurrentDate = new Date(dateStr);
        generateCalendarDays();
        document.getElementById('mainCalendarWrapper').style.display = 'none';
        document.getElementById('sortForm').submit();
    }
    
    function clearDate() { 
        const today = new Date();
        const dateStr = today.toISOString().split('T')[0];
        selectDate(dateStr);
    }
    
    function setToday() { 
        const today = new Date();
        const dateStr = today.toISOString().split('T')[0];
        selectDate(dateStr);
    }
    
    // ============================================
    // NOTIFICATION FUNCTIONS
    // ============================================
    function showNotification(message, type) {
        const container = document.getElementById('notificationContainer');
        if (!container) return;
        const notification = document.createElement('div');
        notification.className = 'notification ' + type;
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
        notification.innerHTML = `<i class="fas ${icon}"></i><div class="notification-content">${message}</div><button class="notification-close" onclick="this.parentElement.remove()">&times;</button>`;
        container.appendChild(notification);
        setTimeout(() => notification.remove(), 5000);
    }
    
    // ============================================
    // DELETE MODAL FUNCTIONS
    // ============================================
    let currentDeleteSiteId = null;
    
    function showDeleteModal(siteId, siteName) {
        currentDeleteSiteId = siteId;
        document.getElementById('deleteSiteName').textContent = siteName;
        document.getElementById('deleteSiteId').value = siteId;
        document.getElementById('deleteConfirmModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function closeDeleteModal() {
        document.getElementById('deleteConfirmModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    function confirmDelete() {
        if (currentDeleteSiteId) document.getElementById('deleteForm').submit();
    }
    
    // ============================================
    // SITE TYPE SWITCHER
    // ============================================
    function switchSiteType(type) {
        const regularBtn = document.getElementById('regularSiteBtn');
        const othersBtn = document.getElementById('othersBtn');
        const regularForm = document.getElementById('regularSiteForm');
        const othersForm = document.getElementById('othersForm');
        const modalTitle = document.getElementById('modalTitle');
        
        if (type === 'regular') {
            regularBtn.classList.add('active');
            othersBtn.classList.remove('active');
            regularForm.style.display = 'block';
            othersForm.style.display = 'none';
            modalTitle.textContent = 'Add New Site';
        } else {
            othersBtn.classList.add('active');
            regularBtn.classList.remove('active');
            regularForm.style.display = 'none';
            othersForm.style.display = 'block';
            modalTitle.textContent = 'Create New Assignment';
        }
    }
    
    // ============================================
    // ADD/EDIT SITE MODAL FUNCTIONS
    // ============================================
    function showAddSiteModal() {
        document.getElementById('addSiteModal').style.display = 'flex';
        document.body.classList.add('modal-open');
        switchSiteType('regular');
    }
    
    function closeAddSiteModal() {
        document.getElementById('addSiteModal').style.display = 'none';
        document.body.classList.remove('modal-open');
        document.getElementById('regularSiteForm').reset();
        document.getElementById('othersForm').reset();
    }
    
    function closeEditSiteModal() {
        window.location.href = 'site_monitoring.php';
    }
    
    // ============================================
    // ENHANCED VIEW EMPLOYEES MODAL - Professional Design
    // ============================================
    async function showViewEmployeesModal(siteId, siteName, siteManager, siteAddress, filterDate) {
        const modal = document.getElementById('viewEmployeesModal');
        const modalBody = document.getElementById('viewEmployeesModalBody');
        const siteNameSpan = document.getElementById('viewSiteName');
        
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        siteNameSpan.textContent = siteName;
        modalBody.innerHTML = '<div class="spinner"></div>';
        
        try {
            const response = await fetch(`get_attendance_by_site.php?site_id=${siteId}&date=${filterDate}`);
            const data = await response.json();
            
            const formattedDate = new Date(filterDate);
            const dateFormatted = formattedDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const dateDay = formattedDate.getDate();
            const dateMonth = formattedDate.toLocaleDateString('en-US', { month: 'short' });
            
            if (data.success && data.employees) {
                const employees = data.employees;
                
                if (employees.length === 0) {
                    modalBody.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h4>No Employees Found</h4>
                            <p>No employees were assigned to <strong>${escapeHtml(siteName)}</strong> on ${dateFormatted}.</p>
                            <p style="font-size: 0.85rem;">Employees appear here when they are assigned to this site in their attendance record.</p>
                        </div>
                    `;
                    return;
                }
                
                let html = `
                    <!-- Professional Header Card -->
                    <div class="site-header-card">
                        <div class="site-header-content">
                            <div class="site-title-section">
                                <h2>
                                    <i class="fas fa-building"></i>
                                    ${escapeHtml(siteName)}
                                    <span class="site-badge"><i class="fas fa-users"></i> Active Site</span>
                                </h2>
                                <div class="site-meta">
                                    <span><i class="fas fa-user-tie"></i> Manager: ${escapeHtml(siteManager)}</span>
                                    <span><i class="fas fa-location-dot"></i> ${escapeHtml(siteAddress)}</span>
                                </div>
                            </div>
                            <div class="date-badge">
                                <div class="date-day">${dateDay}</div>
                                <div class="date-month">${dateMonth}</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Stats Cards - White background, Green icons (no background), Larger icons -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon total"><i class="fas fa-users"></i></div>
                            <div class="stat-value">${data.summary.total}</div>
                            <div class="stat-label">Total Employees</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon present"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-value">${data.summary.present}</div>
                            <div class="stat-label">Present Today</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon absent"><i class="fas fa-times-circle"></i></div>
                            <div class="stat-value">${data.summary.absent}</div>
                            <div class="stat-label">Absent Today</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon hours"><i class="fas fa-clock"></i></div>
                            <div class="stat-value">${data.summary.total_hours}</div>
                            <div class="stat-label">Total Hours</div>
                        </div>
                    </div>
                    
                    <!-- Employees Table -->
                    <div class="employees-table-container">
                        <table class="employees-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user"></i> Employee</th>
                                    <th><i class="fas fa-sun"></i> AM Status</th>
                                    <th><i class="fas fa-clock"></i> AM Time</th>
                                    <th><i class="fas fa-moon"></i> PM Status</th>
                                    <th><i class="fas fa-clock"></i> PM Time</th>
                                    <th><i class="fas fa-star"></i> Night Status</th>
                                    <th><i class="fas fa-clock"></i> Night Time</th>
                                    <th><i class="fas fa-hourglass-half"></i> Total Hours</th>
                                    <th><i class="fas fa-comment"></i> Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                employees.forEach(emp => {
                    const getStatusBadge = (status) => {
                        if (status === 'Present') return '<span class="status-badge status-present"><i class="fas fa-check-circle"></i> Present</span>';
                        if (status === 'Absent') return '<span class="status-badge status-absent"><i class="fas fa-times-circle"></i> Absent</span>';
                        return '<span class="status-badge status-norecord"><i class="fas fa-minus-circle"></i> No Record</span>';
                    };
                    
                    const formatTime = (time) => {
                        if (!time || time === '-') return '<span style="color:#bdc3c7;">--:--</span>';
                        return `<span class="time-cell">${time}</span>`;
                    };
                    
                    html += `
                        <tr>
                            <td>
                                <div class="employee-name">${escapeHtml(emp.employee_name)}</div>
                                <div class="employee-position">${escapeHtml(emp.position)}</div>
                                <div class="employee-id">ID: ${emp.employee_id}</div>
                            </div>
                            <td>${getStatusBadge(emp.status)}</div>
                            <td>${formatTime(emp.time_in_am)}<br>→<br>${formatTime(emp.time_out_am)}</div>
                            <td>${getStatusBadge(emp.pm_status)}</div>
                            <td>${formatTime(emp.time_in_pm)}<br>→<br>${formatTime(emp.time_out_pm)}</div>
                            <td>${getStatusBadge(emp.night_status)}</div>
                            <td>${formatTime(emp.time_in_night)}<br>→<br>${formatTime(emp.time_out_night)}</div>
                            <td class="hours-cell">${emp.total_hours} hrs</div>
                            <td class="remarks-cell">${escapeHtml(emp.remarks)}</div>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="info-note">
                        <i class="fas fa-info-circle"></i>
                        <span>Employees shown above are those who were assigned to <strong>${escapeHtml(siteName)}</strong> in their attendance records for ${dateFormatted}.</span>
                    </div>
                `;
                
                modalBody.innerHTML = html;
            } else {
                modalBody.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>${data.message || 'Error loading employees'}</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error:', error);
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error loading employees. Please try again.</p>
                </div>
            `;
        }
    }
    
    function closeViewEmployeesModal() {
        document.getElementById('viewEmployeesModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ============================================
    // EVENT LISTENERS & INITIALIZATION
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        generateCalendarDays();
    });
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const addModal = document.getElementById('addSiteModal');
        const editModal = document.getElementById('editSiteModal');
        const viewModal = document.getElementById('viewEmployeesModal');
        const deleteModal = document.getElementById('deleteConfirmModal');
        
        if (event.target === addModal) closeAddSiteModal();
        if (event.target === editModal) closeEditSiteModal();
        if (event.target === viewModal) closeViewEmployeesModal();
        if (event.target === deleteModal) closeDeleteModal();
    }
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeAddSiteModal();
            closeViewEmployeesModal();
            closeDeleteModal();
        }
    });
    
    // Close calendar when clicking outside
    document.addEventListener('click', function(e) {
        const container = document.querySelector('.date-filter-container');
        const calendar = document.getElementById('mainCalendarWrapper');
        if (container && calendar && !container.contains(e.target)) {
            calendar.style.display = 'none';
        }
    });
</script>

</body>
</html>