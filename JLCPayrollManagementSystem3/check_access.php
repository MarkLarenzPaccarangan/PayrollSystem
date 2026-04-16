<?php
/**
 * check_access.php
 * Access control functions for user roles
 */

// Define page access permissions based on user roles
$page_access = [
    // Public pages - accessible to all authenticated users
    'home.php' => ['admin', 'ceo', 'user'],
    'attendance.php' => ['admin', 'ceo', 'user'],
    'employee.php' => ['admin', 'ceo', 'user'],
    'site_monitoring.php' => ['admin', 'ceo', 'user'],
    'user.php' => ['admin', 'ceo', 'user'],
    
    // Admin only pages
    'deductionList.php' => ['admin'],
    'payrollList.php' => ['admin'],
    'salarySlip.php' => ['admin'],
    'deduction.php' => ['admin'],
    'payroll.php' => ['admin'],
    
    // Reports - accessible to admin and ceo
    'reports.php' => ['admin', 'ceo'],
    'generate_report.php' => ['admin', 'ceo'],
    
    // API/Backend files - these should not be accessed directly
    'add_attendance.php' => ['admin', 'ceo', 'user'],
    'get_attendance.php' => ['admin', 'ceo', 'user'],
    'check_attendance.php' => ['admin', 'ceo', 'user'],
    'get_site_employees.php' => ['admin', 'ceo', 'user'],
    'get_available_employees.php' => ['admin', 'ceo', 'user'],
    'logout.php' => ['admin', 'ceo', 'user'], // Public - accessible to all logged in users
];

/**
 * Check if current user has access to the specified page
 * 
 * @param string $page The page filename to check access for
 * @return bool True if user has access, false otherwise
 */
function checkPageAccess($page) {
    global $page_access;
    
    // Get user role from session
    $role = $_SESSION['role'] ?? 'user';
    
    // If page is not in the access list, default to true (allow access)
    if (!isset($page_access[$page])) {
        error_log("Page '$page' not defined in access control list - allowing access by default");
        return true;
    }
    
    // Check if user's role is allowed for this page
    return in_array($role, $page_access[$page]);
}

/**
 * Get all accessible pages for current user
 * 
 * @return array List of pages accessible to current user
 */
function getAccessiblePages() {
    global $page_access;
    
    $role = $_SESSION['role'] ?? 'user';
    $accessible = [];
    
    foreach ($page_access as $page => $roles) {
        if (in_array($role, $roles)) {
            $accessible[] = $page;
        }
    }
    
    return $accessible;
}

/**
 * Redirect to home page if user doesn't have access
 * 
 * @param string $page The page to check access for
 */
function requireAccess($page) {
    if (!checkPageAccess($page)) {
        header("Location: home.php?error=access_denied");
        exit;
    }
}

/**
 * Get the user's role name in readable format
 * 
 * @return string Formatted role name
 */
function getUserRoleName() {
    $role = $_SESSION['role'] ?? 'user';
    
    $role_names = [
        'admin' => 'Administrator',
        'ceo' => 'Chief Executive Officer',
        'user' => 'Regular User'
    ];
    
    return $role_names[$role] ?? ucfirst($role);
}

// If this file is accessed directly, redirect to home
if (basename($_SERVER['PHP_SELF']) == 'check_access.php') {
    header("Location: home.php");
    exit;
}
?>