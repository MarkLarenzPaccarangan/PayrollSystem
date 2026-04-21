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

// Connection to the database
include_once("connection.php");

// Set MySQL to accept dates in a more flexible format
$conn->query("SET SESSION sql_mode = 'ALLOW_INVALID_DATES'");

// Function to safely format date for display
function formatDateForDisplay($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }
    // Check if it's a valid date
    $timestamp = strtotime($date);
    if ($timestamp === false || $timestamp === -1) {
        return '';
    }
    return date('m/d/Y', $timestamp);
}

// Function to calculate age from date of birth
function calculateAge($dob) {
    if (empty($dob) || $dob == '0000-00-00') {
        return '';
    }
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    return $age;
}

// FIX: Add date validation function
function validateDate($date, $format = 'Y-m-d') {
    if (empty($date) || $date == '0000-00-00') return false;
    
    // Check if it's just a year (like "2008" or "2010")
    if (preg_match('/^\d{4}$/', $date)) {
        return false;
    }
    
    // Check format
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// FIX: Function to sanitize and format date
function sanitizeDate($date) {
    if (empty($date)) return null;
    
    // Remove any extra spaces
    $date = trim($date);
    
    // Check if it's just a year
    if (preg_match('/^\d{4}$/', $date)) {
        return null; // Invalid - just a year
    }
    
    // Try to parse the date
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return null;
    }
    
    // Return in YYYY-MM-DD format
    return date('Y-m-d', $timestamp);
}

// ============================================
// MODIFIED: HELPER FUNCTION TO DETERMINE DEPARTMENT FROM POSITION WITH HIERARCHY
// Priority: Executive > Admin > Technical
// ============================================
function getDepartmentFromPosition($position) {
    global $conn;
    
    // Handle empty position
    if (empty($position)) {
        return 'Technical'; // Default to Technical instead of Other
    }
    
    // Split multiple positions
    $positions = explode(',', $position);
    $hasExecutive = false;
    $hasAdmin = false;
    $hasTechnical = false;
    
    foreach ($positions as $pos) {
        $pos = strtolower(trim($pos));
        if (empty($pos)) continue;
        
        // Check against hardcoded categories for default positions
        $executive_keywords = ['chief executive officer', 'ceo', 'general manager', 'sales and marketing manager'];
        foreach ($executive_keywords as $keyword) {
            if (strpos($pos, $keyword) !== false) {
                $hasExecutive = true;
                break 2;
            }
        }
        
        $technical_keywords = [
            'technical director', 'design department head', 'design engineer', 
            'design technical engineer', 'cad operator', 'implementation',
            'technical department head', 'site engineer', 'safety officer 2',
            'lead man', 'electrician', 'carpenter', 'welder', 'electronics',
            'painter', 'scaffolder', 'safety officer department head'
        ];
        foreach ($technical_keywords as $keyword) {
            if (strpos($pos, $keyword) !== false) {
                $hasTechnical = true;
                break 2;
            }
        }
        
        $admin_keywords = ['admin', 'purchasing', 'hr manager', 'finance and administrative officer', 'maintenance'];
        foreach ($admin_keywords as $keyword) {
            if (strpos($pos, $keyword) !== false) {
                $hasAdmin = true;
                break 2;
            }
        }
        
        // If not found in hardcoded lists, query the positions table
        $check_query = $conn->prepare("SELECT category FROM positions WHERE LOWER(position_name) = ? OR LOWER(position_name) LIKE ?");
        $search_term = '%' . $pos . '%';
        $check_query->bind_param("ss", $pos, $search_term);
        $check_query->execute();
        $cat_result = $check_query->get_result();
        
        if ($cat_result && $cat_result->num_rows > 0) {
            $cat_row = $cat_result->fetch_assoc();
            switch($cat_row['category']) {
                case 'executive':
                    $hasExecutive = true;
                    break 2;
                case 'admin':
                    $hasAdmin = true;
                    break 2;
                case 'technical':
                    $hasTechnical = true;
                    break 2;
            }
        }
        
        // Additional keyword checks for positions that might not be in database
        if (!$hasExecutive && !$hasAdmin && !$hasTechnical) {
            if (strpos($pos, 'executive') !== false || strpos($pos, 'manager') !== false || strpos($pos, 'director') !== false) {
                $hasExecutive = true;
            } elseif (strpos($pos, 'admin') !== false || strpos($pos, 'hr') !== false || strpos($pos, 'finance') !== false || strpos($pos, 'purchasing') !== false) {
                $hasAdmin = true;
            } elseif (strpos($pos, 'technical') !== false || strpos($pos, 'engineer') !== false || strpos($pos, 'technician') !== false || 
                      strpos($pos, 'design') !== false || strpos($pos, 'site') !== false || strpos($pos, 'safety') !== false) {
                $hasTechnical = true;
            } else {
                // Default to Technical for any unrecognized position
                $hasTechnical = true;
            }
        }
    }
    
    // Apply hierarchy: Executive > Admin > Technical
    if ($hasExecutive) {
        return 'Executive';
    } elseif ($hasAdmin) {
        return 'Admin';
    } else {
        return 'Technical';
    }
}

// ============================================
// AJAX HANDLERS - DAPAT NASA ITAAS PARA WALANG HEADER ISSUE
// ============================================

// Handle AJAX request to get positions
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_positions') {
    header('Content-Type: application/json');
    
    $category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : 'technical';
    $result = $conn->query("SELECT id, position_name as name, is_custom FROM positions WHERE category = '$category' AND status = 'active' ORDER BY position_name");
    
    $positions = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $positions[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'is_custom' => $row['is_custom']
            ];
        }
    }
    
    echo json_encode($positions);
    exit;
}

// Handle AJAX request to add custom position
if (isset($_POST['ajax']) && $_POST['ajax'] == 'add_position') {
    header('Content-Type: application/json');
    
    $position_name = trim($_POST['position_name']);
    $category = $_POST['category'];
    
    // Check if position already exists
    $check = $conn->prepare("SELECT id FROM positions WHERE position_name = ?");
    $check->bind_param("s", $position_name);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Position already exists']);
        exit;
    }
    
    // Insert new position
    $stmt = $conn->prepare("INSERT INTO positions (position_name, category, is_custom) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $position_name, $category);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Position added successfully',
            'id' => $stmt->insert_id,
            'name' => $position_name
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error adding position: ' . $conn->error]);
    }
    exit;
}

// Handle AJAX request to delete custom position
if (isset($_POST['ajax']) && $_POST['ajax'] == 'delete_position') {
    header('Content-Type: application/json');
    
    $position_id = $_POST['position_id'];
    $position_name = $_POST['position_name'];
    
    // Check if position is used by any employee
    $check = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE position LIKE ? OR position = ? OR position LIKE ?");
    $search_term1 = '%' . $position_name . '%';
    $search_term2 = $position_name;
    $search_term3 = '%,' . $position_name . ',%';
    $check->bind_param("sss", $search_term1, $search_term2, $search_term3);
    $check->execute();
    $check_result = $check->get_result();
    $row = $check_result->fetch_assoc();
    
    if ($row['count'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete: Position is assigned to ' . $row['count'] . ' employee(s)']);
        exit;
    }
    
    // Delete the position
    $stmt = $conn->prepare("DELETE FROM positions WHERE id = ? AND is_custom = 1");
    $stmt->bind_param("i", $position_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Position deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting position or position not found']);
    }
    exit;
}

// Handle AJAX request to update position
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_position') {
    header('Content-Type: application/json');
    
    $position_id = $_POST['position_id'];
    $position_name = trim($_POST['position_name']);
    
    // Check if new name already exists
    $check = $conn->prepare("SELECT id FROM positions WHERE position_name = ? AND id != ?");
    $check->bind_param("si", $position_name, $position_id);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Position name already exists']);
        exit;
    }
    
    // Update the position
    $stmt = $conn->prepare("UPDATE positions SET position_name = ? WHERE id = ?");
    $stmt->bind_param("si", $position_name, $position_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Position updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating position: ' . $conn->error]);
    }
    exit;
}

// Handle AJAX request to get all positions for management
if (isset($_GET['ajax']) && $_GET['ajax'] == 'manage_positions') {
    header('Content-Type: application/json');
    
    $result = $conn->query("SELECT * FROM positions ORDER BY category, position_name");
    
    $positions = [
        'executive' => [],
        'technical' => [],
        'admin' => []
    ];
    
    while ($row = $result->fetch_assoc()) {
        $positions[$row['category']][] = [
            'id' => $row['id'],
            'name' => $row['position_name'],
            'is_custom' => $row['is_custom'],
            'status' => $row['status']
        ];
    }
    
    echo json_encode($positions);
    exit;
}

// Handle AJAX request to delete employee
if (isset($_POST['ajax']) && $_POST['ajax'] == 'delete_employee') {
    header('Content-Type: application/json');
    
    $employee_id = $_POST['employee_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First delete from site_employee if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'site_employee'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt1 = $conn->prepare("DELETE FROM site_employee WHERE employee_id = ?");
            $stmt1->bind_param("i", $employee_id);
            $stmt1->execute();
        }
        
        // Delete from deduction if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'deduction'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt2 = $conn->prepare("DELETE FROM deduction WHERE employee_id = ?");
            $stmt2->bind_param("i", $employee_id);
            $stmt2->execute();
        }
        
        // Delete from attendance if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'attendance'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt4 = $conn->prepare("DELETE FROM attendance WHERE employee_id = ?");
            $stmt4->bind_param("i", $employee_id);
            $stmt4->execute();
        }
        
        // Finally delete the employee
        $stmt3 = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt3->bind_param("i", $employee_id);
        $stmt3->execute();
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Employee deleted successfully!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error deleting employee: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to toggle employee status
if (isset($_POST['ajax']) && $_POST['ajax'] == 'toggle_status') {
    header('Content-Type: application/json');
    
    $employee_id = $_POST['employee_id'];
    $current_status = $_POST['current_status'];

    // Toggle the employee status between active and inactive
    $new_status = ($current_status === 'active') ? 'inactive' : 'active';

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update the employee's status
        $stmt = $conn->prepare("UPDATE employees SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $employee_id);
        $stmt->execute();
        
        // If the employee is disabled, disable all their deductions
        if ($new_status === 'inactive') {
            // Check if deduction table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'deduction'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt2 = $conn->prepare("UPDATE deduction SET status = 'disabled' WHERE employee_id = ?");
                $stmt2->bind_param("i", $employee_id);
                $stmt2->execute();
            }
            
            // Check if site_employee table exists and remove employee from site assignments
            $table_check = $conn->query("SHOW TABLES LIKE 'site_employee'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt3 = $conn->prepare("DELETE FROM site_employee WHERE employee_id = ?");
                $stmt3->bind_param("i", $employee_id);
                $stmt3->execute();
            }
        }
        
        $conn->commit();
        
        $action = $new_status === 'active' ? 'enabled' : 'disabled';
        echo json_encode(['success' => true, 'message' => 'Employee ' . $action . ' successfully!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error updating employee status: ' . $e->getMessage()]);
    }
    exit;
}

