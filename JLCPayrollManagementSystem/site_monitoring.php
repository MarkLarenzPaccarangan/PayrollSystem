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

// Function to get employee count for dashboard
function getSiteEmployeeCount($conn, $site_id) {
    $query = "SELECT COUNT(*) as count FROM site_employee WHERE site_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    return $data ? $data['count'] : 0;
}

// Function to get others/assignment details
function getSiteOthersDetails($conn, $site_id) {
    // Check if site_others table exists first
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

        // Check if site_others table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
        if ($table_check->num_rows == 0) {
            // Create site_others table if it doesn't exist
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

        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert into site_monitoring
            $site_name = $assignment_type; // Use assignment type as site name
            $query = "INSERT INTO site_monitoring (site_name, site_manager, site_address, is_others) VALUES (?, ?, ?, 1)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $site_name, $manager, $location);
            $stmt->execute();
            
            $site_id = $conn->insert_id;
            
            // Insert into site_others
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
        
        // Check if this is an others/assignment
        $is_others = isset($_POST['is_others']) ? $_POST['is_others'] : 0;
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update site_monitoring
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
                
                // Check if site_others table exists
                $table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
                if ($table_check->num_rows > 0) {
                    // Check if record exists
                    $check_query = "SELECT id FROM site_others WHERE site_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("i", $site_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        // Update existing record
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
            
            // Remove employees if selected
            if (isset($_POST['remove_employees']) && !empty($_POST['remove_employees'])) {
                foreach ($_POST['remove_employees'] as $employee_id) {
                    $delete_query = "DELETE FROM site_employee WHERE site_id = ? AND employee_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $site_id, $employee_id);
                    $delete_stmt->execute();
                }
            }
            
            $conn->commit();
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Site updated successfully!'];
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error updating site: ' . $conn->error];
        }
        
        header("Location: site_monitoring.php");
        exit;
    }
    
    // Add employees to site
    if (isset($_POST['add_employees_to_site'])) {
        $site_id = $_POST['add_site_id'];
        
        if (isset($_POST['selected_employees']) && !empty($_POST['selected_employees'])) {
            $success_count = 0;
            $already_assigned = [];
            
            foreach ($_POST['selected_employees'] as $employee_id) {
                $check_any_site_query = "SELECT se.*, s.site_name 
                                        FROM site_employee se 
                                        LEFT JOIN site_monitoring s ON se.site_id = s.id 
                                        WHERE se.employee_id = ?";
                $check_any_site_stmt = $conn->prepare($check_any_site_query);
                $check_any_site_stmt->bind_param("i", $employee_id);
                $check_any_site_stmt->execute();
                $check_any_site_result = $check_any_site_stmt->get_result();
                
                if ($check_any_site_result->num_rows > 0) {
                    $already_assigned[] = $employee_id;
                } else {
                    $insert_query = "INSERT INTO site_employee (site_id, employee_id, assigned_date) VALUES (?, ?, NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("ii", $site_id, $employee_id);
                    
                    if ($insert_stmt->execute()) {
                        $success_count++;
                    }
                }
            }
            
            if ($success_count > 0) {
                $_SESSION['notification'] = ['type' => 'success', 'message' => "Successfully added " . $success_count . " employee(s) to site!"];
            }
            if (!empty($already_assigned)) {
                $_SESSION['notification'] = ['type' => 'warning', 'message' => count($already_assigned) . ' employee(s) were already assigned to other sites.'];
            }
            if ($success_count == 0 && empty($already_assigned)) {
                $_SESSION['notification'] = ['type' => 'error', 'message' => 'No employees were added. Please try again.'];
            }
        } else {
            $_SESSION['notification'] = ['type' => 'warning', 'message' => 'Please select at least one employee to add.'];
        }
        header("Location: site_monitoring.php");
        exit;
    }
}

// Delete site
if (isset($_GET['delete'])) {
    $site_id = (int)$_GET['delete'];

    // site_others will be deleted automatically via CASCADE foreign key
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

// Function to get site employees
function getSiteEmployees($conn, $site_id) {
    $employees = [];
    
    $columns = ['e.id', 'e.first_name', 'e.last_name', 'e.position'];
    
    $email_check = $conn->query("SHOW COLUMNS FROM employees LIKE 'email'");
    if ($email_check && $email_check->num_rows > 0) {
        $columns[] = 'e.email';
    }
    
    $columns_str = implode(', ', $columns);
    $query = "SELECT $columns_str 
              FROM employees e 
              INNER JOIN site_employee se ON e.id = se.employee_id 
              WHERE se.site_id = ?
              ORDER BY e.first_name, e.last_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $site_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    return $employees;
}

// Function to get available employees
function getAvailableEmployees($conn, $site_id) {
    $available_employees = [];
    
    $query = "SELECT e.id, e.first_name, e.last_name, e.position 
              FROM employees e 
              WHERE e.id NOT IN (
                  SELECT COALESCE(employee_id, 0) 
                  FROM site_employee 
                  WHERE employee_id IS NOT NULL
              )
              AND e.status = 'active'
              AND e.is_active = 1
              AND (e.is_archived = 0 OR e.is_archived IS NULL)
              ORDER BY e.first_name, e.last_name";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $available_employees[] = $row;
    }
    
    return $available_employees;
}

// Get site details for editing
$site_details = null;
$site_employees = [];
$available_employees = [];
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
        $site_employees = getSiteEmployees($conn, $site_id);
        $available_employees = getAvailableEmployees($conn, $site_id);
        
        // Get others details if this is an others site
        if ($site_details['is_others'] == 1) {
            $site_others = getSiteOthersDetails($conn, $site_id);
        }
    }
}

