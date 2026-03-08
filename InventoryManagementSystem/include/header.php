<?php
// Start output buffering if not already started
if (ob_get_level() == 0) ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/home3.css">
    <style>
        /* Additional inline styles for logo alignment */
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: transparent !important;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        /* Remove any background from logo container */
        .app-header .logo-icon {
            background: transparent !important;
            box-shadow: none;
        }
        
        /* Style for multiple logos side by side */
        .logo-images {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .logo-images img {
            height: 35px;
            width: auto;
            object-fit: contain;
        }
        
        /* Logout button style */
        .logout-btn-icon {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 8px;
        }
        
        .logout-btn-icon:hover {
            background: #d63031;
            transform: translateY(-2px);
        }
        
        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 4px 12px 4px 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .user-profile-mini:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }
        
        .header-actions-compact {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Light theme adjustments */
        body.light-theme .logo-icon {
            background: transparent !important;
        }
        
        body.light-theme .logout-btn-icon {
            background: rgba(44, 62, 80, 0.1);
            color: #2c3e50;
            border-color: rgba(44, 62, 80, 0.1);
        }
        
        body.light-theme .logout-btn-icon:hover {
            background: #d63031;
            color: white;
        }

        /* Fixed scroll bar styling - Only container should scroll */
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden; /* Prevent browser scrollbars */
        }

        /* Container - main scrollable area */
        .container {
            height: 100vh;
            overflow-y: auto; /* ONLY container scrolls */
            overflow-x: hidden;
            scrollbar-gutter: stable;
        }

        /* Main content - NO scrolling */
        .main-content {
            overflow-y: visible; /* Changed from auto to visible */
            overflow-x: visible;
            height: auto;
        }

        /* Table wrapper - horizontal scroll only */
        .table-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            scrollbar-gutter: stable;
        }

        /* Custom scrollbar styling */
        .container::-webkit-scrollbar,
        .table-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .container::-webkit-scrollbar-track,
        .table-wrapper::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }

        .container::-webkit-scrollbar-thumb,
        .table-wrapper::-webkit-scrollbar-thumb {
            background: #75e6da;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .container::-webkit-scrollbar-thumb:hover,
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #5bc8be;
        }

        /* Firefox scrollbar */
        .container,
        .table-wrapper {
            scrollbar-width: thin;
            scrollbar-color: #75e6da rgba(255, 255, 255, 0.05);
        }

        /* Light theme scrollbar */
        body.light-theme .container::-webkit-scrollbar-track,
        body.light-theme .table-wrapper::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
        }

        body.light-theme .container::-webkit-scrollbar-thumb,
        body.light-theme .table-wrapper::-webkit-scrollbar-thumb {
            background: #e84393;
        }

        body.light-theme .container::-webkit-scrollbar-thumb:hover,
        body.light-theme .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #d63031;
        }

        body.light-theme .container,
        body.light-theme .table-wrapper {
            scrollbar-color: #e84393 rgba(0, 0, 0, 0.05);
        }

        /* Navigation menu styling for 4 tabs */
        .nav-menu {
            display: flex;
            align-items: center;
            justify-content: center;
            list-style: none;
            gap: 10px;
            padding: 10px 0;
            margin: 0;
        }

        .nav-item {
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
        }

        .nav-item:hover {
            background: var(--hover-bg);
            color: var(--text-primary);
            transform: translateY(-2px);
        }

        .nav-item.active {
            background: linear-gradient(135deg, #75e6da, #6c5ce7);
            color: white;
            box-shadow: 0 4px 15px rgba(117, 230, 218, 0.3);
        }

        .nav-item a {
            color: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-item i {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Updated Header - Payroll System Style -->
        <header class="app-header">
            <div class="header-left">
                <div class="logo-section">
                    <div class="logo-images">
                        <img src="assets/images/header_logo.png" alt="JLC Logo">
                        <img src="assets/images/header_logo2.png" alt="JLC Logo 2">
                    </div>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions-compact">
                    <!-- Theme Toggle Button -->
                    <button class="header-action-btn" onclick="toggleTheme()" id="themeToggleBtn">
                        <i class="fas fa-moon"></i>
                    </button>
                    <!-- Logout Button with Icon -->
                    <div class="logout-btn-icon" onclick="openLogoutModal()" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- Simplified Navigation - Only 4 tabs -->
        <nav class="sidebar-nav">
            <ul class="nav-menu">
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>">
                    <a href="home.php"><i class="fas fa-home"></i> HOME</a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'canvas.php' ? 'active' : ''; ?>">
                    <a href="canvas.php"><i class="fas fa-box"></i> Canvas</a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'purchase.php' ? 'active' : ''; ?>">
                    <a href="purchase.php"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'stock_tracker.php' ? 'active' : ''; ?>">
                    <a href="stock_tracker.php"><i class="fas fa-chart-line"></i> Stock Monitoring</a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>">
                    <a href="products.php"><i class="fas fa-box"></i> PRODUCTS</a>
                </li>
                
        
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <script>
            // Theme management
            function toggleTheme() {
                const body = document.body;
                const themeToggleBtn = document.getElementById('themeToggleBtn');
                const icon = themeToggleBtn.querySelector('i');
                
                // Toggle theme class
                body.classList.toggle('light-theme');
                
                // Update icon
                if (body.classList.contains('light-theme')) {
                    icon.className = 'fas fa-sun';
                    localStorage.setItem('theme', 'light');
                } else {
                    icon.className = 'fas fa-moon';
                    localStorage.setItem('theme', 'dark');
                }
            }

            // Load saved theme on page load
            document.addEventListener('DOMContentLoaded', function() {
                const savedTheme = localStorage.getItem('theme');
                const themeToggleBtn = document.getElementById('themeToggleBtn');
                const icon = themeToggleBtn.querySelector('i');
                
                // Apply saved theme or default to dark
                if (savedTheme === 'light') {
                    document.body.classList.add('light-theme');
                    icon.className = 'fas fa-sun';
                } else {
                    document.body.classList.remove('light-theme');
                    icon.className = 'fas fa-moon';
                }

                // Fix for active nav item - handle home.php specifically
                const currentPage = window.location.pathname.split('/').pop();
                const navItems = document.querySelectorAll('.nav-item');
                
                navItems.forEach(item => {
                    const link = item.querySelector('a');
                    if (link) {
                        const href = link.getAttribute('href');
                        if (href === currentPage || 
                            (currentPage === '' && href === 'home.php') || 
                            (currentPage === 'index.php' && href === 'home.php')) {
                            item.classList.add('active');
                        } else {
                            item.classList.remove('active');
                        }
                    }
                });
            });

            // Fix for tab clicks - ensure theme persists when switching tabs
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    const savedTheme = localStorage.getItem('theme');
                    const hasLightTheme = document.body.classList.contains('light-theme');
                    const icon = document.getElementById('themeToggleBtn').querySelector('i');
                    
                    if (savedTheme === 'light' && !hasLightTheme) {
                        document.body.classList.add('light-theme');
                        icon.className = 'fas fa-sun';
                    } else if (savedTheme === 'dark' && hasLightTheme) {
                        document.body.classList.remove('light-theme');
                        icon.className = 'fas fa-moon';
                    }
                }
            });
            </script>