<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get user role from session
$role = $_SESSION['role'] ?? 'user';
$current_page = basename($_SERVER['PHP_SELF']);

// Include access control for menu checking
include_once("check_access.php");

// Map page filenames to display titles
$page_titles = [
    'home.php' => 'HOME',
    'attendance.php' => 'ATTENDANCE',
    'employee.php' => 'EMPLOYEE LIST',
    'site_monitoring.php' => 'EMPLOYEE TRACKING',
    
    'payrollList.php' => 'PAYROLL',
    'salarySlip.php' => 'SALARY SLIP',
    'user.php' => 'PROFILE'
];

// Get the title for the current page
$page_title = isset($page_titles[$current_page]) ? $page_titles[$current_page] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management System</title>
    <!-- Font Awesome 6 Free (Professional Icons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Header Styling with Sidebar Integration */
        .header {
            width: 100%;
            height: 140px; /* Fixed total height */
            background: linear-gradient(135deg, #75e6da 0%, #5fd6c9 100%);
            padding: 0;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-bottom: 3px solid #4bc4b5;
        }

        /* Top Row: Logo and Page Info */
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            height: 80px; /* Fixed height for top section */
            flex-shrink: 0;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .header-logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
            height: 100%;
        }

        .header-logo img {
            height: 70px;
            width: auto;
            border-radius: 10px;
            object-fit: contain;
            max-width: 120px;
        }

        .header-logo2 img {
            height: 50px;
            width: auto;
            border-radius: 10px;
            object-fit: contain;
            max-width: 300px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            height: 100%;
        }

        .current-page-text {
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            padding: 10px 25px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            min-width: 180px;
            text-align: center;
            white-space: nowrap;
            backdrop-filter: blur(5px);
        }

        .logout-icon {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            position: relative;
            text-decoration: none;
        }

        .logout-icon i {
            font-size: 28px;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 12px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .logout-icon i:hover {
            transform: scale(1.1);
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: #ffffff;
        }

        /* Tooltip Styles */
        .logout-icon::after {
            content: "Logout";
            position: absolute;
            bottom: -35px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3e50;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 1001;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .logout-icon:hover::after {
            opacity: 1;
            visibility: visible;
            bottom: -40px;
        }

        /* Navigation Menu */
        .header-nav {
            display: flex;
            justify-content: center;
            background: linear-gradient(135deg, #62d4c8 0%, #52c4b8 100%);
            padding: 0 20px;
            flex-shrink: 0;
            height: 70px;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
        }

        .nav-menu {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            width: 100%;
            justify-content: space-around;
            align-items: center;
            gap: 10px;
        }

        .nav-menu li {
            flex: 1;
            text-align: center;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .nav-menu li a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 12px 15px;
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            border-radius: 12px;
            margin: 0 5px;
            white-space: nowrap;
            font-size: 1rem;
            height: 50px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 200px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
        }

        /* Icon styling - Font Awesome with white color */
        .nav-menu li a i {
            font-size: 20px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            color: #ffffff;
            filter: drop-shadow(1px 1px 2px rgba(0, 0, 0, 0.2));
        }

        /* Hover effect - Icon animation */
        .nav-menu li a:hover i {
            transform: scale(1.15) rotate(3deg);
            color: #ffffff;
        }

        /* Active state icon */
        .nav-menu li.active a i {
            transform: scale(1.1);
            color: #ffffff;
        }

        .nav-menu li a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .nav-menu li a:hover::before {
            left: 100%;
        }

        .nav-menu li a:hover {
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            transform: translateY(-3px);
            border: 2px solid rgba(255, 255, 255, 0.5);
            color: #ffffff;
        }

        .nav-menu li.active a {
            background: linear-gradient(135deg, #2a9d8f 0%, #1d7873 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(42, 157, 143, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.3);
            font-weight: 700;
            position: relative;
        }

        .nav-menu li.active a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 3px;
            background: #ffffff;
            border-radius: 2px;
        }

        /* Tooltip styles for nav items */
        .nav-menu li .tooltip-text {
            position: absolute;
            bottom: -35px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3e50;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 1001;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .nav-menu li:hover .tooltip-text {
            opacity: 1;
            visibility: visible;
            bottom: -40px;
        }

        /* Enhanced Logout Modal */
        .logout-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            z-index: 2000;
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .logout-content {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdfb 100%);
            padding: 40px;
            width: 450px;
            max-width: 90%;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 3px solid #75e6da;
            transform: scale(0.9);
            animation: scaleIn 0.3s ease forwards;
            position: relative;
            overflow: hidden;
        }

        .logout-content::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(117, 230, 218, 0.1) 50%,
                transparent 70%
            );
            animation: shimmer 3s infinite;
        }

        @keyframes scaleIn {
            to { transform: scale(1); }
        }

        @keyframes shimmer {
            0% { transform: translate(-30%, -30%) rotate(0deg); }
            100% { transform: translate(30%, 30%) rotate(180deg); }
        }

        .logout-icon-large {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #75e6da 0%, #5fd6c9 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 20px rgba(117, 230, 218, 0.3);
            border: 3px solid rgba(255, 255, 255, 0.5);
        }

        .logout-icon-large i {
            font-size: 40px;
            color: white;
        }

        .logout-header {
            font-size: 2rem;
            margin-bottom: 15px;
            font-weight: 800;
            color: #2c3e50;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .logout-content p {
            font-size: 1.1rem;
            color: #4a5568;
            margin-bottom: 30px;
            font-weight: 500;
            line-height: 1.6;
            padding: 0 20px;
        }

        .logout-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
        }

        .logout-content button {
            font-weight: 600;
            padding: 14px 35px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 140px;
            position: relative;
            overflow: hidden;
        }

        .logout-content button:first-child {
            background: linear-gradient(135deg, #2a9d8f 0%, #1d7873 100%);
            color: white;
            border: 2px solid #1d7873;
            box-shadow: 0 4px 15px rgba(42, 157, 143, 0.3);
        }

        .logout-content button:first-child:hover {
            background: linear-gradient(135deg, #1d7873 0%, #2a9d8f 100%);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(42, 157, 143, 0.4);
        }

        .logout-content button:first-child:active {
            transform: translateY(-1px);
        }

        .logout-content button:last-child {
            background: white;
            color: #e53e3e;
            border: 2px solid #e53e3e;
            box-shadow: 0 4px 15px rgba(229, 62, 62, 0.1);
        }

        .logout-content button:last-child:hover {
            background: #e53e3e;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(229, 62, 62, 0.3);
        }

        .logout-content button:last-child:active {
            transform: translateY(-1px);
        }

        .logout-content button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .logout-content button:hover::before {
            width: 300px;
            height: 300px;
        }

        /* Responsive Design - Preserved */
        @media (max-width: 1200px) {
            .header {
                height: 135px;
            }
            
            .header-top {
                height: 65px;
            }
            
            .nav-menu li a {
                font-size: 0.95rem;
                padding: 10px 12px;
                gap: 10px;
            }
            
            .nav-menu li a i {
                font-size: 18px;
                width: 22px;
                height: 22px;
            }
            
            .current-page-text {
                font-size: 1.4rem;
                padding: 9px 20px;
                min-width: 160px;
            }
            
            .header-logo img {
                height: 65px;
            }
            
            .header-logo2 img {
                height: 55px;
                max-width: 160px;
            }
            
            .header-nav {
                height: 70px;
            }
            
            .logout-icon i {
                font-size: 26px;
                width: 45px;
                height: 45px;
            }
        }

        @media (max-width: 992px) {
            .header {
                height: 150px;
            }
            
            .header-top {
                height: 70px;
            }
            
            .nav-menu {
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .nav-menu li {
                flex: 0 0 25%;
                margin-bottom: 5px;
            }
            
            .header-logo-container {
                gap: 10px;
            }
            
            .header-logo img {
                height: 60px;
            }
            
            .header-logo2 img {
                height: 50px;
                max-width: 140px;
            }
            
            .logout-icon i {
                font-size: 24px;
                width: 40px;
                height: 40px;
            }
            
            .header-nav {
                height: 80px;
            }
            
            .nav-menu li a {
                max-width: 180px;
            }
            
            .logout-content {
                width: 400px;
                padding: 35px;
            }
        }

        @media (max-width: 768px) {
            .header {
                height: 140px;
                padding: 0;
            }
            
            .header-top {
                padding: 12px 15px;
                height: 60px;
            }
            
            .nav-menu li {
                flex: 0 0 33.33%;
            }
            
            .current-page-text {
                font-size: 1.2rem;
                padding: 8px 15px;
                min-width: 140px;
            }
            
            .header-logo-container {
                flex: 1;
                justify-content: flex-start;
            }
            
            .header-right {
                flex: 1;
                justify-content: flex-end;
                gap: 15px;
            }
            
            .header-logo img {
                height: 55px;
                max-width: 100px;
            }
            
            .header-logo2 img {
                height: 45px;
                max-width: 120px;
            }
            
            .header-nav {
                height: 80px;
                padding: 0 8px;
            }
            
            .nav-menu li a {
                font-size: 0.9rem;
                gap: 8px;
                padding: 8px 10px;
            }
            
            .nav-menu li a i {
                font-size: 16px;
                width: 20px;
                height: 20px;
            }
            
            .logout-header {
                font-size: 1.8rem;
            }
            
            .logout-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .logout-content button {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .header {
                height: 160px;
            }
            
            .header-top {
                height: 70px;
                flex-direction: row;
                padding: 10px 12px;
                gap: 8px;
            }
            
            .nav-menu li {
                flex: 0 0 50%;
            }
            
            .nav-menu li a {
                flex-direction: column;
                padding: 8px 5px;
                font-size: 0.85rem;
                height: 45px;
                gap: 4px;
            }
            
            .nav-menu li a i {
                margin-right: 0;
                margin-bottom: 4px;
                font-size: 18px;
                width: 20px;
                height: 20px;
            }
            
            .current-page-text {
                font-size: 1rem;
                padding: 7px 12px;
                min-width: 120px;
            }
            
            .logout-icon i {
                font-size: 22px;
                width: 36px;
                height: 36px;
                padding: 8px;
            }
            
            .header-logo-container {
                width: auto;
                justify-content: flex-start;
                gap: 6px;
            }
            
            .header-logo img {
                height: 50px;
            }
            
            .header-logo2 img {
                height: 40px;
                max-width: 110px;
            }
            
            .header-right {
                width: auto;
                justify-content: flex-end;
            }
            
            .header-nav {
                height: 90px;
            }
            
            .nav-menu li .tooltip-text {
                font-size: 10px;
                padding: 4px 8px;
            }
            
            .logout-content {
                padding: 30px 20px;
            }
            
            .logout-header {
                font-size: 1.5rem;
            }
            
            .logout-icon-large {
                width: 60px;
                height: 60px;
            }
            
            .logout-icon-large i {
                font-size: 30px;
            }
        }

        @media (max-width: 400px) {
            .header {
                height: 170px;
            }
            
            .header-top {
                height: 75px;
            }
            
            .nav-menu li a {
                font-size: 0.8rem;
                padding: 6px 4px;
            }
            
            .nav-menu li a i {
                font-size: 16px;
                width: 18px;
                height: 18px;
            }
            
            .current-page-text {
                font-size: 0.9rem;
                min-width: 100px;
                padding: 6px 10px;
            }
            
            .logout-icon i {
                font-size: 20px;
                width: 32px;
                height: 32px;
                padding: 6px;
            }
            
            .header-logo img {
                height: 45px;
                max-width: 90px;
            }
            
            .header-logo2 img {
                height: 35px;
                max-width: 100px;
            }
            
            .header-logo-container {
                gap: 5px;
            }
            
            .header-nav {
                height: 95px;
            }
            
            .logout-content {
                padding: 25px 15px;
            }
            
            .logout-header {
                font-size: 1.3rem;
            }
            
            .logout-content p {
                font-size: 1rem;
                padding: 0 10px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <!-- Top Row: Logo and Page Info -->
        <div class="header-top">
            <div class="header-logo-container">
                <div class="header-logo">
                    <img src="assets/images/header_logo.png" alt="Main Logo">
                </div>
                <div class="header-logo2">
                    <img src="assets/images/header_logo2.png" alt="Secondary Logo">
                </div>
            </div>
            
            <div class="header-right">
                <span class="current-page-text"><?php echo $page_title ?: 'DASHBOARD'; ?></span>
                <a href="#" class="logout-icon" onclick="showLogoutConfirmation(event)">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
        
        <!-- Navigation Menu (Dynamic based on user role) -->
        <nav class="header-nav">
            <ul class="nav-menu">
                <!-- Home - Accessible to all -->
                <?php if (function_exists('checkPageAccess') && checkPageAccess('home.php')): ?>
                <li class="<?php echo ($current_page == 'home.php') ? 'active' : ''; ?>">
                    <a href="home.php">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                    <span class="tooltip-text">Dashboard</span>
                </li>
                <?php endif; ?>
                
                <!-- Attendance - Accessible to all -->
                <?php if (function_exists('checkPageAccess') && checkPageAccess('attendance.php')): ?>
                <li class="<?php echo ($current_page == 'attendance.php') ? 'active' : ''; ?>">
                    <a href="attendance.php">
                        <i class="fas fa-calendar-check"></i>
                        <span>Attendance</span>
                    </a>
                    <span class="tooltip-text">Mark Attendance</span>
                </li>
                <?php endif; ?>
                
                <!-- Employees - Accessible to all -->
                <?php if (function_exists('checkPageAccess') && checkPageAccess('employee.php')): ?>
                <li class="<?php echo ($current_page == 'employee.php') ? 'active' : ''; ?>">
                    <a href="employee.php">
                        <i class="fas fa-users"></i>
                        <span>Employees</span>
                    </a>
                    <span class="tooltip-text">View Employees</span>
                </li>
                <?php endif; ?>
                
                <!-- Site Monitoring - Accessible to all -->
                <?php if (function_exists('checkPageAccess') && checkPageAccess('site_monitoring.php')): ?>
                <li class="<?php echo ($current_page == 'site_monitoring.php') ? 'active' : ''; ?>">
                    <a href="site_monitoring.php">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>Employee Tracking</span>
                    </a>
                    <span class="tooltip-text">Track Location</span>
                </li>
                <?php endif; ?>
                
                <!-- Payroll - Admin and CEO only -->
                <?php if ($role === 'admin' || $role === 'ceo'): ?>
                <li class="<?php echo ($current_page == 'payrollList.php') ? 'active' : ''; ?>">
                    <a href="payrollList.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Payroll</span>
                    </a>
                    <span class="tooltip-text">Manage Payroll</span>
                </li>
                <?php endif; ?>
                
                <!-- Salary Slip - Admin and CEO only -->
                <?php if ($role === 'admin' || $role === 'ceo'): ?>
                <li class="<?php echo ($current_page == 'salarySlip.php') ? 'active' : ''; ?>">
                    <a href="salarySlip.php">
                        <i class="fas fa-file-invoice"></i>
                        <span>Salary Slip</span>
                    </a>
                    <span class="tooltip-text">Generate Slips</span>
                </li>
                <?php endif; ?>
                
                <!-- Profile - Accessible to ALL roles -->
                <li class="<?php echo ($current_page == 'user.php') ? 'active' : ''; ?>">
                    <a href="user.php">
                        <i class="fas fa-user-circle"></i>
                        <span>Profile</span>
                    </a>
                    <span class="tooltip-text">Your Profile</span>
                </li>
            </ul>
        </nav>
    </header>

    <!-- Enhanced Logout Confirmation Modal (No X button) -->
    <div id="logoutOverlay" class="logout-overlay">
        <div class="logout-content">
            <div class="logout-icon-large">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <div class="logout-header">Confirm logout?</div>
            <p>Are you sure you want to logout from your account?</p>
            <div class="logout-buttons">
                <button onclick="confirmLogout()">Confirm</button>
                <button onclick="cancelLogout()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        function showLogoutConfirmation(event) {
            event.preventDefault();
            document.getElementById('logoutOverlay').style.display = 'flex';
        }

        function confirmLogout() {
            // Redirect to logout.php which will destroy session and redirect to login
            window.location.href = 'logout.php';
        }

        function cancelLogout() {
            document.getElementById('logoutOverlay').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('logoutOverlay');
            if (event.target === modal) {
                cancelLogout();
            }
        }
        
        // Ensure content starts below header
        document.addEventListener('DOMContentLoaded', function() {
            const header = document.querySelector('.header');
            const content = document.querySelector('.content');
            
            if (header && content) {
                const headerHeight = header.offsetHeight;
                document.documentElement.style.setProperty('--header-height', headerHeight + 'px');
            }
        });
    </script>
</body>
</html>