// Search and sort functionality
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'site_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

$valid_sort_columns = ['site_name', 'site_manager', 'site_address', 'id', 'is_others'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'site_name';
}
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

// FIXED: Check if site_others table exists and join properly using site_id
$table_check = $conn->query("SHOW TABLES LIKE 'site_others'");
$has_site_others = $table_check->num_rows > 0;

if ($has_site_others) {
    // Use LEFT JOIN on site_id instead of others_id
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

// Get all sites for dropdown - FIXED: Use site_id for joining
$all_sites = [];

// Check again for site_others table
$table_check2 = $conn->query("SHOW TABLES LIKE 'site_others'");
$has_site_others2 = $table_check2->num_rows > 0;

if ($has_site_others2) {
    $sites_query = "SELECT sm.id, sm.site_name, so.assignment_type 
                    FROM site_monitoring sm 
                    LEFT JOIN site_others so ON sm.id = so.site_id 
                    ORDER BY sm.site_name";
} else {
    $sites_query = "SELECT sm.id, sm.site_name, NULL as assignment_type 
                    FROM site_monitoring sm 
                    ORDER BY sm.site_name";
}

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
        /* Notification Styles - Bottom Position */
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
        
        .notification.success {
            border-left-color: #27ae60;
        }
        
        .notification.error {
            border-left-color: #e74c3c;
        }
        
        .notification.warning {
            border-left-color: #f39c12;
        }
        
        .notification.info {
            border-left-color: #2196F3;
        }
        
        .notification i {
            font-size: 1.2rem;
        }
        
        .notification.success i {
            color: #27ae60;
        }
        
        .notification.error i {
            color: #e74c3c;
        }
        
        .notification.warning i {
            color: #f39c12;
        }
        
        .notification.info i {
            color: #2196F3;
        }
        
        .notification-content {
            flex: 1;
            font-size: 0.95rem;
            color: #333;
        }
        
        .notification-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            transition: color 0.3s;
        }
        
        .notification-close:hover {
            color: #333;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateY(0);
                opacity: 1;
            }
            to {
                transform: translateY(100%);
                opacity: 0;
            }
        }
        
        /* Sort Controls */
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
            transition: var(--transition);
            flex: 1;
            max-width: 400px;
        }
        
        .search-container:focus-within {
            border-color: var(--accent-green);
            box-shadow: 0 0 0 3px rgba(41, 148, 82, 0.1);
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
            flex-shrink: 0;
        }
        
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
            transition: all 0.3s;
            outline: none;
        }
        
        .sort-select:focus {
            border-color: var(--accent-green);
        }
        
        .sort-btn {
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
        
        .sort-btn:hover {
            background: var(--light-green);
            border-color: var(--accent-green);
            color: var(--accent-green);
        }
        
        .add-site-btn {
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
        }
        
        .add-site-btn:hover {
            background-color: var(--sidebar-dark-green);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0,0,0,0.15);
        }
        
        /* Site Type Selector */
        .site-type-selector {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 10px;
            background: #f8fff8;
            border-radius: 12px;
        }
        
        .site-type-btn {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            color: #666;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .site-type-btn.active {
            background: linear-gradient(135deg, var(--sidebar-green) 0%, var(--sidebar-dark-green) 100%);
            color: white;
            border-color: transparent;
        }
        
        .site-type-btn i {
            font-size: 1.1rem;
        }
        
        .site-type-btn.others-btn {
            background: #f0f0f0;
            border-color: #d0d0d0;
        }
        
        .site-type-btn.others-btn.active {
            background: linear-gradient(135deg, var(--accent-green) 0%, var(--sidebar-dark-green) 100%);
            color: white;
        }
        
        /* Others/Assignment Form */
        .others-form {
            display: grid;
            gap: 20px;
            padding: 10px 0;
        }
        
        .others-section {
            background: #f8fff8;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid var(--accent-green);
        }
        
        .others-section h4 {
            color: var(--accent-green);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }
        
        /* Assignment Badge */
        .assignment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .assignment-type {
            color: var(--accent-green);
            font-weight: 600;
        }
        
        .person-group {
            color: var(--info-color);
            font-style: italic;
        }
        
        /* Edit Mode Indicator */
        .edit-mode-indicator {
            background: #e3f2fd;
            border: 1px solid #2196F3;
            color: #1976d2;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }
        
        /* Site Info Display */
        .site-info {
            display: grid;
            gap: 6px;
        }
        
        .site-info div {
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .site-info strong {
            color: var(--sidebar-dark-green);
            font-weight: 600;
            min-width: 80px;
            display: inline-block;
        }
        
        /* Action Buttons */
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
            width: 32px;
            height: 32px;
            border-radius: var(--border-radius);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: white;
            flex-shrink: 0;
        }
        
        .view-btn {
            background-color: #2196F3;
        }
        
        .view-btn:hover {
            background-color: #1976D2;
        }
        
        .edit-btn {
            background-color: var(--warning-color);
        }
        
        .edit-btn:hover {
            background-color: #e67e22;
        }
        
        .delete-btn {
            background-color: var(--danger-color);
        }
        
        .delete-btn:hover {
            background-color: #c0392b;
        }
        
        .add-employee-btn {
            background-color: var(--info-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .add-employee-btn:hover {
            background-color: #0b7dda;
            transform: translateY(-1px);
        }
        
        /* Table Styling */
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
            table-layout: fixed;
        }
        
        .site-table thead {
            background: linear-gradient(to right, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
        }
        
        .site-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .site-table th:nth-child(1) {
            width: 60%;
        }
        
        .site-table th:nth-child(2) {
            width: 40%;
        }
        
        .site-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .site-table tbody tr:hover {
            background-color: var(--light-green);
        }
        
        .site-table td {
            padding: 15px;
            vertical-align: top;
            word-wrap: break-word;
        }
        
        /* Employee Modal Styles */
        .employee-select-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        
        .employee-select-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            padding: 12px;
            background: #f8fff8;
        }
        
        .employee-select-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin-bottom: 6px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .employee-select-item:hover {
            background-color: var(--light-green);
            transform: translateX(5px);
        }
        
        .employee-select-item input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.1);
            cursor: pointer;
            flex-shrink: 0;
            accent-color: var(--accent-green);
        }
        
        .employee-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .employee-name {
            font-weight: 600;
            color: var(--sidebar-dark-green);
        }
        
        .employee-position {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }
        
        .status-unassigned {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .selected-count {
            background-color: var(--info-color);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .select-all-btn {
            background-color: var(--accent-green);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }
        
        .select-all-btn:hover {
            background-color: var(--sidebar-dark-green);
        }
        
        .no-employees-available {
            text-align: center;
            padding: 30px;
            color: #999;
        }
        
        .no-employees-available i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: #d1f0eb;
        }
        
        /* Employee List in Edit Modal */
        .employee-list-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        
        .employee-checkbox-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            padding: 12px;
            background: #f8fff8;
        }
        
        .employee-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin-bottom: 6px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .employee-item:hover {
            background-color: #ffebee;
        }
        
        .employee-item input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.1);
            cursor: pointer;
            flex-shrink: 0;
            accent-color: var(--danger-color);
        }
        
        .employee-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .employee-info .name {
            font-weight: 600;
            color: var(--sidebar-dark-green);
        }
        
        .employee-info .id {
            font-size: 0.8rem;
            color: #666;
        }
        
        .remove-employees-section {
            background-color: #FFEBEE;
            border: 1px solid #FFCDD2;
            border-radius: var(--border-radius);
            padding: 12px;
            margin-top: 12px;
        }
        
        .remove-employees-section h5 {
            color: var(--danger-color);
            margin-bottom: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .employee-search-container {
            position: relative;
            margin-bottom: 15px;
        }
        
        .employee-search-input {
            width: 100%;
            padding: 8px 35px 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }
        
        .employee-search-input:focus {
            outline: none;
            border-color: var(--accent-green);
        }
        
        .employee-search-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--sidebar-green);
            cursor: pointer;
        }
        
        .employee-item.hidden {
            display: none;
        }
        
        .no-results {
            text-align: center;
            padding: 20px;
            color: #999;
        }
        
        /* View Employees Modal */
        .view-employee-item {
            padding: 10px;
            margin-bottom: 8px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }
        
        .view-employee-item:hover {
            background-color: var(--light-green);
        }
        
        .view-employee-name {
            font-weight: 600;
            color: var(--sidebar-dark-green);
            font-size: 0.95rem;
        }
        
        .view-employee-info {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
        
        .view-employee-info i {
            color: var(--accent-green);
            width: 16px;
        }
        
        .employee-count-badge {
            background-color: var(--info-color);
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .no-assigned-employees {
            text-align: center;
            padding: 30px;
            color: #7f8c8d;
        }
        
        .no-assigned-employees i {
            font-size: 3rem;
            color: #d1f0eb;
            margin-bottom: 15px;
        }
        
        .view-modal-header p {
            margin: 5px 0;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.9);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--sidebar-green);
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: #d1f0eb;
            margin-bottom: 10px;
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
            
            .action-btn, .add-employee-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include_once("./includes/header.php"); ?>
    
    <!-- Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>
    
    <?php
    // Display notifications from session
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
            <!-- Controls Container with Search, Sort, and Add Button -->
            <div class="controls-container">
                <div class="search-section">
                    <div class="search-container">
                        <form method="GET" action="site_monitoring.php" style="display: flex; align-items: center; width: 100%;">
                            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
                            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                            <input type="text" name="search" 
                                   value="<?php echo htmlspecialchars($search_query); ?>" 
                                   placeholder="Search Site..." 
                                   class="search-bar">
                            <button type="submit" class="search-btn">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                    
                    <!-- Sort Controls -->
                    <div class="sort-container">
                        <form method="GET" action="site_monitoring.php" id="sortForm" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                            <select name="sort_by" class="sort-select" onchange="document.getElementById('sortForm').submit();">
                                <option value="site_name" <?php echo $sort_by == 'site_name' ? 'selected' : ''; ?>>Name</option>
                                <option value="site_manager" <?php echo $sort_by == 'site_manager' ? 'selected' : ''; ?>>Manager</option>
                                <option value="site_address" <?php echo $sort_by == 'site_address' ? 'selected' : ''; ?>>Address</option>
                                <option value="is_others" <?php echo $sort_by == 'is_others' ? 'selected' : ''; ?>>Type</option>
                                <option value="id" <?php echo $sort_by == 'id' ? 'selected' : ''; ?>>ID</option>
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
                            <tr>
                                <th>Site / Assignment Information</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $employee_count = getSiteEmployeeCount($conn, $row['id']);
                                $is_others = $row['is_others'] == 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="site-info">
                                        <?php if ($is_others && isset($row['assignment_type']) && $row['assignment_type'] !== null): ?>
                                            <div>
                                                <strong>Assignment:</strong> 
                                                <span class="assignment-type"><?php echo htmlspecialchars($row['assignment_type']); ?></span>
                                                <span class="assignment-badge">
                                                    <i class="fas fa-tasks"></i> Others
                                                </span>
                                            </div>
                                            <div>
                                                <strong>Person/Group:</strong> 
                                                <span class="person-group"><?php echo htmlspecialchars($row['person_group']); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div>
                                                <strong>Site Name:</strong> 
                                                <?php echo htmlspecialchars($row['site_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div><strong>Manager:</strong> <?php echo htmlspecialchars($row['site_manager']); ?></div>
                                        <div><strong>Address:</strong> <?php echo htmlspecialchars($row['site_address']); ?></div>
                                        <div><strong>Employees:</strong> <span style="color: var(--info-color); font-weight: 600;"><?php echo $employee_count; ?> assigned</span></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class='action-btn view-btn' 
                                                onclick='showViewEmployeesModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars(addslashes($is_others && isset($row['assignment_type']) && $row['assignment_type'] !== null ? $row['assignment_type'] : $row['site_name'])); ?>", "<?php echo htmlspecialchars(addslashes($row['site_manager'])); ?>", "<?php echo htmlspecialchars(addslashes($row['site_address'])); ?>")'
                                                title="View Employees">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <a href='site_monitoring.php?edit=<?php echo $row['id']; ?>' 
                                           class='action-btn edit-btn' 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <a href='javascript:void(0);' 
                                           class='action-btn delete-btn' 
                                           title="Delete"
                                           onclick='confirmDelete(<?php echo $row['id']; ?>)'>
                                            <i class="fas fa-trash-alt"></i>
                                        </a>

                                        <button class='add-employee-btn' 
                                                onclick='showAddEmployeeModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars(addslashes($is_others && isset($row['assignment_type']) && $row['assignment_type'] !== null ? $row['assignment_type'] : $row['site_name'])); ?>")'
                                                title="Add Employee">
                                            <i class="fas fa-user-plus"></i>
                                            Add Employee
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2">
                                    <div class="empty-state">
                                        <i class="fas fa-map-marked-alt"></i>
                                        <p>No sites or assignments found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include_once("./includes/footer.php"); ?>
    
    <?php include_once("./modal/logout-modal.php"); ?>

    <!-- Add Site/Others Modal -->
    <div id="addSiteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-marked-alt"></i> <span id="modalTitle">Add New Site</span></h3>
                <button class="modal-close" onclick="closeAddSiteModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <!-- Site Type Selector -->
                <div class="site-type-selector">
                    <button type="button" class="site-type-btn active" id="regularSiteBtn" onclick="switchSiteType('regular')">
                        <i class="fas fa-building"></i> Regular Site
                    </button>
                    <button type="button" class="site-type-btn others-btn" id="othersBtn" onclick="switchSiteType('others')">
                        <i class="fas fa-tasks"></i> Others (Assignment)
                    </button>
                </div>
                
                <!-- Regular Site Form -->
                <form method="POST" action="site_monitoring.php" id="regularSiteForm">
                    <div class="site-form">
                        <div class="form-group">
                            <label for="site-name">Site Name *</label>
                            <input type="text" id="site-name" name="site_name" 
                                   required placeholder="Enter site name" 
                                   autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="site-manager">Manager *</label>
                            <input type="text" id="site-manager" name="site_manager" 
                                   required placeholder="Enter manager name" 
                                   autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="site-address">Address *</label>
                            <input type="text" id="site-address" name="site_address" 
                                   required placeholder="Enter address" 
                                   autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel" onclick="closeAddSiteModal()">Cancel</button>
                        <button type="submit" name="save_site" class="btn btn-save">Save Site</button>
                    </div>
                </form>
                
                <!-- Others/Assignment Form (Simplified) -->
                <form method="POST" action="site_monitoring.php" id="othersForm" style="display: none;">
                    <div class="others-form">
                        <div class="others-section">
                            <h4>
                                <i class="fas fa-clipboard-list"></i> Assignment Details
                            </h4>
                            
                            <div class="form-group">
                                <label for="assignment_type">Assignment Type *</label>
                                <select id="assignment_type" name="assignment_type" required>
                                    <option value="">-- Select Assignment Type --</option>
                                    <option value="Meeting">Meeting</option>
                                    <option value="Project">Project</option>
                                    <option value="Activities">Activities</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="person_group">Person or Group Involved *</label>
                                <input type="text" id="person_group" name="person_group" 
                                       required placeholder="Enter person name or group" 
                                       autocomplete="off">
                            </div>
                            
                            <div class="form-group">
                                <label for="assignment_manager">Manager/Officer *</label>
                                <input type="text" id="assignment_manager" name="assignment_manager" 
                                       required placeholder="Enter manager/officer name">
                            </div>
                            
                            <div class="form-group">
                                <label for="assignment_location">Address/Location *</label>
                                <input type="text" id="assignment_location" name="assignment_location" 
                                       required placeholder="Enter address or location">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel" onclick="closeAddSiteModal()">Cancel</button>
                        <button type="submit" name="save_others" class="btn btn-save" style="background: var(--accent-green);">
                            <i class="fas fa-save"></i> Create Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Employee Modal -->
    <div id="addEmployeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Employees to Site</h3>
                <button class="modal-close" onclick="closeAddEmployeeModal()">&times;</button>
            </div>
            <form method="POST" action="site_monitoring.php" id="addEmployeeForm">
                <input type="hidden" id="add_site_id" name="add_site_id" value="">
                
                <div class="modal-body">
                    <div class="site-form">
                        <div class="form-group">
                            <label for="site-select">Select Site</label>
                            <select id="site-select" class="form-control" required>
                                <option value="">-- Select a Site --</option>
                                <?php foreach ($all_sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>">
                                    <?php echo htmlspecialchars($site['site_name']); ?>
                                    <?php if (!empty($site['assignment_type'])): ?>
                                        (<?php echo htmlspecialchars($site['assignment_type']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="employee-select-section">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h4 style="margin: 0;">
                                    <i class="fas fa-users"></i> Available Employees
                                </h4>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span id="selectedCount" class="selected-count">0 selected</span>
                                    <button type="button" class="select-all-btn" id="selectAllBtn" onclick="selectAllEmployees()">
                                        <i class="fas fa-check"></i> Select All
                                    </button>
                                </div>
                            </div>
                            
                            <div class="employee-search-section">
                                <div class="search-container">
                                    <input type="text" 
                                           id="employeeSearchInput" 
                                           placeholder="Search employees by name..." 
                                           class="search-bar"
                                           onkeyup="searchAvailableEmployees()">
                                    <button type="button" class="search-btn" onclick="searchAvailableEmployees()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div id="employeeSelectList" class="employee-select-list">
                                <div class="no-employees-available">
                                    <i class="fas fa-user-slash"></i>
                                    <p>Select a site first to see available employees</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeAddEmployeeModal()">Cancel</button>
                    <button type="submit" name="add_employees_to_site" class="btn btn-add" id="addEmployeesBtn" disabled>
                        <i class="fas fa-user-plus"></i> Add Selected Employees
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Employees Modal -->
    <div id="viewEmployeesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3><i class="fas fa-users"></i> Site Employees</h3>
                    <div class="view-modal-header">
                        <p id="viewSiteName"></p>
                        <p><i class="fas fa-user-tie"></i> <span id="viewSiteManager"></span></p>
                        <p><i class="fas fa-map-marker-alt"></i> <span id="viewSiteAddress"></span></p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeViewEmployeesModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="employeesListContainer">
                    <div class="no-assigned-employees">
                        <i class="fas fa-user-clock"></i>
                        <p>Loading employees...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeViewEmployeesModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Site Modal -->
    <?php if ($site_details): ?>
    <div id="editSiteModal" class="modal" style="display: flex;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Site</h3>
                <button class="modal-close" onclick="closeEditSiteModal()">&times;</button>
            </div>
            <form method="POST" action="site_monitoring.php" id="editSiteForm">
                <input type="hidden" name="site_id" value="<?php echo $site_details['id']; ?>">
                <input type="hidden" name="is_others" value="<?php echo $site_details['is_others']; ?>">
                
                <div class="modal-body">
                    <div class="site-form">
                        <?php if ($site_details['is_others'] == 1 && $site_others): ?>
                        <div class="edit-mode-indicator">
                            <i class="fas fa-tasks"></i>
                            <span>Editing Assignment - All changes will be saved when you click Update</span>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-assignment-type">Assignment Type *</label>
                            <select id="edit-assignment-type" name="edit_assignment_type" required>
                                <option value="Meeting" <?php echo $site_others['assignment_type'] == 'Meeting' ? 'selected' : ''; ?>>Meeting</option>
                                <option value="Project" <?php echo $site_others['assignment_type'] == 'Project' ? 'selected' : ''; ?>>Project</option>
                                <option value="Activities" <?php echo $site_others['assignment_type'] == 'Activities' ? 'selected' : ''; ?>>Activities</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-person-group">Person or Group Involved *</label>
                            <input type="text" id="edit-person-group" name="edit_person_group" 
                                   value="<?php echo htmlspecialchars($site_others['person_group']); ?>"
                                   required placeholder="Enter person name or group" 
                                   autocomplete="off">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-site-manager">Manager/Officer *</label>
                            <input type="text" id="edit-site-manager" name="edit_site_manager" 
                                   value="<?php echo htmlspecialchars($site_details['site_manager']); ?>"
                                   required placeholder="Enter manager name" 
                                   autocomplete="off">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-site-address">Address/Location *</label>
                            <input type="text" id="edit-site-address" name="edit_site_address" 
                                   value="<?php echo htmlspecialchars($site_details['site_address']); ?>"
                                   required placeholder="Enter address or location" 
                                   autocomplete="off">
                        </div>
                        
                        <?php else: ?>
                        <div class="form-group">
                            <label for="edit-site-name">Site Name *</label>
                            <input type="text" id="edit-site-name" name="edit_site_name" 
                                   value="<?php echo htmlspecialchars($site_details['site_name']); ?>"
                                   required placeholder="Enter site name" 
                                   autocomplete="off">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-site-manager">Manager *</label>
                            <input type="text" id="edit-site-manager" name="edit_site_manager" 
                                   value="<?php echo htmlspecialchars($site_details['site_manager']); ?>"
                                   required placeholder="Enter manager name" 
                                   autocomplete="off">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-site-address">Address *</label>
                            <input type="text" id="edit-site-address" name="edit_site_address" 
                                   value="<?php echo htmlspecialchars($site_details['site_address']); ?>"
                                   required placeholder="Enter address" 
                                   autocomplete="off">
                        </div>
                        <?php endif; ?>
                        
                        <!-- Employee Management Section -->
                        <?php if (!empty($site_employees)): ?>
                        <div class="employee-list-section">
                            <h4><i class="fas fa-users"></i> Assigned Employees (<?php echo count($site_employees); ?>)</h4>
                            <p style="color: #666; font-size: 0.85rem; margin-bottom: 10px;">
                                <i class="fas fa-info-circle"></i> Removing an employee will make them available for assignment to other sites.
                            </p>
                            
                            <div class="remove-employees-section">
                                <h5><i class="fas fa-user-minus"></i> Remove Employees</h5>
                                
                                <div class="employee-search-container">
                                    <input type="text" 
                                           id="employeeSearch" 
                                           class="employee-search-input" 
                                           placeholder="Search employees by name..." 
                                           onkeyup="searchEmployees()">
                                    <button type="button" class="employee-search-btn">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                
                                <div id="employeeCheckboxList" class="employee-checkbox-list searchable">
                                    <?php foreach ($site_employees as $employee): ?>
                                    <div class="employee-item" data-name="<?php echo strtolower(htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'])); ?>" data-id="<?php echo $employee['id']; ?>">
                                        <input type="checkbox" 
                                               id="employee_<?php echo $employee['id']; ?>" 
                                               name="remove_employees[]" 
                                               value="<?php echo $employee['id']; ?>">
                                        <label for="employee_<?php echo $employee['id']; ?>">
                                            <div class="employee-info">
                                                <span class="name"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                                                <span class="id">ID: <?php echo $employee['id']; ?> | <?php echo htmlspecialchars($employee['position']); ?></span>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div id="noResultsMessage" class="no-results" style="display: none;">
                                    <i class="fas fa-search"></i>
                                    <p>No employees found matching your search</p>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="employee-list-section">
                            <h4><i class="fas fa-users"></i> Assigned Employees (0)</h4>
                            <div class="no-employees">
                                <i class="fas fa-user-slash"></i>
                                <p>No employees assigned to this site yet.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeEditSiteModal()">Cancel</button>
                    <button type="submit" name="update_site" class="btn-update" style="background-color: var(--sidebar-green); color: white; padding: 8px 20px; border: none; border-radius: var(--border-radius); font-size: 0.95rem; font-weight: 500; cursor: pointer;">
                        <i class="fas fa-save"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Notification System
        function showNotification(message, type = 'success', duration = 5000) {
            const container = document.getElementById('notificationContainer');
            if (!container) return;
            
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            
            let icon = '';
            switch(type) {
                case 'success':
                    icon = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'error':
                    icon = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                case 'warning':
                    icon = '<i class="fas fa-exclamation-triangle"></i>';
                    break;
                default:
                    icon = '<i class="fas fa-info-circle"></i>';
            }
            
            notification.innerHTML = `
                ${icon}
                <div class="notification-content">${message}</div>
                <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
            `;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }, duration);
        }

        // Confirm Delete
        function confirmDelete(siteId) {
            if (confirm('Are you sure you want to delete this site? This will unassign all employees.')) {
                window.location.href = 'site_monitoring.php?delete=' + siteId;
            }
        }

        // Switch between regular site and others
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
                modalTitle.innerHTML = '<i class="fas fa-map-marked-alt"></i> Add New Site';
            } else {
                othersBtn.classList.add('active');
                regularBtn.classList.remove('active');
                regularForm.style.display = 'none';
                othersForm.style.display = 'block';
                modalTitle.innerHTML = '<i class="fas fa-tasks"></i> Create New Assignment';
            }
        }

        // Add Site Modal functions
        function showAddSiteModal() {
            document.getElementById('addSiteModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            switchSiteType('regular');
        }

        function closeAddSiteModal() {
            document.getElementById('addSiteModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            
            // Clear regular form
            const siteName = document.getElementById('site-name');
            const siteManager = document.getElementById('site-manager');
            const siteAddress = document.getElementById('site-address');
            if (siteName) siteName.value = '';
            if (siteManager) siteManager.value = '';
            if (siteAddress) siteAddress.value = '';
            
            // Clear others form
            const assignmentType = document.getElementById('assignment_type');
            const personGroup = document.getElementById('person_group');
            const assignmentManager = document.getElementById('assignment_manager');
            const assignmentLocation = document.getElementById('assignment_location');
            
            if (assignmentType) assignmentType.value = '';
            if (personGroup) personGroup.value = '';
            if (assignmentManager) assignmentManager.value = '';
            if (assignmentLocation) assignmentLocation.value = '';
        }

        // Add Employee Modal functions
        function showAddEmployeeModal(siteId, siteName) {
            const modal = document.getElementById('addEmployeeModal');
            const siteIdField = document.getElementById('add_site_id');
            const siteSelect = document.getElementById('site-select');
            const modalTitle = document.querySelector('#addEmployeeModal .modal-header h3');
            
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
            
            if (siteIdField) siteIdField.value = siteId;
            if (siteSelect) siteSelect.value = siteId;
            if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-user-plus"></i> Add Employees to ' + siteName;
            
            loadAvailableEmployees(siteId);
            
            const searchInput = document.getElementById('employeeSearchInput');
            if (searchInput) {
                searchInput.value = '';
                searchInput.disabled = false;
            }
        }

        function closeAddEmployeeModal() {
            document.getElementById('addEmployeeModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            
            const siteIdField = document.getElementById('add_site_id');
            const siteSelect = document.getElementById('site-select');
            const employeeList = document.getElementById('employeeSelectList');
            const selectedCount = document.getElementById('selectedCount');
            const addBtn = document.getElementById('addEmployeesBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');
            
            if (siteIdField) siteIdField.value = '';
            if (siteSelect) siteSelect.value = '';
            if (employeeList) {
                employeeList.innerHTML = '<div class="no-employees-available"><i class="fas fa-user-slash"></i><p>Select a site first to see available employees</p></div>';
            }
            if (selectedCount) selectedCount.textContent = '0 selected';
            if (addBtn) addBtn.disabled = true;
            if (selectAllBtn) selectAllBtn.style.display = 'inline-flex';
        }

        // View Employees Modal functions
        function showViewEmployeesModal(siteId, siteName, siteManager, siteAddress) {
            const modal = document.getElementById('viewEmployeesModal');
            const viewSiteName = document.getElementById('viewSiteName');
            const viewSiteManager = document.getElementById('viewSiteManager');
            const viewSiteAddress = document.getElementById('viewSiteAddress');
            
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
            
            if (viewSiteName) viewSiteName.innerHTML = '<i class="fas fa-building"></i> ' + siteName;
            if (viewSiteManager) viewSiteManager.textContent = siteManager;
            if (viewSiteAddress) viewSiteAddress.textContent = siteAddress;
            
            loadSiteEmployees(siteId);
        }

        function closeViewEmployeesModal() {
            document.getElementById('viewEmployeesModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            
            const container = document.getElementById('employeesListContainer');
            if (container) {
                container.innerHTML = '<div class="no-assigned-employees"><i class="fas fa-user-clock"></i><p>Loading employees...</p></div>';
            }
        }

        // Edit Site Modal functions
        function closeEditSiteModal() {
            window.location.href = 'site_monitoring.php';
        }

        // Search employees in edit modal
        function searchEmployees() {
            const searchTerm = document.getElementById('employeeSearch').value.toLowerCase();
            const employeeItems = document.querySelectorAll('.employee-item');
            const noResultsMessage = document.getElementById('noResultsMessage');
            let visibleCount = 0;
            
            employeeItems.forEach(item => {
                const employeeName = item.getAttribute('data-name');
                const employeeId = item.getAttribute('data-id');
                
                if (employeeName.includes(searchTerm) || employeeId.includes(searchTerm)) {
                    item.classList.remove('hidden');
                    visibleCount++;
                } else {
                    item.classList.add('hidden');
                }
            });
            
            if (noResultsMessage) {
                if (visibleCount === 0 && searchTerm !== '') {
                    noResultsMessage.style.display = 'block';
                } else {
                    noResultsMessage.style.display = 'none';
                }
            }
        }

        // Search available employees in add employee modal
        function searchAvailableEmployees() {
            const searchTerm = document.getElementById('employeeSearchInput').value.toLowerCase();
            const employeeItems = document.querySelectorAll('#employeeSelectList .employee-select-item');
            let visibleCount = 0;
            
            employeeItems.forEach(item => {
                const employeeName = item.querySelector('.employee-name')?.textContent.toLowerCase() || '';
                const employeeId = item.querySelector('input[type="checkbox"]')?.value || '';
                
                if (employeeName.includes(searchTerm) || employeeId.includes(searchTerm)) {
                    item.classList.remove('hidden');
                    visibleCount++;
                } else {
                    item.classList.add('hidden');
                }
            });
            
            const noResultsElement = document.getElementById('searchNoResults');
            if (visibleCount === 0 && searchTerm !== '' && employeeItems.length > 0) {
                if (!noResultsElement) {
                    const noResultsDiv = document.createElement('div');
                    noResultsDiv.id = 'searchNoResults';
                    noResultsDiv.className = 'no-results';
                    noResultsDiv.innerHTML = '<i class="fas fa-search"></i><p>No employees found matching your search</p>';
                    document.getElementById('employeeSelectList').appendChild(noResultsDiv);
                } else {
                    noResultsElement.style.display = 'block';
                }
            } else if (noResultsElement) {
                noResultsElement.style.display = 'none';
            }
        }

        // Load site employees for view modal
        async function loadSiteEmployees(siteId) {
            const container = document.getElementById('employeesListContainer');
            if (!container) return;
            
            container.innerHTML = '<div class="spinner"></div>';
            
            try {
                const response = await fetch('get_site_employees.php?site_id=' + siteId);
                const data = await response.json();
                
                if (data.success && data.employees && data.employees.length > 0) {
                    let html = '';
                    data.employees.forEach(employee => {
                        html += `
                            <div class="view-employee-item">
                                <div class="view-employee-details">
                                    <div class="view-employee-name">${employee.first_name} ${employee.last_name}</div>
                                    <div class="view-employee-info">
                                        <span><i class="fas fa-id-badge"></i> ID: ${employee.id}</span>
                                        <span><i class="fas fa-briefcase"></i> ${employee.position || 'No position'}</span>
                                        ${employee.email ? '<span><i class="fas fa-envelope"></i> ' + employee.email + '</span>' : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    const titleElement = document.querySelector('#viewEmployeesModal .modal-header h3');
                    if (titleElement) {
                        titleElement.innerHTML = '<i class="fas fa-users"></i> Site Employees <span class="employee-count-badge">' + data.employees.length + ' employees</span>';
                    }
                    
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="no-assigned-employees">
                            <i class="fas fa-user-slash"></i>
                            <p>No employees assigned to this site yet.</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading site employees:', error);
                container.innerHTML = `
                    <div class="no-assigned-employees">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading employees. Please try again.</p>
                    </div>
                `;
            }
        }

        // Load available employees via AJAX
        async function loadAvailableEmployees(siteId) {
            const employeeSelectList = document.getElementById('employeeSelectList');
            if (!employeeSelectList) return;
            
            employeeSelectList.innerHTML = '<div class="spinner"></div>';
            
            try {
                const response = await fetch('get_available_employees.php?site_id=' + siteId);
                const data = await response.json();
                
                if (data.success && data.employees && data.employees.length > 0) {
                    let html = '';
                    data.employees.forEach(employee => {
                        html += `
                            <div class="employee-select-item">
                                <input type="checkbox" 
                                       id="emp_${employee.id}" 
                                       name="selected_employees[]" 
                                       value="${employee.id}"
                                       onchange="updateSelectedCount()">
                                <label for="emp_${employee.id}">
                                    <div class="employee-details">
                                        <span class="employee-name">${employee.first_name} ${employee.last_name}</span>
                                        <span class="employee-position">${employee.position || 'No position'}</span>
                                        <span class="employee-status status-unassigned">Available</span>
                                    </div>
                                </label>
                            </div>
                        `;
                    });
                    employeeSelectList.innerHTML = html;
                    
                    const selectAllBtn = document.getElementById('selectAllBtn');
                    if (selectAllBtn) selectAllBtn.style.display = 'inline-flex';
                    
                    const searchInput = document.getElementById('employeeSearchInput');
                    if (searchInput) {
                        searchInput.value = '';
                        searchInput.disabled = false;
                    }
                } else {
                    employeeSelectList.innerHTML = `
                        <div class="no-employees-available">
                            <i class="fas fa-user-check"></i>
                            <p>No available employees. All active employees are already assigned to sites.</p>
                        </div>
                    `;
                    
                    const selectAllBtn = document.getElementById('selectAllBtn');
                    if (selectAllBtn) selectAllBtn.style.display = 'none';
                    
                    const searchInput = document.getElementById('employeeSearchInput');
                    if (searchInput) searchInput.disabled = true;
                }
                updateSelectedCount();
            } catch (error) {
                console.error('Error loading employees:', error);
                employeeSelectList.innerHTML = `
                    <div class="no-employees-available">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading employees. Please try again.</p>
                    </div>
                `;
                
                const selectAllBtn = document.getElementById('selectAllBtn');
                if (selectAllBtn) selectAllBtn.style.display = 'none';
                
                const searchInput = document.getElementById('employeeSearchInput');
                if (searchInput) searchInput.disabled = true;
            }
        }

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('#employeeSelectList input[type="checkbox"]');
            const selected = document.querySelectorAll('#employeeSelectList input[type="checkbox"]:checked');
            const selectedCount = document.getElementById('selectedCount');
            const addEmployeesBtn = document.getElementById('addEmployeesBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');
            
            if (selectedCount) {
                selectedCount.textContent = selected.length + ' selected';
            }
            
            if (selectAllBtn && checkboxes.length > 0) {
                if (selected.length === checkboxes.length) {
                    selectAllBtn.innerHTML = '<i class="fas fa-times"></i> Deselect All';
                } else {
                    selectAllBtn.innerHTML = '<i class="fas fa-check"></i> Select All';
                }
            }
            
            if (addEmployeesBtn) {
                addEmployeesBtn.disabled = selected.length === 0;
            }
        }

        // Select all employees
        function selectAllEmployees() {
            const checkboxes = document.querySelectorAll('#employeeSelectList input[type="checkbox"]');
            const selectAllBtn = document.getElementById('selectAllBtn');
            
            if (!selectAllBtn) return;
            
            const isSelectingAll = selectAllBtn.innerHTML.includes('Select All');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = isSelectingAll;
            });
            
            updateSelectedCount();
        }

        // Site select change handler
        document.addEventListener('DOMContentLoaded', function() {
            const siteSelect = document.getElementById('site-select');
            if (siteSelect) {
                siteSelect.addEventListener('change', function() {
                    const siteId = this.value;
                    const addSiteIdField = document.getElementById('add_site_id');
                    const modalTitle = document.querySelector('#addEmployeeModal .modal-header h3');
                    
                    if (siteId && addSiteIdField) {
                        addSiteIdField.value = siteId;
                        loadAvailableEmployees(siteId);
                        
                        const selectedOption = this.options[this.selectedIndex];
                        if (modalTitle) {
                            modalTitle.innerHTML = '<i class="fas fa-user-plus"></i> Add Employees to ' + selectedOption.text;
                        }
                        
                        const searchInput = document.getElementById('employeeSearchInput');
                        if (searchInput) {
                            searchInput.disabled = false;
                            searchInput.focus();
                        }
                    } else {
                        if (addSiteIdField) addSiteIdField.value = '';
                        
                        const employeeList = document.getElementById('employeeSelectList');
                        if (employeeList) {
                            employeeList.innerHTML = '<div class="no-employees-available"><i class="fas fa-user-slash"></i><p>Select a site first to see available employees</p></div>';
                        }
                        
                        const selectedCount = document.getElementById('selectedCount');
                        if (selectedCount) selectedCount.textContent = '0 selected';
                        
                        const addBtn = document.getElementById('addEmployeesBtn');
                        if (addBtn) addBtn.disabled = true;
                        
                        const selectAllBtn = document.getElementById('selectAllBtn');
                        if (selectAllBtn) selectAllBtn.style.display = 'none';
                        
                        const searchInput = document.getElementById('employeeSearchInput');
                        if (searchInput) {
                            searchInput.disabled = true;
                            searchInput.value = '';
                        }
                    }
                });
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addSiteModal');
            const editModal = document.getElementById('editSiteModal');
            const addEmployeeModal = document.getElementById('addEmployeeModal');
            const viewEmployeesModal = document.getElementById('viewEmployeesModal');
            
            if (event.target === addModal) closeAddSiteModal();
            if (event.target === editModal) closeEditSiteModal();
            if (event.target === addEmployeeModal) closeAddEmployeeModal();
            if (event.target === viewEmployeesModal) closeViewEmployeesModal();
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddSiteModal();
                closeAddEmployeeModal();
                closeViewEmployeesModal();
            }
        });

        // Form validation
        document.addEventListener('submit', function(e) {
            if (e.target.id === 'addEmployeeForm') {
                const selected = document.querySelectorAll('#employeeSelectList input[type="checkbox"]:checked');
                if (selected.length === 0) {
                    e.preventDefault();
                    showNotification('Please select at least one employee to add.', 'warning');
                    return false;
                }
            }
        });

        // Enter key for search
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.id === 'employeeSearchInput') {
                e.preventDefault();
                searchAvailableEmployees();
            }
        });
    </script>
</body>
</html>