// Helper function to display employee table
function displayEmployeeTable($employees) {
    if (empty($employees)) return;
    
    echo '<table>';
    echo '<tr>';
    echo '<th width="5%">ID</th>';
    echo '<th width="15%">Employee Name</th>';
    echo '<th width="5%">Age</th>';
    echo '<th width="15%">Position</th>';
    echo '<th width="10%">Contact</th>';
    echo '<th width="15%">Email</th>';
    echo '<th width="8%">Daily Salary</th>';
    echo '<th width="8%">Monthly Salary</th>';
    echo '<th width="12%">Address</th>';
    echo '<th width="5%">Status</th>';
    echo '</tr>';
    
    foreach ($employees as $emp) {
        $full_name = $emp['first_name'] . ' ' . ($emp['middle_name'] ? substr($emp['middle_name'], 0, 1) . '. ' : '') . $emp['last_name'];
        $status = $emp['status'] ?? 'active';
        $status_class = $status == 'active' ? 'status-active' : 'status-inactive';
        $employment_type = $emp['employment_type'] ?? 'regular';
        $emp_type_class = $employment_type == 'regular' ? 'regular' : 'non-regular';
        $emp_type_label = $employment_type == 'regular' ? 'Regular' : 'Non Regular';
        
        // Calculate age
        $age = calculateAge($emp['dob'] ?? '');
        
        echo '<tr>';
        echo '<td style="text-align: center; font-weight: bold;">' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT) . '</td>';
        echo '<td class="employee-name">' . $full_name . '</td>';
        echo '<td style="text-align: center; font-weight: 600; color: #2E7D32;">' . ($age ? $age : '-') . '</td>';
        echo '<td>';
        $positions = explode(', ', $emp['position']);
        foreach ($positions as $pos) {
            echo '<span class="position-badge">' . trim($pos) . '</span> ';
        }
        echo '<br><span class="employment-type-badge ' . $emp_type_class . '">' . $emp_type_label . '</span>';
        echo '</td>';
        echo '<td>' . $emp['contact_num'] . '</td>';
        echo '<td>' . ($emp['email'] ?: '-') . '</td>';
        echo '<td style="text-align: right; font-weight: bold;">₱' . number_format($emp['daily_salary'], 2) . '</td>';
        echo '<td style="text-align: right; font-weight: bold;">₱' . number_format($emp['monthly_salary'] ?? 0, 2) . '</td>';
        echo '<td>' . $emp['address'] . '</td>';
        echo '<td style="text-align: center;"><span class="status-badge ' . $status_class . '">' . ucfirst($status) . '</span></td>';
        echo '</tr>';
        
        // Add government IDs in a sub-row if they exist
        if (!empty($emp['sss_number']) || !empty($emp['pagibig_number']) || !empty($emp['tin_number']) || !empty($emp['philhealth_number'])) {
            echo '<tr style="background-color: #f5f5f5;">';
            echo '<td colspan="10" style="padding: 5px 10px; font-size: 8pt;">';
            $govt_ids = [];
            if (!empty($emp['sss_number'])) $govt_ids[] = 'SSS: ' . $emp['sss_number'];
            if (!empty($emp['pagibig_number'])) $govt_ids[] = 'PAG-IBIG: ' . $emp['pagibig_number'];
            if (!empty($emp['tin_number'])) $govt_ids[] = 'TIN: ' . $emp['tin_number'];
            if (!empty($emp['philhealth_number'])) $govt_ids[] = 'PhilHealth: ' . $emp['philhealth_number'];
            echo '<span style="color: #2E7D32; font-weight: bold;">Government IDs:</span> ' . implode(' | ', $govt_ids);
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</table>';
}

// ============================================
// WORD DOCUMENT DOWNLOAD FUNCTIONS - MODIFIED: Ascending order and removed Other category
// ============================================

// Handle Download All Employees as Word - WITH DEPARTMENT GROUPING
if (isset($_GET['download_all_word']) && $_GET['download_all_word'] == 1) {
    
    // MODIFIED: Changed to ASCENDING order
    $result = $conn->query("SELECT * FROM employees ORDER BY id ASC");
    
    // Set headers for Word download
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="All_Employees_By_Department_' . date('Y-m-d') . '.doc"');
    header('Cache-Control: max-age=0');
    
    // Create Word document content
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>All Employees Report by Department</title>';
    echo '<style>
        body { 
            font-family: "Times New Roman", Times, serif; 
            margin: 0.5in;
            color: #000000;
        }
        * {
            color: inherit;
        }
        h1 { 
            color: #2E7D32; 
            font-size: 28pt;
            text-align: center;
            margin: 0 0 5px 0;
            text-transform: uppercase;
            border-bottom: 3px solid #2E7D32;
            padding-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            font-size: 14pt;
            margin-bottom: 25px;
            color: #555;
        }
        .summary {
            background: linear-gradient(135deg, #f0f7f0, #e8f5e9);
            padding: 20px;
            margin-bottom: 30px;
            border: 2px solid #2E7D32;
            border-radius: 10px;
            font-size: 12pt;
            color: #333333;
        }
        .summary table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary td {
            text-align: center;
            padding: 10px;
            border: none;
            color: #333333;
        }
        .summary .number {
            font-size: 28pt;
            font-weight: bold;
            color: #2E7D32;
        }
        .summary .label {
            font-size: 12pt;
            color: #555;
            text-transform: uppercase;
        }
        
        /* Category Section Styles */
        .category-section {
            margin: 40px 0 20px 0;
            page-break-inside: avoid;
        }
        .category-header {
            background: linear-gradient(135deg, #2E7D32, #388E3C);
            color: #000000 !important;
            padding: 20px 25px;
            border-radius: 10px 10px 0 0;
            font-size: 22pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            border-bottom: 3px solid #FFD700;
        }
        .category-header i {
            margin-right: 15px;
            font-size: 28pt;
        }
        .category-summary {
            background: #e8f5e9;
            padding: 10px 20px;
            border-left: 3px solid #2E7D32;
            border-right: 3px solid #2E7D32;
            font-size: 11pt;
            color: #1B5E20;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 25px 0;
            font-size: 10pt;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        /* CRITICAL FIX - Table Header Styles */
        th {
            background: #2E7D32;
            color: #FFFFFF !important;
            font-weight: bold;
            padding: 8px 4px;
            border: 1px solid #1B5E20;
            text-align: center;
            font-size: 9pt;
        }
        
        th * {
            color: #FFFFFF !important;
        }
        
        /* Table Cell Styles */
        td {
            padding: 6px 4px;
            border: 1px solid #2E7D32;
            vertical-align: top;
            color: #333333 !important;
        }
        
        td * {
            color: #333333 !important;
        }
        
        tr:nth-child(even) {
            background-color: #f8fff8;
        }
        
        /* Employee Name in table - plain black text like regular text */
        .employee-name {
            font-weight: normal !important;
            color: #000000 !important;
        }
        
        /* Position badges - CHANGED TO PLAIN TEXT LIKE NAME (NO BOX, NO COLOR) */
        .position-badge {
            display: inline-block;
            padding: 0;
            background: transparent;
            border: none !important;
            font-size: 10pt;
            color: #000000 !important;
            margin: 0;
            font-weight: normal;
        }
        
        /* Employment Type Badge */
        .employment-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8pt;
            font-weight: 600;
            margin-top: 3px;
        }
        
        .employment-type-badge.regular {
            background: #e8f5e9;
            color: #2E7D32 !important;
            border: 1px solid #2E7D32;
        }
        
        .employment-type-badge.non-regular {
            background: #ffebee;
            color: #c62828 !important;
            border: 1px solid #c62828;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8pt;
            font-weight: 600;
        }
        
        .status-active {
            background: #e8f5e9;
            color: #2E7D32 !important;
            border: 1px solid #2E7D32;
        }
        
        .status-inactive {
            background: #ffebee;
            color: #c62828 !important;
            border: 1px solid #c62828;
        }
        
        /* Government IDs sub-row */
        tr[style*="background-color: #f5f5f5"] td {
            color: #666666 !important;
            font-size: 8pt;
        }
        
        .footer {
            margin-top: 40px;
            text-align: right;
            font-size: 9pt;
            border-top: 2px solid #2E7D32;
            padding-top: 15px;
            color: #555;
        }
        .executive-bg { background: linear-gradient(135deg, #8B4513, #A0522D); color: #000000 !important; }
        .technical-bg { background: linear-gradient(135deg, #2E7D32, #388E3C); color: #000000 !important; }
        .admin-bg { background: linear-gradient(135deg, #1565C0, #1976D2); color: #000000 !important; }
        
        /* Force all text in specific elements to be visible */
        .text-center, .text-left, .text-right {
            color: inherit;
        }
        
        /* Make all category header text black */
        .category-header, .category-header * {
            color: #000000 !important;
        }
    </style>';
    echo '</head>';
    echo '<body style="color: #000000;">';
    
    // Header
    echo '<h1>JLC PAYROLL SYSTEM</h1>';
    echo '<div class="subtitle">Complete Employee List Report Grouped by Department</div>';
    
    // Get all employees
    $all_employees = [];
    while ($row = $result->fetch_assoc()) {
        $all_employees[] = $row;
    }
    
    // MODIFIED: Categorize employees by department using hierarchy
    $executive_employees = [];
    $technical_employees = [];
    $admin_employees = [];
    
    foreach ($all_employees as $emp) {
        $dept = getDepartmentFromPosition($emp['position']);
        
        // Categorize by department - only three categories now
        if ($dept == 'Executive') {
            $executive_employees[] = $emp;
        }
        elseif ($dept == 'Admin') {
            $admin_employees[] = $emp;
        }
        else { // Technical (including any default)
            $technical_employees[] = $emp;
        }
    }
    
    // MODIFIED: Summary Statistics - only three categories
    $total_count = count($all_employees);
    $executive_count = count($executive_employees);
    $technical_count = count($technical_employees);
    $admin_count = count($admin_employees);
    
    echo '<div class="summary">';
    echo '<table>';
    echo '<tr>';
    echo '<td><span class="label">TOTAL EMPLOYEES</span><br><span class="number">' . $total_count . '</span></td>';
    echo '<td><span class="label">EXECUTIVE</span><br><span class="number" style="color: #8B4513;">' . $executive_count . '</span></td>';
    echo '<td><span class="label">TECHNICAL</span><br><span class="number" style="color: #2E7D32;">' . $technical_count . '</span></td>';
    echo '<td><span class="label">ADMIN</span><br><span class="number" style="color: #1565C0;">' . $admin_count . '</span></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    // ==================== EXECUTIVE CATEGORY ====================
    if ($executive_count > 0) {
        echo '<div class="category-section">';
        echo '<div class="category-header executive-bg">';
        echo 'EXECUTIVE DEPARTMENT';
        echo '</div>';
        echo '<div class="category-summary">Total Executive Employees: ' . $executive_count . '</div>';
        
        // COMBINE ALL EXECUTIVE EMPLOYEES INTO ONE TABLE
        if (!empty($executive_employees)) {
            displayEmployeeTable($executive_employees);
        }
        
        echo '</div>'; // Close executive section
        echo '<div style="height: 20px; border-bottom: 3px solid #8B4513; margin: 20px 0;"></div>';
    }
    
    // ==================== TECHNICAL CATEGORY ====================
    if ($technical_count > 0) {
        echo '<div class="category-section">';
        echo '<div class="category-header technical-bg">';
        echo 'TECHNICAL DEPARTMENT';
        echo '</div>';
        echo '<div class="category-summary">Total Technical Employees: ' . $technical_count . '</div>';
        
        // COMBINE ALL TECHNICAL EMPLOYEES INTO ONE TABLE
        if (!empty($technical_employees)) {
            displayEmployeeTable($technical_employees);
        }
        
        echo '</div>'; // Close technical section
        echo '<div style="height: 20px; border-bottom: 3px solid #2E7D32; margin: 20px 0;"></div>';
    }
    
    // ==================== ADMIN CATEGORY ====================
    if ($admin_count > 0) {
        echo '<div class="category-section">';
        echo '<div class="category-header admin-bg">';
        echo 'ADMINISTRATIVE DEPARTMENT';
        echo '</div>';
        echo '<div class="category-summary">Total Administrative Employees: ' . $admin_count . '</div>';
        
        // COMBINE ALL ADMIN EMPLOYEES INTO ONE TABLE
        if (!empty($admin_employees)) {
            displayEmployeeTable($admin_employees);
        }
        
        echo '</div>'; // Close admin section
    }
    
    // Footer
    echo '<div class="footer">';
    echo '<strong>Generated on:</strong> ' . date('F d, Y h:i:s A') . '<br>';
    echo '<strong>Generated by:</strong> ' . $_SESSION['Admin_User'] . '<br>';
    echo '<strong>Report ID:</strong> EMP-CAT-' . date('Ymd-His');
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    
    exit;
}

// Handle Download Single Employee as Word
if (isset($_GET['download_word']) && isset($_GET['employee_id'])) {
    $employee_id = $_GET['employee_id'];
    
    // MODIFIED: Changed to ASCENDING order for consistency
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    
    if (!$employee) {
        header("Location: employee.php?error=employee_not_found");
        exit;
    }
    
    // Set headers for Word download
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Employee_' . $employee['id'] . '_' . $employee['first_name'] . '_' . $employee['last_name'] . '.doc"');
    header('Cache-Control: max-age=0');
    
    // Create Word document content
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Employee Information - ' . $employee['first_name'] . ' ' . $employee['last_name'] . '</title>';
    echo '<style>
        body { 
            font-family: "Times New Roman", Times, serif; 
            margin: 0.5in;
        }
        h1 { 
            color: #2E7D32; 
            font-size: 24pt;
            text-align: center;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }
        .subtitle {
            text-align: center;
            font-size: 14pt;
            margin-bottom: 25px;
            border-bottom: 2px solid #2E7D32;
            padding-bottom: 10px;
        }
        .header-info {
            background-color: #f0f7f0;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #2E7D32;
            border-radius: 5px;
        }
        h2 {
            color: #2E7D32;
            font-size: 16pt;
            border-left: 5px solid #2E7D32;
            padding-left: 10px;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        .label {
            background-color: #f0f7f0;
            font-weight: bold;
            width: 35%;
        }
        .value {
            width: 65%;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 10pt;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .employment-type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10pt;
            font-weight: 600;
            margin-left: 10px;
        }
        .employment-type-badge.regular {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .employment-type-badge.non-regular {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .age-value {
            color: #2E7D32;
            font-weight: bold;
        }
    </style>';
    echo '</head>';
    echo '<body>';
    
    // Header
    echo '<h1>JLC PAYROLL SYSTEM</h1>';
    echo '<div class="subtitle">Employee Information Sheet • <strong>Official Document</strong></div>';
    
    // Employee Name with Employment Type
    $employment_type = $employee['employment_type'] ?? 'regular';
    $emp_type_class = $employment_type == 'regular' ? 'regular' : 'non-regular';
    $emp_type_label = $employment_type == 'regular' ? 'Regular' : 'Non Regular';
    
    echo '<div class="header-info">';
    echo '<strong>Employee: </strong>' . strtoupper($employee['first_name'] . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . $employee['last_name']);
    echo ' <span class="employment-type-badge ' . $emp_type_class . '">' . $emp_type_label . '</span>';
    echo ' | <strong>ID: </strong>' . $employee['id'];
    echo ' | <strong>Date: </strong>' . date('F d, Y');
    echo '</div>';
    
    // Personal Information
    $age = calculateAge($employee['dob'] ?? '');
    
    echo '<h2>PERSONAL INFORMATION</h2>';
    echo '<table>';
    echo '<tr><td class="label">Full Name:</td><td class="value">' . $employee['first_name'] . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . $employee['last_name'] . '</td></tr>';
    echo '<tr><td class="label">Date of Birth:</td><td class="value">' . (formatDateForDisplay($employee['dob']) ?: 'Not specified') . '</td></tr>';
    echo '<tr><td class="label">Age:</td><td class="value"><span class="age-value">' . ($age ? $age . ' years old' : 'Not specified') . '</span></td></tr>';
    echo '<tr><td class="label">Gender:</td><td class="value">' . $employee['gender'] . '</td></tr>';
    echo '<tr><td class="label">Civil Status:</td><td class="value">' . $employee['civil_status'] . '</td></tr>';
    echo '<tr><td class="label">Address:</td><td class="value">' . $employee['address'] . '</td></tr>';
    echo '</table>';
    
    // Employment Details
    echo '<h2>EMPLOYMENT DETAILS</h2>';
    echo '<table>';
    echo '<tr><td class="label">Position:</td><td class="value">' . str_replace(', ', ' / ', $employee['position']) . '</td></tr>';
    echo '<tr><td class="label">Date Hired:</td><td class="value">' . (formatDateForDisplay($employee['date_hired']) ?: 'Not specified') . '</td></tr>';
    echo '<tr><td class="label">Daily Salary:</td><td class="value">₱ ' . number_format($employee['daily_salary'], 2) . '</td></tr>';
    echo '<tr><td class="label">Monthly Salary:</td><td class="value">₱ ' . number_format($employee['monthly_salary'] ?? 0, 2) . '</td></tr>';
    echo '<tr><td class="label">Email Address:</td><td class="value">' . ($employee['email'] ?: 'Not provided') . '</td></tr>';
    echo '<tr><td class="label">Contact Number:</td><td class="value">' . $employee['contact_num'] . '</td></tr>';
    echo '<tr><td class="label">Employment Type:</td><td class="value">' . $emp_type_label . '</td></tr>';
    echo '<tr><td class="label">Status:</td><td class="value">' . ucfirst($employee['status'] ?? 'active') . '</td></tr>';
    echo '</table>';
    
    // Government IDs (if any)
    if (!empty($employee['sss_number']) || !empty($employee['pagibig_number']) || !empty($employee['tin_number']) || !empty($employee['philhealth_number'])) {
        echo '<h2>GOVERNMENT IDENTIFICATION NUMBERS</h2>';
        echo '<table>';
        if (!empty($employee['sss_number'])) {
            echo '<tr><td class="label">SSS Number:</td><td class="value">' . $employee['sss_number'] . '</td></tr>';
        }
        if (!empty($employee['pagibig_number'])) {
            echo '<tr><td class="label">PAG-IBIG Number:</td><td class="value">' . $employee['pagibig_number'] . '</td></tr>';
        }
        if (!empty($employee['tin_number'])) {
            echo '<tr><td class="label">TIN Number:</td><td class="value">' . $employee['tin_number'] . '</td></tr>';
        }
        if (!empty($employee['philhealth_number'])) {
            echo '<tr><td class="label">PhilHealth Number:</td><td class="value">' . $employee['philhealth_number'] . '</td></tr>';
        }
        echo '</table>';
    }
    
    // Footer
    echo '<div class="footer">';
    echo 'Generated on: ' . date('F d, Y h:i:s A') . ' | ';
    echo 'Generated by: ' . $_SESSION['Admin_User'] . ' | ';
    echo 'Report ID: EMP-' . date('Ymd') . '-' . $employee['id'];
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    
    exit;
}

// Handle Add Employee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_employee'])) {
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $address = $_POST['address'];
    $contact_num = $_POST['contact_num'];
    $position = $_POST['position'];
    $email = $_POST['email'] ?? '';
    $gender = $_POST['gender'];
    $civil_status = $_POST['civil_status'];
    $date_hired = !empty($_POST['date_hired']) ? $_POST['date_hired'] : null;
    $daily_salary = $_POST['daily_salary'];
    $monthly_salary = $_POST['monthly_salary'];
    $dob = $_POST['dob'];
    $sss_number = $_POST['sss_number'] ?? '';
    $pagibig_number = $_POST['pagibig_number'] ?? '';
    $tin_number = $_POST['tin_number'] ?? '';
    $philhealth_number = $_POST['philhealth_number'] ?? '';
    $employment_type = $_POST['employment_type'] ?? 'regular';

    // FIX: Sanitize dates
    $dob = sanitizeDate($dob);
    $date_hired = $date_hired ? sanitizeDate($date_hired) : null;

    // Validate date fields
    if (!$dob) {
        echo "<script>alert('Invalid Date of Birth. Please select a valid date.'); window.history.back();</script>";
        exit();
    }

    // Use prepared statement to prevent SQL injection
    $sql = "INSERT INTO employees (first_name, middle_name, last_name, address, contact_num, position, email, gender, civil_status, date_hired, daily_salary, monthly_salary, dob, sss_number, pagibig_number, tin_number, philhealth_number, employment_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssssssssss", 
        $first_name, $middle_name, $last_name, $address, $contact_num, $position, $email, 
        $gender, $civil_status, $date_hired, $daily_salary, $monthly_salary, $dob, 
        $sss_number, $pagibig_number, $tin_number, $philhealth_number, $employment_type
    );
    
    if ($stmt->execute()) {
        echo "<script>
            localStorage.setItem('toastMessage', 'Employee added successfully!');
            localStorage.setItem('toastType', 'success');
            window.location.href='employee.php';
        </script>";
    } else {
        echo "<script>
            localStorage.setItem('toastMessage', 'Error adding employee: " . addslashes($conn->error) . "');
            localStorage.setItem('toastType', 'error');
            window.location.href='employee.php';
        </script>";
    }
    exit();
}

// Handle Delete Employee - Now handled via AJAX, keeping for backward compatibility
if (isset($_GET['delete']) && isset($_GET['employee_id'])) {
    $employee_id = $_GET['employee_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First delete from site_employee if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'site_employee'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt1 = $conn->prepare("DELETE FROM site_employee WHERE employee_id = ?");
            $stmt1->bind_param("i", $employee_id);
            $stmt1->execute();
        }
        
        // Delete from deduction if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'deduction'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt2 = $conn->prepare("DELETE FROM deduction WHERE employee_id = ?");
            $stmt2->bind_param("i", $employee_id);
            $stmt2->execute();
        }
        
        // Delete from attendance if exists
        $table_check = $conn->query("SHOW TABLES LIKE 'attendance'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt4 = $conn->prepare("DELETE FROM attendance WHERE employee_id = ?");
            $stmt4->bind_param("i", $employee_id);
            $stmt4->execute();
        }
        
        // Finally delete the employee
        $stmt3 = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt3->bind_param("i", $employee_id);
        $stmt3->execute();
        
        $conn->commit();
        
        echo "<script>
            localStorage.setItem('toastMessage', 'Employee deleted successfully!');
            localStorage.setItem('toastType', 'success');
            window.location.href='employee.php';
        </script>";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>
            localStorage.setItem('toastMessage', 'Error deleting employee: " . addslashes($e->getMessage()) . "');
            localStorage.setItem('toastType', 'error');
            window.location.href='employee.php';
        </script>";
    }
    exit();
}

// Handle Toggle Status (Enable/Disable Employee) - Now handled via AJAX, keeping for backward compatibility
if (isset($_GET['toggle_status']) && isset($_GET['employee_id'])) {
    $employee_id = $_GET['employee_id'];
    $current_status = $_GET['status'];

    // Toggle the employee status between active and inactive
    $new_status = ($current_status === 'active') ? 'inactive' : 'active';

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update the employee's status
        $stmt = $conn->prepare("UPDATE employees SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $employee_id);
        $stmt->execute();
        
        // If the employee is disabled, disable all their deductions
        if ($new_status === 'inactive') {
            // Check if deduction table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'deduction'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt2 = $conn->prepare("UPDATE deduction SET status = 'disabled' WHERE employee_id = ?");
                $stmt2->bind_param("i", $employee_id);
                $stmt2->execute();
            }
            
            // Check if site_employee table exists and remove employee from site assignments
            $table_check = $conn->query("SHOW TABLES LIKE 'site_employee'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt3 = $conn->prepare("DELETE FROM site_employee WHERE employee_id = ?");
                $stmt3->bind_param("i", $employee_id);
                $stmt3->execute();
            }
        }
        
        $conn->commit();
        
        $action = $new_status === 'active' ? 'enabled' : 'disabled';
        echo "<script>
            localStorage.setItem('toastMessage', 'Employee " . $action . " successfully!');
            localStorage.setItem('toastType', 'success');
            window.location.href='employee.php';
        </script>";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>
            localStorage.setItem('toastMessage', 'Error updating employee status: " . addslashes($e->getMessage()) . "');
            localStorage.setItem('toastType', 'error');
            window.location.href='employee.php';
        </script>";
    }
    exit();
}

// Fetch Employee Details for View
if (isset($_GET['view'])) {
    $id = $_GET['view'];
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $view_result = $stmt->get_result();
    $employee = $view_result->fetch_assoc();
}

// Fetch Employee Details for Edit
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    $employee_edit = $edit_result->fetch_assoc();
}

// Handle Update Employee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_employee'])) {
    $id = $_POST['id'];
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $address = $_POST['address'];
    $contact_num = $_POST['contact_num'];
    $position = $_POST['position'];
    $email = $_POST['email'] ?? '';
    $gender = $_POST['gender'];
    $civil_status = $_POST['civil_status'];
    $date_hired = !empty($_POST['date_hired']) ? $_POST['date_hired'] : null;
    $daily_salary = $_POST['daily_salary'];
    $monthly_salary = $_POST['monthly_salary'];
    $dob = $_POST['dob'];
    $sss_number = $_POST['sss_number'] ?? '';
    $pagibig_number = $_POST['pagibig_number'] ?? '';
    $tin_number = $_POST['tin_number'] ?? '';
    $philhealth_number = $_POST['philhealth_number'] ?? '';
    $employment_type = $_POST['employment_type'] ?? 'regular';

    // FIX: Sanitize dates to prevent just "2008" from being inserted
    $dob = sanitizeDate($dob);
    $date_hired = $date_hired ? sanitizeDate($date_hired) : null;

    // Validate date fields
    if (!$dob) {
        echo "<script>alert('Invalid Date of Birth. Please select a valid date.'); window.history.back();</script>";
        exit();
    }

    $sql = "UPDATE employees SET 
            first_name=?, middle_name=?, last_name=?, address=?, 
            contact_num=?, position=?, email=?, gender=?, civil_status=?, 
            date_hired=?, daily_salary=?, monthly_salary=?, dob=?, sss_number=?, 
            pagibig_number=?, tin_number=?, philhealth_number=?, employment_type=? 
            WHERE id=?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssssssssssi", 
        $first_name, $middle_name, $last_name, $address, 
        $contact_num, $position, $email, $gender, $civil_status, 
        $date_hired, $daily_salary, $monthly_salary, $dob, $sss_number, 
        $pagibig_number, $tin_number, $philhealth_number, $employment_type, $id
    );

    if ($stmt->execute()) {
        echo "<script>
            localStorage.setItem('toastMessage', 'Employee updated successfully!');
            localStorage.setItem('toastType', 'success');
            window.location.href='employee.php';
        </script>";
    } else {
        echo "<script>
            localStorage.setItem('toastMessage', 'Error updating employee: " . addslashes($conn->error) . "');
            localStorage.setItem('toastType', 'error');
            window.location.href='employee.php';
        </script>";
    }
    exit();
}

// First, check if the new columns exist in the table, if not add them
$check_columns = $conn->query("SHOW COLUMNS FROM employees LIKE 'sss_number'");
if ($check_columns && $check_columns->num_rows == 0) {
    $alter_sql = "ALTER TABLE employees 
                  ADD COLUMN sss_number VARCHAR(50) NULL,
                  ADD COLUMN pagibig_number VARCHAR(50) NULL,
                  ADD COLUMN tin_number VARCHAR(50) NULL,
                  ADD COLUMN philhealth_number VARCHAR(50) NULL";
    $conn->query($alter_sql);
}

// Check if monthly_salary column exists, if not add it
$check_monthly_salary = $conn->query("SHOW COLUMNS FROM employees LIKE 'monthly_salary'");
if ($check_monthly_salary && $check_monthly_salary->num_rows == 0) {
    $conn->query("ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) NULL AFTER daily_salary");
}

// Check if status column exists, if not add it
$check_status = $conn->query("SHOW COLUMNS FROM employees LIKE 'status'");
if ($check_status && $check_status->num_rows == 0) {
    $conn->query("ALTER TABLE employees ADD COLUMN status ENUM('active','inactive','disabled') DEFAULT 'active'");
}

// Check if employment_type column exists, if not add it
$check_employment_type = $conn->query("SHOW COLUMNS FROM employees LIKE 'employment_type'");
if ($check_employment_type && $check_employment_type->num_rows == 0) {
    $conn->query("ALTER TABLE employees ADD COLUMN employment_type ENUM('regular', 'non_regular') NOT NULL DEFAULT 'regular' AFTER philhealth_number");
}

// Check if positions table exists, if not create it
$check_positions_table = $conn->query("SHOW TABLES LIKE 'positions'");
if ($check_positions_table && $check_positions_table->num_rows == 0) {
    $create_positions_sql = "CREATE TABLE IF NOT EXISTS `positions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `position_name` varchar(100) NOT NULL,
        `category` enum('executive','technical','admin') NOT NULL DEFAULT 'technical',
        `is_custom` tinyint(1) DEFAULT '0',
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `position_name` (`position_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_positions_sql);
    
    // Insert default positions
    $default_positions = [
        ['Chief Executive Officer', 'executive', 0],
        ['General Manager', 'executive', 0],
        ['Sales and Marketing Manager', 'executive', 0],
        ['Technical Director', 'technical', 0],
        ['Design Department Head', 'technical', 0],
        ['Design Engineer', 'technical', 0],
        ['Design Technical Engineer', 'technical', 0],
        ['CAD operator', 'technical', 0],
        ['Implementation', 'technical', 0],
        ['Technical Department Head', 'technical', 0],
        ['Site Engineer', 'technical', 0],
        ['Safety Officer 2', 'technical', 0],
        ['Lead man', 'technical', 0],
        ['Electrician', 'technical', 0],
        ['Carpenter', 'technical', 0],
        ['Welder', 'technical', 0],
        ['Electronics', 'technical', 0],
        ['Painter', 'technical', 0],
        ['Scaffolder', 'technical', 0],
        ['Safety Officer Department Head', 'technical', 0],
        ['Admin', 'admin', 0],
        ['Purchasing', 'admin', 0],
        ['HR Manager', 'admin', 0],
        ['Finance and Administrative Officer', 'admin', 0],
        ['Maintenance', 'admin', 0]
    ];
    
    foreach ($default_positions as $pos) {
        $stmt = $conn->prepare("INSERT IGNORE INTO positions (position_name, category, is_custom) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $pos[0], $pos[1], $pos[2]);
        $stmt->execute();
    }
}

// MODIFIED: Fetch employees based on search query - ASCENDING order
$search_query = "";
if (isset($_GET['search'])) {
    $search_query = $conn->real_escape_string($_GET['search']);
    $result = $conn->query("SELECT * FROM employees WHERE 
                            first_name LIKE '%$search_query%' OR 
                            middle_name LIKE '%$search_query%' OR 
                            last_name LIKE '%$search_query%' OR
                            id LIKE '%$search_query%' OR
                            position LIKE '%$search_query%'
                            ORDER BY id ASC"); // Changed from DESC to ASC
} else {
    $result = $conn->query("SELECT * FROM employees ORDER BY id ASC"); // Changed from DESC to ASC
}

// Get total count for display
$total_employees = $conn->query("SELECT COUNT(*) as total FROM employees")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management</title>
    <link rel="stylesheet" href="./assets/css/employee.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Additional styles specific to employee.php */
        .govt-id-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background-color: #e3f2fd;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            margin: 3px;
        }
        
        .govt-id-badge i {
            color: var(--info-color);
        }
        
        .govt-id-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        /* Adjust for footer */
        .content {
            padding-bottom: 80px;
        }
        
        /* Ensure proper header height on page load */
        body {
            padding-top: var(--header-height) !important;
        }
        
        /* Action buttons container */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-start;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            color: white;
        }

        .action-btn.view-btn {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .action-btn.view-btn:hover {
            background: linear-gradient(135deg, #2980b9, #1c6ea4);
            transform: translateY(-2px);
        }

        .action-btn.edit-btn {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .action-btn.edit-btn:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-2px);
        }

        .action-btn.word-small-btn {
            background: linear-gradient(135deg, #2B5797, #1E3F6E);
        }

        .action-btn.word-small-btn:hover {
            background: linear-gradient(135deg, #1E3F6E, #0F2A4A);
            transform: translateY(-2px);
        }
        
        /* Toggle status button styles */
        .toggle-status-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
            background-color: #fb0202;
            color: white;
        }
        
        .toggle-status-btn:hover {
            background-color: #781c1c;
            transform: translateY(-1px);
        }
        
        .toggle-status-btn.inactive {
            background-color: #27ae60;
        }
        
        .toggle-status-btn.inactive:hover {
            background-color: #219a52;
        }
        
        /* Delete button styles */
        .delete-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
            background-color: #dc3545;
            color: white;
        }
        
        .delete-btn:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }
        
        .delete-btn i {
            font-size: 0.9rem;
        }
        
        /* Word Download button styles (BLUE) */
        .word-download-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
            background: linear-gradient(135deg, #2B5797, #1E3F6E);
            color: white;
        }
        
        .word-download-btn:hover {
            background: linear-gradient(135deg, #1E3F6E, #0F2A4A);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(43, 87, 151, 0.3);
        }
        
        .word-download-all-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
            background: linear-gradient(135deg, #2B5797, #1E3F6E);
            color: white;
            margin-left: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .word-download-all-btn:hover {
            background: linear-gradient(135deg, #1E3F6E, #0F2A4A);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(43, 87, 151, 0.3);
        }
        
        .word-download-all-btn i {
            font-size: 1rem;
        }
        
        /* Small Word button in actions */
        .action-btn.word-small-btn {
            background: linear-gradient(135deg, #2B5797, #1E3F6E);
        }
        
        .action-btn.word-small-btn:hover {
            background: linear-gradient(135deg, #1E3F6E, #0F2A4A);
            transform: translateY(-2px);
        }
        
        /* Status badge styles */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-inactive {
            background-color: #ffebee;
            color: #c62828;
        }
        
        /* Employment Type Badge styles */
        .employment-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .employment-type-badge.regular {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .employment-type-badge.non-regular {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Government ID section in form */
        .govt-id-section {
            grid-column: 1 / -1;
            background: #f8fff8;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 15px;
            border-left: 4px solid var(--accent-green);
        }
        
        .govt-id-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .govt-id-section h4 {
            color: var(--accent-green);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .govt-id-section small {
            display: block;
            margin-top: 4px;
        }
        
        /* Form sections */
        .form-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
        }
        
        .form-section h4 {
            color: var(--sidebar-dark-green);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .employee-details {
            display: grid;
            gap: 15px;
        }
        
        .employee-details .form-group {
            display: grid;
            grid-template-columns: 150px 1fr;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .employee-details .form-group label {
            font-weight: 600;
            color: var(--sidebar-dark-green);
        }
        
        .employee-details .form-group p {
            margin: 0;
            color: #333;
        }

        /* Age field styling */
        .age-field {
            background: linear-gradient(135deg, #f5f5f5, #e8e8e8);
            color: #2E7D32;
            font-weight: 600;
            cursor: default;
            border: 2px solid #c8e6c9;
            width: 100%;
            padding: 10px 15px 10px 12px;
            border-radius: 8px;
            font-size: 0.95rem;
            height: 42px;
        }

        /* ============ CALENDAR DATE PICKER STYLES ============ */
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
            padding: 10px 15px 10px 12px;
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
        
        .date-field:hover {
            border-color: #75e6da;
        }
        
        .date-field:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
            outline: none;
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
            font-size: 0.9rem;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .calendar-dropdown-btn:hover {
            color: #75e6da;
        }
        
        /* Calendar Dropdown Styles */
        .calendar-wrapper {
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
            width: 100%;
            min-width: 300px;
        }
        
        .calendar-wrapper.show {
            display: block;
        }
        
        .calendar-box {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-header {
            background: linear-gradient(135deg, #75e6da, #62d4c8);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .calendar-month-year {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .calendar-nav-btn {
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
        
        .calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .calendar-selectors {
            display: flex;
            gap: 10px;
            padding: 15px 15px 5px 15px;
            background: white;
        }
        
        .calendar-select {
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
        
        .calendar-select:hover {
            border-color: #75e6da;
        }
        
        .calendar-select:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(46, 125, 139, 0.1);
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        .calendar-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 10px;
            background: white;
        }
        
        .calendar-day {
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
        
        .calendar-day:hover {
            background: #e8f5e9;
            color: #62d4c8;
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
        
        .calendar-day.weekend {
            color: #e74c3c;
        }
        
        .calendar-day.other-month {
            color: #bdc3c7;
        }
        
        .calendar-footer {
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }
        
        .calendar-action-btn {
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
        
        .calendar-action-btn.clear {
            background: #f8f9fa;
            color: #7f8c8d;
            border: 1px solid #bdc3c7;
        }
        
        .calendar-action-btn.clear:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
        }
        
        .calendar-action-btn.today {
            background: #75e6da;
            color: white;
            border: 1px solid #75e6da;
        }
        
        .calendar-action-btn.today:hover {
            background: #62d4c8;
        }

        /* Date validation warning */
        .date-warning {
            color: #e74c3c;
            font-size: 0.8rem;
            margin-top: 4px;
            display: none;
        }

        .date-input.invalid {
            border-color: #e74c3c !important;
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            min-width: 300px;
            max-width: 400px;
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }

        .toast.success {
            border-left-color: #28a745;
        }

        .toast.success i {
            color: #28a745;
        }

        .toast.error {
            border-left-color: #dc3545;
        }

        .toast.error i {
            color: #dc3545;
        }

        .toast.warning {
            border-left-color: #ffc107;
        }

        .toast.warning i {
            color: #ffc107;
        }

        .toast.info {
            border-left-color: #17a2b8;
        }

        .toast.info i {
            color: #17a2b8;
        }

        .toast i {
            font-size: 1.2rem;
        }

        .toast-content {
            flex: 1;
            font-size: 0.95rem;
            color: #333;
        }

        .toast-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            transition: color 0.3s;
        }

        .toast-close:hover {
            color: #333;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(100%);
                opacity: 1;
            }
            to {
                transform: translateX(0);
                opacity: 0;
            }
        }

        /* New Position Selection Styles - Enhanced */
        .position-selection-container {
            margin-top: 10px;
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            overflow: hidden;
            background: white;
        }

        .select-position-btn {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border: none;
            padding: 12px 15px;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .select-position-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .select-position-btn i {
            font-size: 1rem;
        }

        .position-tag {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: 30px;
            font-size: 0.9rem;
            color: #2e7d32;
            margin: 4px;
            border: 1px solid #a5d6a7;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: var(--transition);
            max-width: 100%;
            word-break: break-word;
            line-height: 1.4;
            white-space: normal;
            text-align: left;
        }

        .position-tag:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }

        .position-tag i {
            color: #2e7d32;
            cursor: pointer;
            font-size: 0.9rem;
            opacity: 0.7;
            transition: var(--transition);
        }

        .position-tag i:hover {
            opacity: 1;
            color: #c62828;
            transform: scale(1.1);
        }

        .position-tag .delete-position {
            color: #c62828;
        }

        .selected-positions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            padding: 15px;
            background-color: #f8fff8;
            border-radius: var(--border-radius);
            min-height: 60px;
            border: 2px dashed #c8e6c9;
            max-height: 300px;
            overflow-y: auto;
        }

        .category-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .category-btn {
            flex: 1;
            padding: 12px 15px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            background-color: #f0f0f0;
            color: #333;
            min-width: 100px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .category-btn.active {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .category-btn:hover:not(.active) {
            background-color: #e0e0e0;
            transform: translateY(-1px);
        }

        .positions-container {
            padding: 20px;
            background: linear-gradient(135deg, #f8fff8, #f0f9f0);
            border-radius: var(--border-radius);
            min-height: 250px;
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            margin-bottom: 15px;
        }

        .position-checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            background: white;
            margin-bottom: 2px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .position-checkbox-item:hover {
            background-color: #f0f9f0;
            transform: translateX(5px);
        }

        .position-checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--sidebar-green);
        }

        .position-checkbox-item label {
            font-size: 0.95rem;
            color: #333;
            cursor: pointer;
            flex: 1;
            font-weight: 500;
            word-break: break-word;
        }

        /* Add Custom Position Button - Now inside each category */
        .add-custom-position-btn {
            background: linear-gradient(135deg, #75e6da, #62d4c8);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 15px auto 5px;
            box-shadow: 0 2px 4px rgba(117, 230, 218, 0.3);
            width: fit-content;
        }

        .add-custom-position-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(117, 230, 218, 0.4);
        }

        .custom-position-input {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid #e0e0e0;
        }

        .custom-position-input input {
            flex: 1;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .custom-position-input input:focus {
            outline: none;
            border-color: var(--sidebar-green);
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
        }

        .custom-position-input button {
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .custom-position-input button:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .position-group {
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .position-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--sidebar-green), var(--sidebar-dark-green));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .position-group-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .position-group-header button {
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
            transition: var(--transition);
        }

        .position-group-header button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .position-group-content {
            padding: 15px;
            background: white;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 3rem;
            color: #d1f0eb;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: 1rem;
            color: #95a5a6;
        }

        .modal {
            z-index: 10000;
        }
        
        #positionModal, #editPositionModal, #deleteConfirmModal, #statusConfirmModal {
            z-index: 10001;
        }

        /* Custom Positions Section - Now Inside Modal */
        .custom-positions-section {
            margin-top: 20px;
            padding: 15px;
            background: #f8fff8;
            border-radius: var(--border-radius);
            border: 1px solid #e0e0e0;
        }

        .custom-positions-section h6 {
            color: var(--sidebar-dark-green);
            margin-bottom: 10px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .custom-positions-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            max-height: 150px;
            overflow-y: auto;
            padding: 10px;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid #e0e0e0;
        }

        .custom-position-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #e1f5fe, #b3e5fc);
            border-radius: 20px;
            font-size: 0.85rem;
            color: #0277bd;
            border: 1px solid #81d4fa;
            word-break: break-word;
            max-width: 100%;
        }

        .custom-position-tag i {
            cursor: pointer;
            color: #0277bd;
            opacity: 0.7;
        }

        .custom-position-tag i:hover {
            opacity: 1;
            color: #c62828;
        }

        /* Delete position button inside modal */
        .position-checkbox-item .delete-position-btn {
            margin-left: auto;
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
        }

        .position-checkbox-item .delete-position-btn:hover {
            background-color: #ffebee;
            transform: scale(1.1);
        }

        .position-checkbox-item .delete-position-btn i {
            font-size: 1rem;
        }

        /* Header with total count and download button */
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 0 20px;
        }

        .total-count {
            font-size: 0.95rem;
            color: #64748b;
            background: #f8fafc;
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
        }

        .total-count i {
            color: #2E7D32;
            margin-right: 6px;
        }

        .total-count strong {
            color: #2E7D32;
            font-size: 1.1rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .word-download-all-btn {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                gap: 4px;
            }
        }

        /* Confirmation Modal Styles */
        .confirm-modal {
            display: none;
            position: fixed;
            z-index: 10001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .confirm-modal-content {
            background-color: #fff;
            margin: auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.3s;
        }

        .confirm-modal-header {
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .confirm-modal-header.warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .confirm-modal-header.danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .confirm-modal-header.success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
        }

        .confirm-modal-header i {
            font-size: 2rem;
            color: white;
        }

        .confirm-modal-header h3 {
            color: white;
            margin: 0;
            font-size: 1.4rem;
        }

        .confirm-modal-body {
            padding: 25px;
            color: #333;
        }

        .confirm-modal-body p {
            margin: 10px 0;
            font-size: 1rem;
            line-height: 1.5;
        }

        .confirm-modal-body .employee-name {
            font-weight: bold;
            color: #2c3e50;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
        }

        .confirm-modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .confirm-modal-footer .btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
        }

        .confirm-modal-footer .btn-cancel {
            background: #ecf0f1;
            color: #7f8c8d;
        }

        .confirm-modal-footer .btn-cancel:hover {
            background: #bdc3c7;
            transform: translateY(-1px);
        }

        .confirm-modal-footer .btn-confirm-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }

        .confirm-modal-footer .btn-confirm-warning:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-1px);
        }

        .confirm-modal-footer .btn-confirm-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        .confirm-modal-footer .btn-confirm-danger:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
            transform: translateY(-1px);
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="confirm-modal">
        <div class="confirm-modal-content">
            <div class="confirm-modal-header danger">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Confirm Delete</h3>
            </div>
            <div class="confirm-modal-body">
                <p>Are you sure you want to permanently delete employee:</p>
                <div class="employee-name" id="deleteEmployeeName"></div>
                <p style="color: #e74c3c; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> This action cannot be undone and will remove all associated records.
                </p>
            </div>
            <div class="confirm-modal-footer">
                <button class="btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-confirm-danger" id="confirmDeleteBtn">Delete Permanently</button>
            </div>
        </div>
    </div>

    <!-- Status Toggle Confirmation Modal -->
    <div id="statusConfirmModal" class="confirm-modal">
        <div class="confirm-modal-content">
            <div class="confirm-modal-header warning" id="statusModalHeader">
                <i class="fas fa-user-slash"></i>
                <h3 id="statusModalTitle">Confirm Disable</h3>
            </div>
            <div class="confirm-modal-body">
                <p id="statusModalMessage">Are you sure you want to disable employee:</p>
                <div class="employee-name" id="statusEmployeeName"></div>
                <p id="statusModalWarning" style="color: #e67e22; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> This will deactivate their account and remove them from any assigned sites.
                </p>
            </div>
            <div class="confirm-modal-footer">
                <button class="btn btn-cancel" onclick="closeStatusModal()">Cancel</button>
                <button class="btn btn-confirm-warning" id="confirmStatusBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Header with Navigation -->
    <?php include_once("./includes/header.php"); ?>

    <main class="content">
        <div class="content-wrapper">
            <!-- Controls Container with Search and Add Button -->
            <div class="controls-container">
                <div class="search-section">
                    <div class="search-container">
                        <form method="GET" action="employee.php" style="display: flex; align-items: center; width: 100%;">
                            <input type="text" name="search" 
                                   value="<?php echo htmlspecialchars($search_query); ?>" 
                                   placeholder="Search employees by name, ID, or position..." 
                                   class="search-bar">
                            <button type="submit" class="search-btn">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button class="add-employee-btn" onclick="showAddEmployeeModal()">
                        <i class="fas fa-user-plus"></i>
                        Add Employee
                    </button>
                    
                    <!-- Word Download All Button (BLUE) -->
                    <a href="employee.php?download_all_word=1" class="word-download-all-btn">
                        <i class="fas fa-file-word"></i>
                        Download All Word (<?php echo $total_employees; ?>)
                    </a>
                </div>
            </div>

            <!-- Employee Table - SIMPLIFIED -->
            <div class="table-responsive">
                <div class="employee-table-container">
                    <table class="employee-table">
                        <thead>
                            <tr>
                                <th>Employee Information</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $employment_type = $row['employment_type'] ?? 'regular';
                                $emp_type_class = $employment_type == 'regular' ? 'regular' : 'non-regular';
                                $emp_type_label = $employment_type == 'regular' ? 'Regular' : 'Non Regular';
                                $age = calculateAge($row['dob'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <div class="employee-info">
                                        <!-- SIMPLIFIED: Only Name, ID, Age, Position, Contact, Daily Salary -->
                                        <div><strong>Name:</strong> <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></div>
                                        <div><strong>ID:</strong> <?php echo $row['id']; ?></div>
                                        <div><strong>Age:</strong> 
                                            <?php if ($age): ?>
                                                <span style="color: #2E7D32; font-weight: 600;"><?php echo $age; ?> years old</span>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </div>
                                        <div><strong>Position:</strong> <span class="position-display"><?php echo str_replace(', ', ' / ', htmlspecialchars($row['position'])); ?></span></div>
                                        <div><strong>Contact:</strong> <?php echo htmlspecialchars($row['contact_num']); ?></div>
                                        <div><strong>Daily Salary:</strong> ₱<?php echo number_format($row['daily_salary'], 2); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo ($row['status'] ?? 'active') == 'active' ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                                        <?php echo ucfirst($row['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <!-- View Button -->
                                        <button class='action-btn view-btn' 
                                                onclick='showViewEmployeeModal(<?php echo $row['id']; ?>)'
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <!-- Edit Button -->
                                        <button class='action-btn edit-btn' 
                                                onclick='showEditEmployeeModal(<?php echo $row['id']; ?>)'
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- Word Download Button (BLUE) for Single Employee -->
                                        <a href='employee.php?download_word=1&employee_id=<?php echo $row['id']; ?>' 
                                           class='action-btn word-small-btn' 
                                           title="Download Employee Information as Word Document">
                                            <i class="fas fa-file-word"></i>
                                        </a>

                                        <!-- Delete Button -->
                                        <button class='delete-btn' 
                                                onclick='showDeleteModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>")'
                                                title="Delete Employee">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>

                                        <!-- Toggle Status Button -->
                                        <button class='toggle-status-btn <?php echo ($row['status'] ?? 'active') == 'active' ? '' : 'inactive'; ?>' 
                                                onclick='showStatusModal(<?php echo $row['id']; ?>, "<?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>", "<?php echo $row['status'] ?? 'active'; ?>")'
                                                title="<?php echo ($row['status'] ?? 'active') == 'active' ? 'Disable' : 'Enable'; ?> Employee">
                                            <i class="fas <?php echo ($row['status'] ?? 'active') == 'active' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                                            <?php echo ($row['status'] ?? 'active') == 'active' ? 'Disable' : 'Enable'; ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No employees found</p>
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

    <!-- Add Employee Modal - MODIFIED: Email field is now optional, Date Hired is now optional -->
    <div id="addEmployeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Employee</h3>
                <button class="modal-close" onclick="closeAddEmployeeModal()">&times;</button>
            </div>
            <form method="POST" action="employee.php" onsubmit="return validateDates()">
                <div class="modal-body">
                    <div class="employee-form">
                        <div class="form-section">
                            <h4><i class="fas fa-user"></i> Personal Information</h4>
                            <div class="form-group">
                                <label for="first_name">First Name *</label>
                                <input type="text" id="first_name" name="first_name" 
                                       required placeholder="Enter first name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" 
                                       placeholder="Enter middle name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" 
                                       required placeholder="Enter last name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="dob">Date of Birth *</label>
                                <div class="date-picker-wrapper">
                                    <div class="date-input-group">
                                        <input type="text" 
                                               id="dobField" 
                                               class="date-field" 
                                               placeholder="MM/DD/YYYY"
                                               autocomplete="off"
                                               readonly
                                               onclick="toggleCalendar('dob')">
                                        <input type="hidden" id="dob" name="dob" value="">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('dob')">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Calendar Dropdown -->
                                    <div class="calendar-wrapper" id="dobCalendar">
                                        <div class="calendar-box">
                                            <div class="calendar-header">
                                                <div class="calendar-month-year" id="dobMonthYear"></div>
                                                <div class="calendar-nav">
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('dob', -1)">‹</button>
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('dob', 1)">›</button>
                                                </div>
                                            </div>
                                            
                                            <div class="calendar-selectors">
                                                <select id="dobMonthSelect" class="calendar-select" onchange="changeMonthYear('dob')">
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
                                                
                                                <select id="dobYearSelect" class="calendar-select" onchange="changeMonthYear('dob')">
                                                </select>
                                            </div>
                                            
                                            <div class="calendar-weekdays">
                                                <div>Su</div>
                                                <div>Mo</div>
                                                <div>Tu</div>
                                                <div>We</div>
                                                <div>Th</div>
                                                <div>Fr</div>
                                                <div>Sa</div>
                                            </div>
                                            
                                            <div class="calendar-days-grid" id="dobDaysGrid">
                                            </div>
                                            
                                            <div class="calendar-footer">
                                                <button type="button" class="calendar-action-btn clear" onclick="clearDate('dob')">
                                                    <i class="fas fa-times"></i> Clear
                                                </button>
                                                <button type="button" class="calendar-action-btn today" onclick="setToday('dob')">
                                                    <i class="fas fa-calendar-check"></i> Today
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small style="color: #666;">Select a valid date</small>
                            </div>
                            <!-- NEW AGE FIELD FOR ADD -->
                            <div class="form-group" style="margin-top: 15px;">
                                <label for="age_display">Age</label>
                                <div style="position: relative;">
                                    <input type="text" 
                                           id="age_display" 
                                           class="age-field" 
                                           readonly 
                                           placeholder="Auto-calculates from DOB"
                                           style="background: linear-gradient(135deg, #f5f5f5, #e8e8e8); 
                                                  color: #2E7D32; 
                                                  font-weight: 600; 
                                                  cursor: default;
                                                  border: 2px solid #c8e6c9;
                                                  width: 100%;
                                                  padding: 10px 15px 10px 12px;
                                                  border-radius: 8px;
                                                  font-size: 0.95rem;
                                                  height: 42px;">
                                    <input type="hidden" id="age" name="age" value="">
                                    <span style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #2E7D32;">
                                        <i class="fas fa-calendar-alt"></i>
                                    </span>
                                </div>
                                <small style="color: #666; display: block; margin-top: 4px;">
                                    <i class="fas fa-info-circle"></i> Automatically calculated from Date of Birth
                                </small>
                            </div>
                            <div class="form-group">
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" required>
                                    <option value="" disabled selected>Select gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="civil_status">Civil Status *</label>
                                <select id="civil_status" name="civil_status" required>
                                    <option value="" disabled selected>Select civil status</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-briefcase"></i> Employment Details</h4>
                            <div class="form-group">
                                <label for="employment_type">Employment Type *</label>
                                <select id="employment_type" name="employment_type" required>
                                    <option value="" disabled selected>Select employment type</option>
                                    <option value="regular">Regular</option>
                                    <option value="non_regular">Non Regular</option>
                                </select>
                                <small style="color: #666;">Regular employees receive holiday pay even when absent on regular/double holidays</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="position">Position *</label>
                                <input type="text" id="position" name="position" 
                                       required placeholder="Select position below" 
                                       autocomplete="off" readonly>
                            </div>
                            
                            <!-- Position Selection Area -->
                            <div class="position-selection-container">
                                <button type="button" class="select-position-btn" onclick="showPositionModal()">
                                    <i class="fas fa-tasks"></i> Select Position(s)
                                </button>
                                <div class="selected-positions" id="selectedPositionsDisplay">
                                    <!-- Selected positions will appear here -->
                                </div>
                            </div>

                            <!-- MODIFIED: Date Hired is now optional - removed required attribute -->
                            <div class="form-group">
                                <label for="date_hired">Date Hired</label>
                                <div class="date-picker-wrapper">
                                    <div class="date-input-group">
                                        <input type="text" 
                                               id="dateHiredField" 
                                               class="date-field" 
                                               placeholder="MM/DD/YYYY (optional)"
                                               autocomplete="off"
                                               readonly
                                               onclick="toggleCalendar('hired')">
                                        <input type="hidden" id="date_hired" name="date_hired" value="">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('hired')">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="calendar-wrapper" id="hiredCalendar">
                                        <div class="calendar-box">
                                            <div class="calendar-header">
                                                <div class="calendar-month-year" id="hiredMonthYear"></div>
                                                <div class="calendar-nav">
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('hired', -1)">‹</button>
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('hired', 1)">›</button>
                                                </div>
                                            </div>
                                            
                                            <div class="calendar-selectors">
                                                <select id="hiredMonthSelect" class="calendar-select" onchange="changeMonthYear('hired')">
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
                                                
                                                <select id="hiredYearSelect" class="calendar-select" onchange="changeMonthYear('hired')">
                                                </select>
                                            </div>
                                            
                                            <div class="calendar-weekdays">
                                                <div>Su</div>
                                                <div>Mo</div>
                                                <div>Tu</div>
                                                <div>We</div>
                                                <div>Th</div>
                                                <div>Fr</div>
                                                <div>Sa</div>
                                            </div>
                                            
                                            <div class="calendar-days-grid" id="hiredDaysGrid">
                                            </div>
                                            
                                            <div class="calendar-footer">
                                                <button type="button" class="calendar-action-btn clear" onclick="clearDate('hired')">
                                                    <i class="fas fa-times"></i> Clear
                                                </button>
                                                <button type="button" class="calendar-action-btn today" onclick="setToday('hired')">
                                                    <i class="fas fa-calendar-check"></i> Today
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small style="color: #666;">Optional - Select a date if applicable</small>
                            </div>
                            
                            <!-- Salary Section - Daily and Monthly side by side -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="daily_salary">Daily Salary *</label>
                                    <input type="number" id="daily_salary" name="daily_salary" 
                                           required placeholder="Enter daily salary" 
                                           autocomplete="off" step="0.01" min="0">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="monthly_salary">Monthly Salary *</label>
                                    <input type="number" id="monthly_salary" name="monthly_salary" 
                                           required placeholder="Enter monthly salary" 
                                           autocomplete="off" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <!-- MODIFIED: Email field - removed required attribute and asterisk -->
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" 
                                       placeholder="Enter email address (optional)" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="contact_num">Contact Number *</label>
                                <input type="text" id="contact_num" name="contact_num" 
                                       required placeholder="Enter contact number" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="address">Address *</label>
                                <input type="text" id="address" name="address" 
                                       required placeholder="Enter address" 
                                       autocomplete="off">
                            </div>
                        </div>
                        
                        <div class="govt-id-section">
                            <h4><i class="fas fa-id-card"></i> Government Identification Numbers</h4>
                            <p style="color: #666; font-size: 0.9rem; margin-bottom: 15px;">Optional: Provide government identification numbers for payroll processing</p>
                            <div class="govt-id-grid">
                                <div class="form-group">
                                    <label for="sss_number">SSS Number</label>
                                    <input type="text" id="sss_number" name="sss_number" 
                                           placeholder="XX-XXXXXXX-X" 
                                           pattern="[0-9]{2}-[0-9]{7}-[0-9]{1}"
                                           title="Format: XX-XXXXXXX-X"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XX-XXXXXXX-X</small>
                                </div>
                                <div class="form-group">
                                    <label for="pagibig_number">PAG-IBIG Number</label>
                                    <input type="text" id="pagibig_number" name="pagibig_number" 
                                           placeholder="XXXX-XXXX-XXXX" 
                                           pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}"
                                           title="Format: XXXX-XXXX-XXXX"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XXXX-XXXX-XXXX</small>
                                </div>
                                <div class="form-group">
                                    <label for="tin_number">TIN Number</label>
                                    <input type="text" id="tin_number" name="tin_number" 
                                           placeholder="XXX-XXX-XXX-XXX" 
                                           pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}"
                                           title="Format: XXX-XXX-XXX-XXX"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XXX-XXX-XXX-XXX</small>
                                </div>
                                <div class="form-group">
                                    <label for="philhealth_number">PhilHealth Number</label>
                                    <input type="text" id="philhealth_number" name="philhealth_number" 
                                           placeholder="XX-XXXXXXXXX-X" 
                                           pattern="[0-9]{2}-[0-9]{9}-[0-9]{1}"
                                           title="Format: XX-XXXXXXXXX-X"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XX-XXXXXXXXX-X</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeAddEmployeeModal()">Cancel</button>
                    <button type="submit" name="save_employee" class="btn btn-save">Save Employee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Position Selection Modal -->
    <div id="positionModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> Select Position(s)</h3>
                <button class="modal-close" onclick="closePositionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="category-buttons">
                    <button type="button" class="category-btn active" onclick="showPositionCategory('executive')" id="catExecutive">Executive</button>
                    <button type="button" class="category-btn" onclick="showPositionCategory('technical')" id="catTechnical">Technical</button>
                    <button type="button" class="category-btn" onclick="showPositionCategory('admin')" id="catAdmin">Admin</button>
                </div>

                <div id="positionChecklist" class="positions-container">
                    <!-- Positions will be loaded here dynamically from database -->
                    <div class="loading-spinner" style="text-align: center; padding: 30px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #2E7D32;"></i>
                        <p>Loading positions...</p>
                    </div>
                </div>

                <!-- Add and Delete Position Buttons Side by Side -->
                <div style="display: flex; gap: 10px; margin-top: 15px; justify-content: center;">
                    <button type="button" class="add-custom-position-btn" id="addPositionBtn" onclick="toggleCustomPositionInput()" style="flex: 1;">
                        <i class="fas fa-plus-circle"></i> Add New Position
                    </button>
                    <button type="button" 
    class="delete-multiple-btn" 
    id="deletePositionsBtn" 
    onclick="deleteSelectedPositions()" 
    style="background: linear-gradient(135deg, #dc3545, #c82333); 
           color: white; 
           border: none; 
           padding: 15px 50px;        
           border-radius: 25px; 
           font-size: 0.85rem; 
           font-weight: 600; 
           cursor: pointer; 
           display: flex; 
           align-items: center; 
           gap: 8px; 
           box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
           margin: 15px auto 5px;
           width: fit-content;">
    <i class="fas fa-trash"></i> Delete Selected
</button>
                </div>

                <div class="custom-position-input" id="customPositionInput" style="display: none;">
                    <input type="text" id="newPositionName" placeholder="Enter new position name">
                    <button type="button" onclick="addCustomPosition()">Add</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closePositionModal()">Cancel</button>
                <button type="button" class="btn btn-save" onclick="savePositions()">Save Positions</button>
            </div>
        </div>
    </div>

    <!-- Edit Position Selection Modal -->
    <div id="editPositionModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> Select Position(s)</h3>
                <button class="modal-close" onclick="closeEditPositionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="category-buttons">
                    <button type="button" class="category-btn active" onclick="showEditPositionCategory('executive')" id="editCatExecutive">Executive</button>
                    <button type="button" class="category-btn" onclick="showEditPositionCategory('technical')" id="editCatTechnical">Technical</button>
                    <button type="button" class="category-btn" onclick="showEditPositionCategory('admin')" id="editCatAdmin">Admin</button>
                </div>

                <div id="editPositionChecklist" class="positions-container">
                    <!-- Positions will be loaded here dynamically from database -->
                    <div class="loading-spinner" style="text-align: center; padding: 30px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #2E7D32;"></i>
                        <p>Loading positions...</p>
                    </div>
                </div>

                <!-- Add and Delete Position Buttons Side by Side for Edit -->
                <div style="display: flex; gap: 10px; margin-top: 15px; justify-content: center;">
                    <button type="button" class="add-custom-position-btn" id="editAddPositionBtn" onclick="toggleEditCustomPositionInput()" style="flex: 1;">
                        <i class="fas fa-plus-circle"></i> Add New Position
                    </button>
                    <button type="button" 
                    class="delete-multiple-btn" 
                    id="editDeletePositionsBtn" 
                    onclick="deleteEditSelectedPositions()" 
                    style="background: linear-gradient(135deg, #dc3545, #c82333); 
                        color: white; 
                        border: none; 
                        padding: 15px 50px;        
                        border-radius: 25px; 
                        font-size: 0.85rem; 
                        font-weight: 600; 
                        cursor: pointer; 
                        display: flex; 
                        align-items: center; 
                        gap: 8px; 
                        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
                        margin: 15px auto 5px;
                        width: fit-content;">
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
                </div>

                <div class="custom-position-input" id="editCustomPositionInput" style="display: none;">
                    <input type="text" id="editNewPositionName" placeholder="Enter new position name">
                    <button type="button" onclick="addEditCustomPosition()">Add</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeEditPositionModal()">Cancel</button>
                <button type="button" class="btn btn-save" onclick="saveEditPositions()">Save Positions</button>
            </div>
        </div>
    </div>

    <!-- View Employee Modal -->
    <div id="viewEmployeeModal" class="modal" style="<?= isset($_GET['view']) ? 'display: flex;' : 'display: none;' ?>">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> Employee Details</h3>
                <button class="modal-close" onclick="closeViewEmployeeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (isset($employee)): 
                    $employment_type = $employee['employment_type'] ?? 'regular';
                    $emp_type_class = $employment_type == 'regular' ? 'regular' : 'non-regular';
                    $emp_type_label = $employment_type == 'regular' ? 'Regular' : 'Non Regular';
                    $age = calculateAge($employee['dob'] ?? '');
                ?>
                <div class="employee-details">
                    <div class="form-section">
                        <h4><i class="fas fa-id-card"></i> Personal Information</h4>
                        <div class="form-group">
                            <label>Full Name:</label>
                            <p><?= htmlspecialchars($employee['first_name'] . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . $employee['last_name']) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth:</label>
                            <?php 
                            $dob_display = formatDateForDisplay($employee['dob'] ?? '');
                            echo '<p>' . ($dob_display ?: 'Not specified') . '</p>';
                            ?>
                        </div>
                        <!-- NEW AGE DISPLAY IN VIEW MODAL -->
                        <div class="form-group">
                            <label>Age:</label>
                            <p style="font-weight: 600; color: #2E7D32;">
                                <?= $age ? $age . ' years old' : 'Not specified' ?>
                            </p>
                        </div>
                        <div class="form-group">
                            <label>Gender:</label>
                            <p><?= htmlspecialchars($employee['gender']) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Civil Status:</label>
                            <p><?= htmlspecialchars($employee['civil_status']) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Address:</label>
                            <p><?= htmlspecialchars($employee['address']) ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-briefcase"></i> Employment Details</h4>
                        <div class="form-group">
                            <label>Employee ID:</label>
                            <p><?= $employee['id'] ?></p>
                        </div>
                        <div class="form-group">
                            <label>Employment Type:</label>
                            <p><span class="employment-type-badge <?php echo $emp_type_class; ?>"><?php echo $emp_type_label; ?></span></p>
                        </div>
                        <div class="form-group">
                            <label>Position:</label>
                            <p class="position-display"><?= str_replace(', ', ' / ', htmlspecialchars($employee['position'])) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Date Hired:</label>
                            <?php 
                            $hired_display = formatDateForDisplay($employee['date_hired'] ?? '');
                            echo '<p>' . ($hired_display ?: 'Not specified') . '</p>';
                            ?>
                        </div>
                        <div class="form-group">
                            <label>Daily Salary:</label>
                            <p>₱<?= number_format($employee['daily_salary'], 2) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Monthly Salary:</label>
                            <p>₱<?= number_format($employee['monthly_salary'] ?? 0, 2) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Email:</label>
                            <p><?= htmlspecialchars($employee['email'] ?: '-') ?></p>
                        </div>
                        <div class="form-group">
                            <label>Contact Number:</label>
                            <p><?= htmlspecialchars($employee['contact_num']) ?></p>
                        </div>
                        <div class="form-group">
                            <label>Status:</label>
                            <p>
                                <span class="status-badge <?php echo ($employee['status'] ?? 'active') == 'active' ? 'status-active' : 'status-inactive'; ?>">
                                    <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                                    <?php echo ucfirst($employee['status'] ?? 'active'); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Government IDs Section -->
                    <?php if (!empty($employee['sss_number']) || !empty($employee['pagibig_number']) || !empty($employee['tin_number']) || !empty($employee['philhealth_number'])): ?>
                    <div class="form-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-id-card"></i> Government Identification Numbers</h4>
                        <div class="govt-id-container">
                            <?php if (!empty($employee['sss_number'])): ?>
                            <div class="govt-id-badge">
                                <i class="fas fa-shield-alt"></i>
                                <div>
                                    <strong>SSS:</strong> <?= htmlspecialchars($employee['sss_number']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['pagibig_number'])): ?>
                            <div class="govt-id-badge">
                                <i class="fas fa-home"></i>
                                <div>
                                    <strong>PAG-IBIG:</strong> <?= htmlspecialchars($employee['pagibig_number']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['tin_number'])): ?>
                            <div class="govt-id-badge">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <div>
                                    <strong>TIN:</strong> <?= htmlspecialchars($employee['tin_number']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['philhealth_number'])): ?>
                            <div class="govt-id-badge">
                                <i class="fas fa-heartbeat"></i>
                                <div>
                                    <strong>PhilHealth:</strong> <?= htmlspecialchars($employee['philhealth_number']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeViewEmployeeModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Employee Modal - MODIFIED: Email field is now optional, Date Hired is now optional -->
    <div id="editEmployeeModal" class="modal" style="<?= isset($_GET['edit']) ? 'display: flex;' : 'display: none;' ?>">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Employee</h3>
                <button class="modal-close" onclick="closeEditEmployeeModal()">&times;</button>
            </div>
            <form method="POST" action="employee.php" onsubmit="return validateEditDates()">
                <input type="hidden" name="id" value="<?= isset($employee_edit['id']) ? htmlspecialchars($employee_edit['id']) : '' ?>">
                
                <div class="modal-body">
                    <div class="employee-form">
                        <div class="form-section">
                            <h4><i class="fas fa-user"></i> Personal Information</h4>
                            <div class="form-group">
                                <label for="edit_first_name">First Name *</label>
                                <input type="text" id="edit_first_name" name="first_name" 
                                       value="<?= htmlspecialchars($employee_edit['first_name'] ?? '') ?>" 
                                       required placeholder="Enter first name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="edit_middle_name">Middle Name</label>
                                <input type="text" id="edit_middle_name" name="middle_name" 
                                       value="<?= htmlspecialchars($employee_edit['middle_name'] ?? '') ?>" 
                                       placeholder="Enter middle name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="edit_last_name">Last Name *</label>
                                <input type="text" id="edit_last_name" name="last_name" 
                                       value="<?= htmlspecialchars($employee_edit['last_name'] ?? '') ?>" 
                                       required placeholder="Enter last name" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="edit_dob">Date of Birth *</label>
                                <div class="date-picker-wrapper">
                                    <div class="date-input-group">
                                        <input type="text" 
                                               id="editDobField" 
                                               class="date-field" 
                                               value="<?= formatDateForDisplay($employee_edit['dob'] ?? '') ?>"
                                               placeholder="MM/DD/YYYY"
                                               autocomplete="off"
                                               readonly
                                               onclick="toggleCalendar('editDob')">
                                        <input type="hidden" id="edit_dob" name="dob" value="<?= htmlspecialchars($employee_edit['dob'] ?? '') ?>">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('editDob')">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="calendar-wrapper" id="editDobCalendar">
                                        <div class="calendar-box">
                                            <div class="calendar-header">
                                                <div class="calendar-month-year" id="editDobMonthYear"></div>
                                                <div class="calendar-nav">
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('editDob', -1)">‹</button>
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('editDob', 1)">›</button>
                                                </div>
                                            </div>
                                            
                                            <div class="calendar-selectors">
                                                <select id="editDobMonthSelect" class="calendar-select" onchange="changeMonthYear('editDob')">
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
                                                
                                                <select id="editDobYearSelect" class="calendar-select" onchange="changeMonthYear('editDob')">
                                                </select>
                                            </div>
                                            
                                            <div class="calendar-weekdays">
                                                <div>Su</div>
                                                <div>Mo</div>
                                                <div>Tu</div>
                                                <div>We</div>
                                                <div>Th</div>
                                                <div>Fr</div>
                                                <div>Sa</div>
                                            </div>
                                            
                                            <div class="calendar-days-grid" id="editDobDaysGrid">
                                            </div>
                                            
                                            <div class="calendar-footer">
                                                <button type="button" class="calendar-action-btn clear" onclick="clearDate('editDob')">
                                                    <i class="fas fa-times"></i> Clear
                                                </button>
                                                <button type="button" class="calendar-action-btn today" onclick="setToday('editDob')">
                                                    <i class="fas fa-calendar-check"></i> Today
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small style="color: #666;">Select a valid date</small>
                            </div>
                            <!-- NEW AGE FIELD FOR EDIT -->
                            <div class="form-group" style="margin-top: 15px;">
                                <label for="edit_age_display">Age</label>
                                <div style="position: relative;">
                                    <input type="text" 
                                           id="edit_age_display" 
                                           class="age-field" 
                                           readonly 
                                           placeholder="Auto-calculates from DOB"
                                           style="background: linear-gradient(135deg, #f5f5f5, #e8e8e8); 
                                                  color: #2E7D32; 
                                                  font-weight: 600; 
                                                  cursor: default;
                                                  border: 2px solid #c8e6c9;
                                                  width: 100%;
                                                  padding: 10px 15px 10px 12px;
                                                  border-radius: 8px;
                                                  font-size: 0.95rem;
                                                  height: 42px;"
                                           value="<?php 
                                               $edit_age = calculateAge($employee_edit['dob'] ?? '');
                                               echo $edit_age ? $edit_age . ' years old' : '';
                                           ?>">
                                    <input type="hidden" id="edit_age" name="age" value="<?= $edit_age ? $edit_age : '' ?>">
                                    <span style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #2E7D32;">
                                        <i class="fas fa-calendar-alt"></i>
                                    </span>
                                </div>
                                <small style="color: #666; display: block; margin-top: 4px;">
                                    <i class="fas fa-info-circle"></i> Automatically calculated from Date of Birth
                                </small>
                            </div>
                            <div class="form-group">
                                <label for="edit_gender">Gender *</label>
                                <select id="edit_gender" name="gender" required>
                                    <option value="Male" <?= (isset($employee_edit['gender']) && $employee_edit['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= (isset($employee_edit['gender']) && $employee_edit['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_civil_status">Civil Status *</label>
                                <select id="edit_civil_status" name="civil_status" required>
                                    <option value="Single" <?= (isset($employee_edit['civil_status']) && $employee_edit['civil_status'] == 'Single') ? 'selected' : '' ?>>Single</option>
                                    <option value="Married" <?= (isset($employee_edit['civil_status']) && $employee_edit['civil_status'] == 'Married') ? 'selected' : '' ?>>Married</option>
                                    <option value="Widowed" <?= (isset($employee_edit['civil_status']) && $employee_edit['civil_status'] == 'Widowed') ? 'selected' : '' ?>>Widowed</option>
                                    <option value="Separated" <?= (isset($employee_edit['civil_status']) && $employee_edit['civil_status'] == 'Separated') ? 'selected' : '' ?>>Separated</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h4><i class="fas fa-briefcase"></i> Employment Details</h4>
                            <div class="form-group">
                                <label for="edit_employment_type">Employment Type *</label>
                                <select id="edit_employment_type" name="employment_type" required>
                                    <option value="" disabled>Select employment type</option>
                                    <option value="regular" <?= (isset($employee_edit['employment_type']) && $employee_edit['employment_type'] == 'regular') ? 'selected' : '' ?>>Regular</option>
                                    <option value="non_regular" <?= (isset($employee_edit['employment_type']) && $employee_edit['employment_type'] == 'non_regular') ? 'selected' : '' ?>>Non Regular</option>
                                </select>
                                <small style="color: #666;">Regular employees receive holiday pay even when absent on regular/double holidays</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_position">Position *</label>
                                <input type="text" id="edit_position" name="position" 
                                       value="<?= htmlspecialchars($employee_edit['position'] ?? '') ?>" 
                                       required placeholder="Select position below" 
                                       autocomplete="off" readonly>
                            </div>
                            
                            <!-- Position Selection Area for Edit -->
                            <div class="position-selection-container">
                                <button type="button" class="select-position-btn" onclick="showEditPositionModal()">
                                    <i class="fas fa-tasks"></i> Select Position(s)
                                </button>
                                <div class="selected-positions" id="editSelectedPositionsDisplay">
                                    <!-- Selected positions will appear here -->
                                </div>
                            </div>

                            <!-- MODIFIED: Date Hired is now optional - removed required attribute -->
                            <div class="form-group">
                                <label for="edit_date_hired">Date Hired</label>
                                <div class="date-picker-wrapper">
                                    <div class="date-input-group">
                                        <input type="text" 
                                               id="editHiredField" 
                                               class="date-field" 
                                               value="<?= formatDateForDisplay($employee_edit['date_hired'] ?? '') ?>"
                                               placeholder="MM/DD/YYYY (optional)"
                                               autocomplete="off"
                                               readonly
                                               onclick="toggleCalendar('editHired')">
                                        <input type="hidden" id="edit_date_hired" name="date_hired" value="<?= htmlspecialchars($employee_edit['date_hired'] ?? '') ?>">
                                        <button type="button" class="calendar-dropdown-btn" onclick="toggleCalendar('editHired')">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="calendar-wrapper" id="editHiredCalendar">
                                        <div class="calendar-box">
                                            <div class="calendar-header">
                                                <div class="calendar-month-year" id="editHiredMonthYear"></div>
                                                <div class="calendar-nav">
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('editHired', -1)">‹</button>
                                                    <button type="button" class="calendar-nav-btn" onclick="navigateMonth('editHired', 1)">›</button>
                                                </div>
                                            </div>
                                            
                                            <div class="calendar-selectors">
                                                <select id="editHiredMonthSelect" class="calendar-select" onchange="changeMonthYear('editHired')">
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
                                                
                                                <select id="editHiredYearSelect" class="calendar-select" onchange="changeMonthYear('editHired')">
                                                </select>
                                            </div>
                                            
                                            <div class="calendar-weekdays">
                                                <div>Su</div>
                                                <div>Mo</div>
                                                <div>Tu</div>
                                                <div>We</div>
                                                <div>Th</div>
                                                <div>Fr</div>
                                                <div>Sa</div>
                                            </div>
                                            
                                            <div class="calendar-days-grid" id="editHiredDaysGrid">
                                            </div>
                                            
                                            <div class="calendar-footer">
                                                <button type="button" class="calendar-action-btn clear" onclick="clearDate('editHired')">
                                                    <i class="fas fa-times"></i> Clear
                                                </button>
                                                <button type="button" class="calendar-action-btn today" onclick="setToday('editHired')">
                                                    <i class="fas fa-calendar-check"></i> Today
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small style="color: #666;">Optional - Select a date if applicable</small>
                            </div>
                            
                            <!-- Salary Section - Daily and Monthly side by side for Edit -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="edit_daily_salary">Daily Salary *</label>
                                    <input type="number" id="edit_daily_salary" name="daily_salary" 
                                           value="<?= htmlspecialchars($employee_edit['daily_salary'] ?? '') ?>" 
                                           required placeholder="Enter daily salary" 
                                           autocomplete="off" step="0.01" min="0">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="edit_monthly_salary">Monthly Salary *</label>
                                    <input type="number" id="edit_monthly_salary" name="monthly_salary" 
                                           value="<?= htmlspecialchars($employee_edit['monthly_salary'] ?? '') ?>" 
                                           required placeholder="Enter monthly salary" 
                                           autocomplete="off" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <!-- MODIFIED: Email field - removed required attribute and asterisk -->
                            <div class="form-group">
                                <label for="edit_email">Email Address</label>
                                <input type="email" id="edit_email" name="email" 
                                       value="<?= htmlspecialchars($employee_edit['email'] ?? '') ?>" 
                                       placeholder="Enter email address (optional)" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="edit_contact_num">Contact Number *</label>
                                <input type="text" id="edit_contact_num" name="contact_num" 
                                       value="<?= htmlspecialchars($employee_edit['contact_num'] ?? '') ?>" 
                                       required placeholder="Enter contact number" 
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="edit_address">Address *</label>
                                <input type="text" id="edit_address" name="address" 
                                       value="<?= htmlspecialchars($employee_edit['address'] ?? '') ?>" 
                                       required placeholder="Enter address" 
                                       autocomplete="off">
                            </div>
                        </div>
                        
                        <div class="govt-id-section">
                            <h4><i class="fas fa-id-card"></i> Government Identification Numbers</h4>
                            <p style="color: #666; font-size: 0.9rem; margin-bottom: 15px;">Optional: Provide government identification numbers for payroll processing</p>
                            <div class="govt-id-grid">
                                <div class="form-group">
                                    <label for="edit_sss_number">SSS Number</label>
                                    <input type="text" id="edit_sss_number" name="sss_number" 
                                           value="<?= htmlspecialchars($employee_edit['sss_number'] ?? '') ?>" 
                                           placeholder="XX-XXXXXXX-X" 
                                           pattern="[0-9]{2}-[0-9]{7}-[0-9]{1}"
                                           title="Format: XX-XXXXXXX-X"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XX-XXXXXXX-X</small>
                                </div>
                                <div class="form-group">
                                    <label for="edit_pagibig_number">PAG-IBIG Number</label>
                                    <input type="text" id="edit_pagibig_number" name="pagibig_number" 
                                           value="<?= htmlspecialchars($employee_edit['pagibig_number'] ?? '') ?>" 
                                           placeholder="XXXX-XXXX-XXXX" 
                                           pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}"
                                           title="Format: XXXX-XXXX-XXXX"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XXXX-XXXX-XXXX</small>
                                </div>
                                <div class="form-group">
                                    <label for="edit_tin_number">TIN Number</label>
                                    <input type="text" id="edit_tin_number" name="tin_number" 
                                           value="<?= htmlspecialchars($employee_edit['tin_number'] ?? '') ?>" 
                                           placeholder="XXX-XXX-XXX-XXX" 
                                           pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}"
                                           title="Format: XXX-XXX-XXX-XXX"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XXX-XXX-XXX-XXX</small>
                                </div>
                                <div class="form-group">
                                    <label for="edit_philhealth_number">PhilHealth Number</label>
                                    <input type="text" id="edit_philhealth_number" name="philhealth_number" 
                                           value="<?= htmlspecialchars($employee_edit['philhealth_number'] ?? '') ?>" 
                                           placeholder="XX-XXXXXXXXX-X" 
                                           pattern="[0-9]{2}-[0-9]{9}-[0-9]{1}"
                                           title="Format: XX-XXXXXXXXX-X"
                                           autocomplete="off">
                                    <small style="color: #888; font-size: 0.8rem;">Format: XX-XXXXXXXXX-X</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeEditEmployeeModal()">Cancel</button>
                    <button type="submit" name="update_employee" class="btn btn-save">Update Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Calendar state
        let activeCalendar = null;
        let calendarDates = {
            dob: { currentDate: new Date(), selectedDate: null },
            hired: { currentDate: new Date(), selectedDate: null },
            editDob: { currentDate: new Date(), selectedDate: null },
            editHired: { currentDate: new Date(), selectedDate: null }
        };

        // Position selection state
        let selectedPositions = [];
        let editSelectedPositions = [];
        let currentCategory = 'executive';
        let editCurrentCategory = 'executive';

        // Store all checkboxes state across categories
        let allPositionStates = {};

        // Variables for delete and status modals
        let currentEmployeeId = null;
        let currentEmployeeName = '';
        let currentEmployeeStatus = '';

        // Toast notification function
        function showToast(message, type = 'success', duration = 5000) {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
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
            
            toast.innerHTML = `
                ${icon}
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 300);
            }, duration);
        }

        // ============================================
        // AGE CALCULATION FUNCTIONALITY
        // ============================================

        // Function to calculate age from date of birth
        function calculateAge(dateOfBirth) {
            if (!dateOfBirth) return '';
            
            const today = new Date();
            const birthDate = new Date(dateOfBirth);
            
            // Validate if it's a valid date
            if (isNaN(birthDate.getTime())) return '';
            
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            // Adjust age if birthday hasn't occurred yet this year
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            return age;
        }

        // Update age field when date of birth changes
        function updateAgeFromDOB(dateFieldId, hiddenFieldId, displayFieldId) {
            const hiddenField = document.getElementById(hiddenFieldId);
            const displayField = document.getElementById(displayFieldId);
            
            if (!hiddenField || !displayField) return;
            
            const dateValue = hiddenField.value;
            
            if (dateValue && dateValue !== '0000-00-00') {
                const age = calculateAge(dateValue);
                
                if (age !== '') {
                    // Format the age display
                    let ageText = age + ' years old';
                    
                    // Add visual indicator for different age groups
                    if (age < 18) {
                        ageText = '⚠️ ' + ageText + ' (Minor)';
                        displayField.style.color = '#e67e22';
                    } else if (age >= 60) {
                        ageText = '👴 ' + ageText + ' (Senior)';
                        displayField.style.color = '#2980b9';
                    } else {
                        displayField.style.color = '#2E7D32';
                    }
                    
                    displayField.value = ageText;
                    
                    // Update hidden age field if it exists (for form submission)
                    const ageHiddenId = displayFieldId === 'age_display' ? 'age' : 'edit_age';
                    const ageHidden = document.getElementById(ageHiddenId);
                    if (ageHidden) {
                        ageHidden.value = age;
                    }
                    
                    // Add animation effect
                    displayField.style.transform = 'scale(1.02)';
                    setTimeout(() => {
                        displayField.style.transform = 'scale(1)';
                    }, 200);
                } else {
                    displayField.value = '';
                    displayField.style.color = '#2E7D32';
                    
                    const ageHiddenId = displayFieldId === 'age_display' ? 'age' : 'edit_age';
                    const ageHidden = document.getElementById(ageHiddenId);
                    if (ageHidden) {
                        ageHidden.value = '';
                    }
                }
            } else {
                displayField.value = '';
                displayField.style.color = '#2E7D32';
                
                const ageHiddenId = displayFieldId === 'age_display' ? 'age' : 'edit_age';
                const ageHidden = document.getElementById(ageHiddenId);
                if (ageHidden) {
                    ageHidden.value = '';
                }
            }
        }

        // Initialize age calculation for forms
        function initializeAgeCalculation() {
            // Add form
            const dobHidden = document.getElementById('dob');
            const ageDisplay = document.getElementById('age_display');
            
            if (dobHidden && ageDisplay) {
                // Initial calculation if DOB exists
                if (dobHidden.value) {
                    updateAgeFromDOB('dobField', 'dob', 'age_display');
                }
                
                // Monitor changes to DOB
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                            updateAgeFromDOB('dobField', 'dob', 'age_display');
                        }
                    });
                });
                
                observer.observe(dobHidden, { attributes: true });
            }
            
            // Edit form
            const editDobHidden = document.getElementById('edit_dob');
            const editAgeDisplay = document.getElementById('edit_age_display');
            
            if (editDobHidden && editAgeDisplay) {
                // Initial calculation if DOB exists
                if (editDobHidden.value) {
                    updateAgeFromDOB('editDobField', 'edit_dob', 'edit_age_display');
                }
                
                // Monitor changes to DOB
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                            updateAgeFromDOB('editDobField', 'edit_dob', 'edit_age_display');
                        }
                    });
                });
                
                observer.observe(editDobHidden, { attributes: true });
            }
        }

        // Load positions from database for the selection modal with cross-category support
        function loadPositionsFromDB(category, targetElementId, isEdit = false) {
            fetch(`employee.php?ajax=get_positions&category=${category}`)
                .then(response => response.json())
                .then(positions => {
                    const container = document.getElementById(targetElementId);
                    if (!container) return;
                    
                    if (positions.length === 0) {
                        container.innerHTML = '<div style="text-align: center; padding: 30px; color: #666;">No positions found</div>';
                        return;
                    }
                    
                    let html = '';
                    positions.forEach(position => {
                        // Check if this position is in allPositionStates first, otherwise use the selectedPositions/editSelectedPositions
                        let isChecked = false;
                        if (isEdit) {
                            isChecked = allPositionStates[`edit_${position.name}`] !== undefined 
                                ? allPositionStates[`edit_${position.name}`] 
                                : editSelectedPositions.includes(position.name);
                        } else {
                            isChecked = allPositionStates[`add_${position.name}`] !== undefined 
                                ? allPositionStates[`add_${position.name}`] 
                                : selectedPositions.includes(position.name);
                        }
                        
                        html += `
                            <div class="position-checkbox-item">
                                <input type="checkbox" value="${position.id}" data-name="${position.name}" data-custom="${position.is_custom}" ${isChecked ? 'checked' : ''} onchange="updatePositionState('${position.name}', this.checked, ${isEdit})">
                                <label>${position.name}</label>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading positions:', error);
                    document.getElementById(targetElementId).innerHTML = '<div style="text-align: center; padding: 30px; color: #e74c3c;">Error loading positions. Please refresh the page.</div>';
                });
        }

        // Update position state when checkbox changes
        function updatePositionState(positionName, isChecked, isEdit = false) {
            const key = isEdit ? `edit_${positionName}` : `add_${positionName}`;
            allPositionStates[key] = isChecked;
            
            // Also update the main arrays for backward compatibility
            if (isEdit) {
                if (isChecked) {
                    if (!editSelectedPositions.includes(positionName)) {
                        editSelectedPositions.push(positionName);
                    }
                } else {
                    editSelectedPositions = editSelectedPositions.filter(p => p !== positionName);
                }
            } else {
                if (isChecked) {
                    if (!selectedPositions.includes(positionName)) {
                        selectedPositions.push(positionName);
                    }
                } else {
                    selectedPositions = selectedPositions.filter(p => p !== positionName);
                }
            }
        }

        // Delete selected positions function
        function deleteSelectedPositions() {
            // Get all checked checkboxes in the position modal
            const checkboxes = document.querySelectorAll('#positionModal .position-checkbox-item input[type="checkbox"]:checked');
            
            if (checkboxes.length === 0) {
                showToast('Please select at least one position to delete', 'warning');
                return;
            }
            
            const selectedIds = [];
            const selectedNames = [];
            
            checkboxes.forEach(cb => {
                if (cb.dataset.custom === '1') { // Only allow deleting custom positions
                    selectedIds.push(cb.value);
                    selectedNames.push(cb.dataset.name);
                }
            });
            
            if (selectedIds.length === 0) {
                showToast('Cannot delete default positions. Only custom positions can be deleted.', 'warning');
                return;
            }
            
            if (!confirm(`Are you sure you want to delete ${selectedNames.length} position(s)?\n\n${selectedNames.join(', ')}\n\nThis action cannot be undone and will only work if no employees are assigned to these positions.`)) {
                return;
            }
            
            // Delete positions one by one
            let deletedCount = 0;
            let errorCount = 0;
            let promises = [];
            
            selectedIds.forEach((id, index) => {
                const name = selectedNames[index];
                const promise = fetch('employee.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=delete_position&position_id=${id}&position_name=${encodeURIComponent(name)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        deletedCount++;
                        
                        // Remove from all states
                        delete allPositionStates[`add_${name}`];
                        delete allPositionStates[`edit_${name}`];
                        
                        if (selectedPositions.includes(name)) {
                            selectedPositions = selectedPositions.filter(p => p !== name);
                            updatePositionsDisplay();
                        }
                        
                        if (editSelectedPositions.includes(name)) {
                            editSelectedPositions = editSelectedPositions.filter(p => p !== name);
                            updateEditPositionsDisplay();
                        }
                    } else {
                        errorCount++;
                        console.error(`Failed to delete ${name}:`, data.message);
                    }
                })
                .catch(error => {
                    errorCount++;
                    console.error(`Error deleting ${name}:`, error);
                });
                
                promises.push(promise);
            });
            
            Promise.all(promises).then(() => {
                if (deletedCount > 0) {
                    showToast(`Successfully deleted ${deletedCount} position(s). ${errorCount > 0 ? errorCount + ' failed.' : ''}`, 'success');
                    
                    // Reload positions
                    loadPositionsFromDB(currentCategory, 'positionChecklist', false);
                } else {
                    showToast('No positions were deleted', 'error');
                }
            });
        }

        // Delete selected positions in edit modal
        function deleteEditSelectedPositions() {
            // Get all checked checkboxes in the edit position modal
            const checkboxes = document.querySelectorAll('#editPositionModal .position-checkbox-item input[type="checkbox"]:checked');
            
            if (checkboxes.length === 0) {
                showToast('Please select at least one position to delete', 'warning');
                return;
            }
            
            const selectedIds = [];
            const selectedNames = [];
            
            checkboxes.forEach(cb => {
                if (cb.dataset.custom === '1') { // Only allow deleting custom positions
                    selectedIds.push(cb.value);
                    selectedNames.push(cb.dataset.name);
                }
            });
            
            if (selectedIds.length === 0) {
                showToast('Cannot delete default positions. Only custom positions can be deleted.', 'warning');
                return;
            }
            
            if (!confirm(`Are you sure you want to delete ${selectedNames.length} position(s)?\n\n${selectedNames.join(', ')}\n\nThis action cannot be undone and will only work if no employees are assigned to these positions.`)) {
                return;
            }
            
            // Delete positions one by one
            let deletedCount = 0;
            let errorCount = 0;
            let promises = [];
            
            selectedIds.forEach((id, index) => {
                const name = selectedNames[index];
                const promise = fetch('employee.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=delete_position&position_id=${id}&position_name=${encodeURIComponent(name)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        deletedCount++;
                        
                        // Remove from all states
                        delete allPositionStates[`add_${name}`];
                        delete allPositionStates[`edit_${name}`];
                        
                        if (selectedPositions.includes(name)) {
                            selectedPositions = selectedPositions.filter(p => p !== name);
                            updatePositionsDisplay();
                        }
                        
                        if (editSelectedPositions.includes(name)) {
                            editSelectedPositions = editSelectedPositions.filter(p => p !== name);
                            updateEditPositionsDisplay();
                        }
                    } else {
                        errorCount++;
                        console.error(`Failed to delete ${name}:`, data.message);
                    }
                })
                .catch(error => {
                    errorCount++;
                    console.error(`Error deleting ${name}:`, error);
                });
                
                promises.push(promise);
            });
            
            Promise.all(promises).then(() => {
                if (deletedCount > 0) {
                    showToast(`Successfully deleted ${deletedCount} position(s). ${errorCount > 0 ? errorCount + ' failed.' : ''}`, 'success');
                    
                    // Reload positions
                    loadPositionsFromDB(editCurrentCategory, 'editPositionChecklist', true);
                } else {
                    showToast('No positions were deleted', 'error');
                }
            });
        }

        // Check for stored toast messages
        document.addEventListener('DOMContentLoaded', function() {
            const toastMessage = localStorage.getItem('toastMessage');
            const toastType = localStorage.getItem('toastType');
            
            if (toastMessage) {
                showToast(toastMessage, toastType || 'success');
                localStorage.removeItem('toastMessage');
                localStorage.removeItem('toastType');
            }
            
            // Set initial selected dates from hidden inputs
            const dobHidden = document.getElementById('dob');
            const hiredHidden = document.getElementById('date_hired');
            const editDobHidden = document.getElementById('edit_dob');
            const editHiredHidden = document.getElementById('edit_date_hired');
            
            if (dobHidden && dobHidden.value) {
                calendarDates.dob.selectedDate = dobHidden.value;
                const dobField = document.getElementById('dobField');
                if (dobField && dobHidden.value) {
                    const date = new Date(dobHidden.value);
                    dobField.value = date.toLocaleDateString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric'
                    });
                }
            }
            
            if (hiredHidden && hiredHidden.value) {
                calendarDates.hired.selectedDate = hiredHidden.value;
                const hiredField = document.getElementById('dateHiredField');
                if (hiredField && hiredHidden.value) {
                    const date = new Date(hiredHidden.value);
                    hiredField.value = date.toLocaleDateString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric'
                    });
                }
            }
            
            if (editDobHidden && editDobHidden.value) {
                calendarDates.editDob.selectedDate = editDobHidden.value;
                const editDobField = document.getElementById('editDobField');
                if (editDobField && editDobHidden.value) {
                    const date = new Date(editDobHidden.value);
                    editDobField.value = date.toLocaleDateString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric'
                    });
                }
            }
            
            if (editHiredHidden && editHiredHidden.value) {
                calendarDates.editHired.selectedDate = editHiredHidden.value;
                const editHiredField = document.getElementById('editHiredField');
                if (editHiredField && editHiredHidden.value) {
                    const date = new Date(editHiredHidden.value);
                    editHiredField.value = date.toLocaleDateString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric'
                    });
                }
            }

            // Initialize edit positions if in edit mode
            <?php if (isset($employee_edit['position'])): ?>
            editSelectedPositions = <?= json_encode(explode(', ', $employee_edit['position'])) ?>;
            // Initialize allPositionStates for edit mode
            editSelectedPositions.forEach(pos => {
                allPositionStates[`edit_${pos}`] = true;
            });
            updateEditPositionsDisplay();
            <?php endif; ?>
            
            // Initialize age calculation
            initializeAgeCalculation();
        });

        // Initialize year dropdowns
        function initializeYearSelects() {
            const yearSelects = ['dobYearSelect', 'hiredYearSelect', 'editDobYearSelect', 'editHiredYearSelect'];
            const currentYear = new Date().getFullYear();
            
            yearSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (select) {
                    select.innerHTML = '';
                    for (let year = 1900; year <= 2100; year++) {
                        const option = document.createElement('option');
                        option.value = year;
                        option.textContent = year;
                        if (year === currentYear) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    }
                }
            });
        }

        // FIX: Enhanced date validation function - MODIFIED: Date Hired validation is now optional
        function validateDateField(input) {
            const dateValue = input.value;
            const warningId = input.id + 'Warning';
            const warning = document.getElementById(warningId);
            
            if (!warning) return true;
            
            // Check if empty - allow empty for date_hired fields
            if (!dateValue) {
                // For date_hired fields, empty is allowed
                if (input.id === 'date_hired' || input.id === 'edit_date_hired') {
                    input.classList.remove('invalid');
                    warning.style.display = 'none';
                    return true;
                }
                
                input.classList.add('invalid');
                warning.style.display = 'block';
                warning.textContent = 'Please select a date';
                return false;
            }
            
            // Check if it's just a year (like "2008" or "2010")
            if (/^\d{4}$/.test(dateValue)) {
                input.classList.add('invalid');
                warning.style.display = 'block';
                warning.textContent = 'Invalid date. Please select a full date (YYYY-MM-DD)';
                return false;
            }
            
            // Check format YYYY-MM-DD
            const datePattern = /^\d{4}-\d{2}-\d{2}$/;
            if (!datePattern.test(dateValue)) {
                input.classList.add('invalid');
                warning.style.display = 'block';
                warning.textContent = 'Invalid date format. Please use YYYY-MM-DD';
                return false;
            }
            
            // Check if it's a valid date
            const date = new Date(dateValue);
            if (isNaN(date.getTime())) {
                input.classList.add('invalid');
                warning.style.display = 'block';
                warning.textContent = 'Invalid date. Please select a valid date';
                return false;
            }
            
            // Check if month is between 01-12 and day is valid
            const parts = dateValue.split('-');
            const year = parseInt(parts[0]);
            const month = parseInt(parts[1]);
            const day = parseInt(parts[2]);
            
            if (month < 1 || month > 12 || day < 1 || day > 31) {
                input.classList.add('invalid');
                warning.style.display = 'block';
                warning.textContent = 'Invalid month or day';
                return false;
            }
            
            // Check if date is not in the future for DOB
            if (input.id === 'dob' || input.id === 'edit_dob') {
                const today = new Date();
                const minAgeDate = new Date();
                minAgeDate.setFullYear(today.getFullYear() - 18);
                
                if (date > minAgeDate) {
                    input.classList.add('invalid');
                    warning.style.display = 'block';
                    warning.textContent = 'Employee must be at least 18 years old';
                    return false;
                }
            }
            
            // Check if date hired is not in the future - only if value exists
            if ((input.id === 'date_hired' || input.id === 'edit_date_hired') && dateValue) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                if (date > today) {
                    input.classList.add('invalid');
                    warning.style.display = 'block';
                    warning.textContent = 'Date hired cannot be in the future';
                    return false;
                }
            }
            
            input.classList.remove('invalid');
            warning.style.display = 'none';
            return true;
        }

        // Validate all dates in add form - MODIFIED: Date Hired is now optional
        function validateDates() {
            const dob = document.getElementById('dob');
            
            const isDobValid = validateDateField(dob);
            
            if (!isDobValid) {
                alert('Please enter a valid Date of Birth');
                return false;
            }
            
            return true;
        }

        // Validate all dates in edit form - MODIFIED: Date Hired is now optional
        function validateEditDates() {
            const dob = document.getElementById('edit_dob');
            
            const isDobValid = validateDateField(dob);
            
            if (!isDobValid) {
                alert('Please enter a valid Date of Birth');
                return false;
            }
            
            return true;
        }

        // Toggle calendar
        function toggleCalendar(calendarId) {
            const calendar = document.getElementById(calendarId + 'Calendar');
            if (calendar) {
                document.querySelectorAll('.calendar-wrapper').forEach(cal => {
                    if (cal.id !== calendarId + 'Calendar') {
                        cal.style.display = 'none';
                    }
                });
                
                if (calendar.style.display === 'block') {
                    calendar.style.display = 'none';
                    activeCalendar = null;
                } else {
                    updateCalendar(calendarId);
                    calendar.style.display = 'block';
                    activeCalendar = calendarId;
                }
            }
        }

        // Update calendar display
        function updateCalendar(calendarId) {
            const date = calendarDates[calendarId].currentDate || new Date();
            const year = date.getFullYear();
            const month = date.getMonth();
            
            const monthYearElement = document.getElementById(calendarId + 'MonthYear');
            if (monthYearElement) {
                monthYearElement.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            }
            
            const monthSelect = document.getElementById(calendarId + 'MonthSelect');
            const yearSelect = document.getElementById(calendarId + 'YearSelect');
            
            if (monthSelect) monthSelect.value = month;
            if (yearSelect) yearSelect.value = year;
            
            generateCalendarDays(calendarId);
        }

        // Generate calendar days
        function generateCalendarDays(calendarId) {
            const date = calendarDates[calendarId].currentDate || new Date();
            const year = date.getFullYear();
            const month = date.getMonth();
            const daysGrid = document.getElementById(calendarId + 'DaysGrid');
            
            if (!daysGrid) return;
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();
            const selectedDate = calendarDates[calendarId].selectedDate;
            
            let html = '';
            
            // Previous month days
            const prevMonthDays = new Date(year, month, 0).getDate();
            for (let i = firstDay - 1; i >= 0; i--) {
                const day = prevMonthDays - i;
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
            }
            
            // Current month days
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
                const isSelected = selectedDate === dateStr;
                const isWeekend = new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6;
                
                let classes = 'calendar-day';
                if (isToday) classes += ' today';
                if (isSelected) classes += ' selected';
                if (isWeekend) classes += ' weekend';
                
                html += `<div class="${classes}" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
            }
            
            // Next month days
            const totalCells = 42;
            const cellsUsed = firstDay + daysInMonth;
            const nextMonthDays = totalCells - cellsUsed;
            for (let day = 1; day <= nextMonthDays; day++) {
                const dateStr = `${year}-${String(month + 2).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                html += `<div class="calendar-day other-month" onclick="selectDate('${calendarId}', '${dateStr}')">${day}</div>`;
            }
            
            daysGrid.innerHTML = html;
        }

        // Navigate months
        function navigateMonth(calendarId, direction) {
            const date = calendarDates[calendarId].currentDate;
            date.setMonth(date.getMonth() + direction);
            updateCalendar(calendarId);
        }

        // Change month/year from selects
        function changeMonthYear(calendarId) {
            const monthSelect = document.getElementById(calendarId + 'MonthSelect');
            const yearSelect = document.getElementById(calendarId + 'YearSelect');
            
            if (monthSelect && yearSelect) {
                const newMonth = parseInt(monthSelect.value);
                const newYear = parseInt(yearSelect.value);
                
                calendarDates[calendarId].currentDate = new Date(newYear, newMonth, 1);
                updateCalendar(calendarId);
            }
        }

        // Select a date
        function selectDate(calendarId, dateStr) {
            const date = new Date(dateStr);
            const formattedDisplay = date.toLocaleDateString('en-US', {
                month: '2-digit',
                day: '2-digit',
                year: 'numeric'
            });
            
            let fieldId = '';
            let hiddenId = '';
            let displayFieldId = '';
            
            switch(calendarId) {
                case 'dob':
                    fieldId = 'dobField';
                    hiddenId = 'dob';
                    displayFieldId = 'age_display';
                    break;
                case 'hired':
                    fieldId = 'dateHiredField';
                    hiddenId = 'date_hired';
                    displayFieldId = null;
                    break;
                case 'editDob':
                    fieldId = 'editDobField';
                    hiddenId = 'edit_dob';
                    displayFieldId = 'edit_age_display';
                    break;
                case 'editHired':
                    fieldId = 'editHiredField';
                    hiddenId = 'edit_date_hired';
                    displayFieldId = null;
                    break;
            }
            
            const field = document.getElementById(fieldId);
            const hidden = document.getElementById(hiddenId);
            
            if (field) {
                field.value = formattedDisplay;
            }
            
            if (hidden) {
                hidden.value = dateStr;
                
                // Calculate and update age if this is a DOB field
                if (displayFieldId) {
                    updateAgeFromDOB(fieldId, hiddenId, displayFieldId);
                }
            }
            
            calendarDates[calendarId].selectedDate = dateStr;
            
            const calendar = document.getElementById(calendarId + 'Calendar');
            if (calendar) calendar.style.display = 'none';
            activeCalendar = null;
            
            updateCalendar(calendarId);
        }

        // Clear date
        function clearDate(calendarId) {
            let fieldId = '';
            let hiddenId = '';
            let displayFieldId = '';
            
            switch(calendarId) {
                case 'dob':
                    fieldId = 'dobField';
                    hiddenId = 'dob';
                    displayFieldId = 'age_display';
                    break;
                case 'hired':
                    fieldId = 'dateHiredField';
                    hiddenId = 'date_hired';
                    displayFieldId = null;
                    break;
                case 'editDob':
                    fieldId = 'editDobField';
                    hiddenId = 'edit_dob';
                    displayFieldId = 'edit_age_display';
                    break;
                case 'editHired':
                    fieldId = 'editHiredField';
                    hiddenId = 'edit_date_hired';
                    displayFieldId = null;
                    break;
            }
            
            const field = document.getElementById(fieldId);
            const hidden = document.getElementById(hiddenId);
            
            if (field) field.value = '';
            if (hidden) hidden.value = '';
            
            // Clear age field if this is a DOB field
            if (displayFieldId) {
                const displayField = document.getElementById(displayFieldId);
                if (displayField) {
                    displayField.value = '';
                }
                
                const ageHiddenId = displayFieldId === 'age_display' ? 'age' : 'edit_age';
                const ageHidden = document.getElementById(ageHiddenId);
                if (ageHidden) {
                    ageHidden.value = '';
                }
            }
            
            calendarDates[calendarId].selectedDate = null;
            
            const calendar = document.getElementById(calendarId + 'Calendar');
            if (calendar) calendar.style.display = 'none';
            activeCalendar = null;
            
            updateCalendar(calendarId);
        }

        // Set today's date
        function setToday(calendarId) {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;
            
            calendarDates[calendarId].currentDate = new Date(dateStr);
            selectDate(calendarId, dateStr);
        }

        // Position Modal Functions
        function showPositionModal() {
            document.getElementById('positionModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            currentCategory = 'executive';
            updateCategoryButtons('positionModal', currentCategory);
            loadPositionsFromDB(currentCategory, 'positionChecklist', false);
        }

        function closePositionModal() {
            document.getElementById('positionModal').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function showEditPositionModal() {
            document.getElementById('editPositionModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            editCurrentCategory = 'executive';
            updateCategoryButtons('editPositionModal', editCurrentCategory);
            loadPositionsFromDB(editCurrentCategory, 'editPositionChecklist', true);
        }

        function closeEditPositionModal() {
            document.getElementById('editPositionModal').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function updateCategoryButtons(modalId, activeCategory) {
            const modal = document.getElementById(modalId);
            const btns = modal.querySelectorAll('.category-btn');
            btns.forEach(btn => btn.classList.remove('active'));
            const activeBtn = modal.querySelector(`#${modalId === 'positionModal' ? 'cat' : 'editCat'}${activeCategory.charAt(0).toUpperCase() + activeCategory.slice(1)}`);
            if (activeBtn) activeBtn.classList.add('active');
        }

        function showPositionCategory(category) {
            currentCategory = category;
            updateCategoryButtons('positionModal', category);
            loadPositionsFromDB(category, 'positionChecklist', false);
        }

        function showEditPositionCategory(category) {
            editCurrentCategory = category;
            updateCategoryButtons('editPositionModal', category);
            loadPositionsFromDB(category, 'editPositionChecklist', true);
        }

        function toggleCustomPositionInput() {
            const input = document.getElementById('customPositionInput');
            if (input.style.display === 'none' || input.style.display === '') {
                input.style.display = 'flex';
                document.getElementById('addPositionBtn').style.display = 'none';
            } else {
                input.style.display = 'none';
                document.getElementById('addPositionBtn').style.display = 'block';
            }
        }

        function toggleEditCustomPositionInput() {
            const input = document.getElementById('editCustomPositionInput');
            if (input.style.display === 'none' || input.style.display === '') {
                input.style.display = 'flex';
                document.getElementById('editAddPositionBtn').style.display = 'none';
            } else {
                input.style.display = 'none';
                document.getElementById('editAddPositionBtn').style.display = 'block';
            }
        }

        function addCustomPosition() {
            const input = document.getElementById('newPositionName');
            const positionName = input.value.trim();
            
            if (positionName === '') {
                showToast('Please enter a position name', 'warning');
                return;
            }

            fetch('employee.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=add_position&position_name=${encodeURIComponent(positionName)}&category=${currentCategory}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    input.value = '';
                    document.getElementById('customPositionInput').style.display = 'none';
                    document.getElementById('addPositionBtn').style.display = 'block';
                    
                    // Refresh the position list
                    loadPositionsFromDB(currentCategory, 'positionChecklist', false);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Error adding position', 'error');
                console.error(error);
            });
        }

        function addEditCustomPosition() {
            const input = document.getElementById('editNewPositionName');
            const positionName = input.value.trim();
            
            if (positionName === '') {
                showToast('Please enter a position name', 'warning');
                return;
            }

            fetch('employee.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=add_position&position_name=${encodeURIComponent(positionName)}&category=${editCurrentCategory}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    input.value = '';
                    document.getElementById('editCustomPositionInput').style.display = 'none';
                    document.getElementById('editAddPositionBtn').style.display = 'block';
                    
                    // Refresh the position list
                    loadPositionsFromDB(editCurrentCategory, 'editPositionChecklist', true);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Error adding position', 'error');
                console.error(error);
            });
        }

        function updatePositionCheckboxes() {
            // This function is kept for backward compatibility but we now use updatePositionState
        }

        function updateEditPositionCheckboxes() {
            // This function is kept for backward compatibility but we now use updatePositionState
        }

        function savePositions() {
            // Update selectedPositions from allPositionStates
            selectedPositions = [];
            for (let key in allPositionStates) {
                if (key.startsWith('add_') && allPositionStates[key]) {
                    selectedPositions.push(key.replace('add_', ''));
                }
            }
            
            // Update display
            updatePositionsDisplay();
            closePositionModal();
        }

        function saveEditPositions() {
            // Update editSelectedPositions from allPositionStates
            editSelectedPositions = [];
            for (let key in allPositionStates) {
                if (key.startsWith('edit_') && allPositionStates[key]) {
                    editSelectedPositions.push(key.replace('edit_', ''));
                }
            }
            
            // Update display
            updateEditPositionsDisplay();
            closeEditPositionModal();
        }

        function updatePositionsDisplay() {
            const displayArea = document.getElementById('selectedPositionsDisplay');
            const positionInput = document.getElementById('position');
            
            if (displayArea && positionInput) {
                displayArea.innerHTML = '';
                selectedPositions.forEach(position => {
                    const tag = document.createElement('span');
                    tag.className = 'position-tag';
                    tag.innerHTML = `${position} <i class="fas fa-times delete-position" onclick="removeSelectedPosition('${position}')"></i>`;
                    displayArea.appendChild(tag);
                });
                
                positionInput.value = selectedPositions.join(', ');
            }
        }

        function updateEditPositionsDisplay() {
            const displayArea = document.getElementById('editSelectedPositionsDisplay');
            const positionInput = document.getElementById('edit_position');
            
            if (displayArea && positionInput) {
                displayArea.innerHTML = '';
                editSelectedPositions.forEach(position => {
                    const tag = document.createElement('span');
                    tag.className = 'position-tag';
                    tag.innerHTML = `${position} <i class="fas fa-times delete-position" onclick="removeEditSelectedPosition('${position}')"></i>`;
                    displayArea.appendChild(tag);
                });
                
                positionInput.value = editSelectedPositions.join(', ');
            }
        }

        function removeSelectedPosition(position) {
            selectedPositions = selectedPositions.filter(p => p !== position);
            allPositionStates[`add_${position}`] = false;
            updatePositionsDisplay();
            
            // Uncheck the checkbox in the modal if it's open
            if (document.getElementById('positionModal').style.display === 'flex') {
                const checkboxes = document.querySelectorAll('#positionModal .position-checkbox-item input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    if (cb.dataset.name === position) {
                        cb.checked = false;
                    }
                });
            }
        }

        function removeEditSelectedPosition(position) {
            editSelectedPositions = editSelectedPositions.filter(p => p !== position);
            allPositionStates[`edit_${position}`] = false;
            updateEditPositionsDisplay();
            
            // Uncheck the checkbox in the modal if it's open
            if (document.getElementById('editPositionModal').style.display === 'flex') {
                const checkboxes = document.querySelectorAll('#editPositionModal .position-checkbox-item input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    if (cb.dataset.name === position) {
                        cb.checked = false;
                    }
                });
            }
        }

        // Close calendar when clicking outside
        document.addEventListener('click', function(e) {
            const isCalendarClick = e.target.closest('.calendar-wrapper') || e.target.closest('.date-input-group');
            if (!isCalendarClick && activeCalendar) {
                const calendar = document.getElementById(activeCalendar + 'Calendar');
                if (calendar) calendar.style.display = 'none';
                activeCalendar = null;
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (activeCalendar) {
                    const calendar = document.getElementById(activeCalendar + 'Calendar');
                    if (calendar) calendar.style.display = 'none';
                    activeCalendar = null;
                } else {
                    closeAddEmployeeModal();
                    closeViewEmployeeModal();
                    closeEditEmployeeModal();
                    closePositionModal();
                    closeEditPositionModal();
                    closeDeleteModal();
                    closeStatusModal();
                }
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeYearSelects();
            
            ['dob', 'hired', 'editDob', 'editHired'].forEach(calId => {
                updateCalendar(calId);
            });
            
            // Add validation to date inputs
            const dobInput = document.getElementById('dob');
            const dateHiredInput = document.getElementById('date_hired');
            const editDobInput = document.getElementById('edit_dob');
            const editDateHiredInput = document.getElementById('edit_date_hired');
            
            if (dobInput) {
                dobInput.addEventListener('blur', function() { validateDateField(this); });
                dobInput.addEventListener('change', function() { validateDateField(this); });
            }
            if (dateHiredInput) {
                dateHiredInput.addEventListener('blur', function() { validateDateField(this); });
                dateHiredInput.addEventListener('change', function() { validateDateField(this); });
            }
            if (editDobInput) {
                editDobInput.addEventListener('blur', function() { validateDateField(this); });
                editDobInput.addEventListener('change', function() { validateDateField(this); });
            }
            if (editDateHiredInput) {
                editDateHiredInput.addEventListener('blur', function() { validateDateField(this); });
                editDateHiredInput.addEventListener('change', function() { validateDateField(this); });
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('view') || urlParams.has('edit')) {
                document.body.classList.add('modal-open');
            }
            
            // Format input masks for government IDs
            const sssInput = document.getElementById('sss_number');
            const pagibigInput = document.getElementById('pagibig_number');
            const tinInput = document.getElementById('tin_number');
            const philhealthInput = document.getElementById('philhealth_number');
            
            const editSssInput = document.getElementById('edit_sss_number');
            const editPagibigInput = document.getElementById('edit_pagibig_number');
            const editTinInput = document.getElementById('edit_tin_number');
            const editPhilhealthInput = document.getElementById('edit_philhealth_number');
            
            function formatSSS(input) {
                let value = input.value.replace(/\D/g, '');
                if (value.length > 0) {
                    value = value.substring(0, 2) + (value.length > 2 ? '-' + value.substring(2, 9) : '') + 
                           (value.length > 9 ? '-' + value.substring(9, 10) : '');
                }
                input.value = value;
            }
            
            function formatPagibig(input) {
                let value = input.value.replace(/\D/g, '');
                if (value.length > 0) {
                    value = value.substring(0, 4) + (value.length > 4 ? '-' + value.substring(4, 8) : '') + 
                           (value.length > 8 ? '-' + value.substring(8, 12) : '');
                }
                input.value = value;
            }
            
            function formatTIN(input) {
                let value = input.value.replace(/\D/g, '');
                if (value.length > 0) {
                    value = value.substring(0, 3) + (value.length > 3 ? '-' + value.substring(3, 6) : '') + 
                           (value.length > 6 ? '-' + value.substring(6, 9) : '') + 
                           (value.length > 9 ? '-' + value.substring(9, 12) : '');
                }
                input.value = value;
            }
            
            function formatPhilhealth(input) {
                let value = input.value.replace(/\D/g, '');
                if (value.length > 0) {
                    value = value.substring(0, 2) + (value.length > 2 ? '-' + value.substring(2, 11) : '') + 
                           (value.length > 11 ? '-' + value.substring(11, 12) : '');
                }
                input.value = value;
            }
            
            if (sssInput) sssInput.addEventListener('input', function() { formatSSS(this); });
            if (pagibigInput) pagibigInput.addEventListener('input', function() { formatPagibig(this); });
            if (tinInput) tinInput.addEventListener('input', function() { formatTIN(this); });
            if (philhealthInput) philhealthInput.addEventListener('input', function() { formatPhilhealth(this); });
            
            if (editSssInput) editSssInput.addEventListener('input', function() { formatSSS(this); });
            if (editPagibigInput) editPagibigInput.addEventListener('input', function() { formatPagibig(this); });
            if (editTinInput) editTinInput.addEventListener('input', function() { formatTIN(this); });
            if (editPhilhealthInput) editPhilhealthInput.addEventListener('input', function() { formatPhilhealth(this); });
        });

        // Modal functions
        function showAddEmployeeModal() {
            document.getElementById('addEmployeeModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            document.getElementById('first_name').focus();
        }

        function closeAddEmployeeModal() {
            document.getElementById('addEmployeeModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            const form = document.querySelector('#addEmployeeModal form');
            if (form) form.reset();
            clearDate('dob');
            clearDate('hired');
            selectedPositions = [];
            // Clear all position states for add modal
            for (let key in allPositionStates) {
                if (key.startsWith('add_')) {
                    delete allPositionStates[key];
                }
            }
            updatePositionsDisplay();
        }

        function showViewEmployeeModal(employeeId) {
            window.location.href = 'employee.php?view=' + employeeId;
        }

        function closeViewEmployeeModal() {
            window.location.href = 'employee.php';
        }

        function showEditEmployeeModal(employeeId) {
            window.location.href = 'employee.php?edit=' + employeeId;
        }

        function closeEditEmployeeModal() {
            window.location.href = 'employee.php';
        }

        // Delete Modal Functions
        function showDeleteModal(employeeId, employeeName) {
            currentEmployeeId = employeeId;
            currentEmployeeName = employeeName;
            document.getElementById('deleteEmployeeName').textContent = employeeName;
            document.getElementById('deleteConfirmModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            
            // Set up confirm button
            document.getElementById('confirmDeleteBtn').onclick = function() {
                confirmDelete();
            };
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            currentEmployeeId = null;
            currentEmployeeName = '';
        }

        function confirmDelete() {
            if (!currentEmployeeId) return;
            
            fetch('employee.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=delete_employee&employee_id=${currentEmployeeId}`
            })
            .then(response => response.json())
            .then(data => {
                closeDeleteModal();
                if (data.success) {
                    showToast(data.message, 'success');
                    // Reload the page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                closeDeleteModal();
                showToast('Error deleting employee', 'error');
                console.error(error);
            });
        }

        // Status Toggle Modal Functions
        function showStatusModal(employeeId, employeeName, currentStatus) {
            currentEmployeeId = employeeId;
            currentEmployeeName = employeeName;
            currentEmployeeStatus = currentStatus;
            
            const action = currentStatus === 'active' ? 'disable' : 'enable';
            const modalHeader = document.getElementById('statusModalHeader');
            const modalTitle = document.getElementById('statusModalTitle');
            const modalMessage = document.getElementById('statusModalMessage');
            const modalWarning = document.getElementById('statusModalWarning');
            
            if (currentStatus === 'active') {
                modalHeader.className = 'confirm-modal-header warning';
                modalTitle.innerHTML = 'Confirm Disable';
                modalMessage.innerHTML = 'Are you sure you want to disable employee:';
                modalWarning.innerHTML = '<i class="fas fa-info-circle"></i> This will deactivate their account and remove them from any assigned sites.';
            } else {
                modalHeader.className = 'confirm-modal-header success';
                modalTitle.innerHTML = 'Confirm Enable';
                modalMessage.innerHTML = 'Are you sure you want to enable employee:';
                modalWarning.innerHTML = '<i class="fas fa-info-circle"></i> This will reactivate their account.';
            }
            
            document.getElementById('statusEmployeeName').textContent = employeeName;
            document.getElementById('statusConfirmModal').style.display = 'flex';
            document.body.classList.add('modal-open');
            
            // Set up confirm button
            document.getElementById('confirmStatusBtn').onclick = function() {
                confirmStatusToggle();
            };
        }

        function closeStatusModal() {
            document.getElementById('statusConfirmModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            currentEmployeeId = null;
            currentEmployeeName = '';
            currentEmployeeStatus = '';
        }

        function confirmStatusToggle() {
            if (!currentEmployeeId) return;
            
            fetch('employee.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=toggle_status&employee_id=${currentEmployeeId}&current_status=${currentEmployeeStatus}`
            })
            .then(response => response.json())
            .then(data => {
                closeStatusModal();
                if (data.success) {
                    showToast(data.message, 'success');
                    // Reload the page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                closeStatusModal();
                showToast('Error updating employee status', 'error');
                console.error(error);
            });
        }

        // Close modal when clicking outside - Disabled
        /*
        window.onclick = function(event) {
            ...
        }
        */
    </script>
</body>
</html>