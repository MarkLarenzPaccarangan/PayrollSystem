<?php 
session_start();

$error_message = '';
$success_message = '';
$show_login_form = false;
$show_create_form = false;
$show_security_form = false;
$selected_role = '';

// Default to showing admin login form
$show_login_form = true;
$selected_role = 'admin';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_direct'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $role = $_POST['role'];
    
    include_once("connection.php");
    
    $authenticated = false;
    $user_data = [];
    
    // Check based on role
    if ($role === 'admin') {
        // Check super_user table for admin
        $stmt = $conn->prepare("SELECT id, name, username, password, 'admin' as role, 'super_user' as user_type FROM super_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            // In a real application, use password_verify() if passwords are hashed
            if ($password === $user['password']) {
                $authenticated = true;
                $user_data = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['name'],
                    'role' => 'admin',
                    'user_type' => 'super_user'
                ];
            }
        }
        $stmt->close();
    } else {
        // Check user_accounts table for ceo and user
        // Check if table exists first
        $table_check = $conn->query("SHOW TABLES LIKE 'user_accounts'");
        if ($table_check->num_rows > 0) {
            // Check if is_active column exists
            $column_check = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'is_active'");
            
            if ($column_check->num_rows > 0) {
                // May is_active column
                $stmt = $conn->prepare("SELECT id, username, password_hash as password, full_name as name, role, 'user_account' as user_type FROM user_accounts WHERE username = ? AND role = ? AND is_active = 1");
            } else {
                // Walang is_active column
                $stmt = $conn->prepare("SELECT id, username, password_hash as password, full_name as name, role, 'user_account' as user_type FROM user_accounts WHERE username = ? AND role = ?");
            }
            
            $stmt->bind_param("ss", $username, $role);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                // In a real application, use password_verify() if passwords are hashed
                if ($password === $user['password']) {
                    $authenticated = true;
                    $user_data = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['name'],
                        'role' => $user['role'],
                        'user_type' => 'user_account'
                    ];
                    
                    // Update last login - check if column exists
                    $column_check2 = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'last_login'");
                    if ($column_check2->num_rows > 0) {
                        $update = $conn->prepare("UPDATE user_accounts SET last_login = NOW() WHERE id = ?");
                        $update->bind_param("i", $user['id']);
                        $update->execute();
                        $update->close();
                    }
                }
            }
            $stmt->close();
        } else {
            // Create user_accounts table if it doesn't exist
            $conn->query("CREATE TABLE IF NOT EXISTS user_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                role ENUM('ceo','user') NOT NULL,
                is_active TINYINT DEFAULT 1,
                security_answer1 VARCHAR(255),
                security_answer2 VARCHAR(255),
                security_answer3 VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL
            )");
            
            $error_message = "User accounts table created. Please try login again.";
        }
    }
    
    $conn->close();
    
    // Set session if authenticated
    if ($authenticated) {
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['Admin_User'] = $user_data['username']; // This is the key your home.php checks for
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['full_name'] = $user_data['full_name'];
        $_SESSION['role'] = $user_data['role']; // Dapat may value ito (admin, ceo, user)
        $_SESSION['user_type'] = $user_data['user_type'];
        
        // Redirect to home.php upon successful login
        header("Location: home.php");
        exit;
    } else {
        $error_message = "Invalid username or password for selected role.";
        $show_login_form = true;
        $selected_role = $role;
    }
}

// Handle create account form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account_direct'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $answer1 = trim($_POST['answer1']);
    $answer2 = trim($_POST['answer2']);
    $answer3 = trim($_POST['answer3']);
    
    if ($password !== $confirm_password) {
        $error_message = "Passwords do not match!";
        $show_create_form = true;
        $selected_role = $role;
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters!";
        $show_create_form = true;
        $selected_role = $role;
    } else {
        include_once("connection.php");
        
        // Check if user_accounts table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'user_accounts'");
        if ($table_check->num_rows == 0) {
            // Create user_accounts table if it doesn't exist
            $conn->query("CREATE TABLE IF NOT EXISTS user_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                role ENUM('ceo','user') NOT NULL,
                is_active TINYINT DEFAULT 1,
                security_answer1 VARCHAR(255),
                security_answer2 VARCHAR(255),
                security_answer3 VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL
            )");
        }
        
        // Check if username exists
        $check = $conn->prepare("SELECT id FROM user_accounts WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $error_message = "Username already exists!";
            $show_create_form = true;
            $selected_role = $role;
        } else {
            // Check if is_active column exists
            $column_check = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'is_active'");
            
            if ($column_check->num_rows > 0) {
                // May is_active column
                $stmt = $conn->prepare("INSERT INTO user_accounts (username, password_hash, full_name, email, role, is_active, security_answer1, security_answer2, security_answer3, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())");
                $stmt->bind_param("ssssssss", $username, $password, $full_name, $email, $role, $answer1, $answer2, $answer3);
            } else {
                // Walang is_active column
                $stmt = $conn->prepare("INSERT INTO user_accounts (username, password_hash, full_name, email, role, security_answer1, security_answer2, security_answer3, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssssssss", $username, $password, $full_name, $email, $role, $answer1, $answer2, $answer3);
            }
            
            if ($stmt->execute()) {
                $success_message = "Account created successfully! You can now login as " . strtoupper($role) . ".";
                // Show login form after successful account creation
                $show_login_form = true;
                $show_create_form = false;
                $selected_role = $role;
            } else {
                $error_message = "Error creating account: " . $conn->error;
                $show_create_form = true;
                $selected_role = $role;
            }
        }
        
        $check->close();
        $stmt->close();
        $conn->close();
    }
}

// Handle form display from URL
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'login') {
        $show_login_form = true;
        $show_create_form = false;
        $show_security_form = false;
        $selected_role = $_GET['role'] ?? 'admin';
    } elseif ($_GET['action'] === 'create') {
        $show_create_form = true;
        $show_login_form = false;
        $show_security_form = false;
        $selected_role = $_GET['role'] ?? 'user';
    }
}

if (isset($_GET['forgot_password']) && $_GET['forgot_password'] === 'true') {
    $show_security_form = true;
    $show_login_form = false;
    $show_create_form = false;
    
    // Get the role from URL parameter
    $reset_role = isset($_GET['role']) ? $_GET['role'] : $selected_role;
    
    // Kung may error sa verification
    if (isset($_GET['error'])) {
        $error_message = "Incorrect answers. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JLC Payroll System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .background {
            width: 100%; min-height: 100vh;
            background-image: url('assets/images/logo3.png');
            background-repeat: no-repeat; background-position: center; background-size: cover;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        
        /* Header with buttons only */
        .login-header {
            width: 100%;
            padding: 20px 40px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            background: transparent;
        }
        
        .button-area {
            display: flex;
            gap: 15px;
        }
        
        .btn-header {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-login {
            background: white;
            color: #2E7D32;
            border: 2px solid #2E7D32;
        }
        
        .btn-login:hover {
            background: #2E7D32;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(46, 125, 50, 0.3);
        }
        
        .btn-create {
            background: linear-gradient(135deg, #39a369, #39a369);
            color: white;
            border: 2px solid transparent;
        }
        
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(46, 125, 50, 0.4);
        }
        
        .btn-active {
            background: #39a369 !important;
            color: white !important;
            border-color: #39a369 !important;
        }
        
        /* Dropdown Menu */
        .dropdown-menu-custom {
            min-width: 300px;
            padding: 15px;
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            margin-top: 15px !important;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(117, 230, 218, 0.2);
        }
        
        .role-option {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 8px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        
        .role-option:hover {
            background: linear-gradient(135deg, #75e6da10, #75e6da20);
            transform: translateX(8px) scale(1.02);
            box-shadow: 0 8px 20px rgba(117, 230, 218, 0.15);
        }
        
        .role-option-icon {
            width: 50px;
            height: 50px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 28px;
            color: #2c3e50;
            background: #75e6da;
            box-shadow: 0 6px 12px rgba(117, 230, 218, 0.3);
            transition: all 0.3s ease;
        }
        
        .role-option:hover .role-option-icon {
            transform: rotate(5deg) scale(1.1);
            box-shadow: 0 8px 16px rgba(117, 230, 218, 0.4);
        }
        
        .role-option-info h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: 0.5px;
        }
        
        .role-option-info p {
            margin: 5px 0 0;
            font-size: 12px;
            color: #64748b;
        }
        
        /* Main Content Area */
        .main-content {
            width: 100%;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 100px 60px 20px 20px;
        }
        
        /* Form Container - SAME SIZE for both Login and Create Account */
        .form-container {
            width: 100%;
            max-width: 400px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: slideIn 0.5s ease;
            border: 1px solid rgba(117, 230, 218, 0.3);
        }
        
        /* For create account form - mas maikli na height */
        .form-container.create-form {
            max-height: 550px;
        }
        
        .form-body {
            padding: 20px 25px;
        }
        
        /* Scrollable content for create account - adjusted height */
        .scrollable-content {
            max-height: 280px;
            overflow-y: auto;
            padding-right: 8px;
            margin-bottom: 10px;
        }
        
        /* Custom scrollbar */
        .scrollable-content::-webkit-scrollbar {
            width: 5px;
        }
        
        .scrollable-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .scrollable-content::-webkit-scrollbar-thumb {
            background: #75e6da;
            border-radius: 10px;
        }
        
        .scrollable-content::-webkit-scrollbar-thumb:hover {
            background: #5bc5b9;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(40px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 5px;
            display: block;
            font-size: 12px;
            letter-spacing: 0.3px;
        }
        
        .form-control {
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 13px;
            transition: all 0.3s ease;
            width: 100%;
            background: white;
        }
        
        .form-control:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
            outline: none;
            transform: translateY(-1px);
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #75e6da;
            color: #2c3e50;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 8px 16px rgba(117, 230, 218, 0.2);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(117, 230, 218, 0.3);
            background: #5bc5b9;
        }
        
        .btn-submit i {
            font-size: 16px;
        }
        
        /* Back button - may kulay gaya ng Create Account button */
        .btn-back {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #39a369, #39a369);
            color: white;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            text-decoration: none;
            box-shadow: 0 8px 16px rgba(46, 125, 50, 0.2);
        }
        
        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(46, 125, 50, 0.3);
            background: linear-gradient(135deg, #1b5e20, #1b5e20);
            text-decoration: none;
            color: white;
        }
        
        .btn-back i {
            font-size: 16px;
            color: white;
        }
        
        /* Forgot Password Link - NAKA-CENTER */
        .forgot-password-link {
            text-align: center;
            margin-top: 15px;
        }
        
        .forgot-password-link a {
            color: #75e6da;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 30px;
            background: rgba(117, 230, 218, 0.05);
            width: auto;
            margin: 0 auto;
        }
        
        .forgot-password-link a:hover {
            color: #5bc5b9;
            background: rgba(117, 230, 218, 0.1);
            transform: translateY(-2px);
            text-decoration: none;
        }
        
        .alert {
            border-radius: 12px;
            padding: 12px 15px;
            margin-bottom: 15px;
            border: none;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert-success {
            background: rgba(117, 230, 218, 0.1);
            color: #2c3e50;
            border-left: 4px solid #75e6da;
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border-left: 4px solid #b91c1c;
        }
        
        /* Role Badge - mas maliit */
        .role-badge {
            display: flex;
            align-items: center;
            gap: 15px;
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 15px 18px;
            border-radius: 18px;
            margin-bottom: 15px;
            border: 2px solid rgba(117, 230, 218, 0.3);
            box-shadow: 0 5px 15px rgba(0,0,0,0.02);
        }
        
        .role-badge-icon {
            width: 55px;
            height: 55px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: #2c3e50;
            background: #75e6da;
            box-shadow: 0 8px 16px rgba(117, 230, 218, 0.3);
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-3px); }
        }
        
        .role-badge-text {
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
            letter-spacing: 0.5px;
        }
        
        .role-badge small {
            font-size: 11px;
            color: #64748b;
            display: block;
            font-weight: 400;
        }
        
        /* Security Questions Section - mas compact */
        .security-section {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 15px;
            border-radius: 15px;
            margin: 10px 0 5px;
            border: 2px solid rgba(117, 230, 218, 0.2);
        }
        
        .security-title {
            color: #1e293b;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .security-title i {
            color: #75e6da;
            font-size: 14px;
        }
        
        /* Role Indicator - mas maliit */
        .role-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: rgba(117, 230, 218, 0.05);
            border-radius: 25px;
            font-size: 11px;
            color: #475569;
            border: 1px solid rgba(117, 230, 218, 0.2);
        }
        
        .role-indicator i {
            color: #75e6da;
            font-size: 12px;
        }
        
        .role-indicator strong {
            color: #2c3e50;
            font-weight: 700;
        }
        
        /* Button group */
        .button-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .login-header {
                padding: 15px 20px;
                justify-content: center;
            }
            
            .button-area {
                width: 100%;
                justify-content: center;
            }
            
            .btn-header {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .success-message {
                min-width: 90%;
                top: 80px;
            }
            
            .main-content {
                justify-content: center;
                padding: 100px 20px 20px;
            }
            
            .form-container {
                max-width: 340px;
            }
            
            .form-container.create-form {
                max-height: 520px;
            }
            
            .scrollable-content {
                max-height: 250px;
            }
            
            .role-badge-icon {
                width: 50px;
                height: 50px;
                font-size: 26px;
            }
            
            .role-badge-text {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="background">
        <!-- Header with Login and Create Account Buttons (with Dropdown) -->
        <div class="login-header">
            <div class="button-area">
                <!-- Login Button with Dropdown -->
                <div class="dropdown">
                    <button class="btn-header btn-login <?php echo $show_login_form ? 'btn-active' : ''; ?>" id="loginDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                    <div class="dropdown-menu dropdown-menu-custom" aria-labelledby="loginDropdown">
                        <h6 style="padding: 10px 15px; color: #64748b; font-weight: 600; margin: 0; font-size: 13px; letter-spacing: 0.5px;">SELECT ROLE TO LOGIN</h6>
                        
                        <!-- Administrator -->
                        <div class="role-option" onclick="window.location.href='?action=login&role=admin'">
                            <div class="role-option-icon">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="role-option-info">
                                <h4>Administrator</h4>
                                <p>System management & configuration</p>
                            </div>
                        </div>
                        
                        <!-- CEO -->
                        <div class="role-option" onclick="window.location.href='?action=login&role=ceo'">
                            <div class="role-option-icon">
                                <i class="fas fa-briefcase"></i>
                            </div>
                            <div class="role-option-info">
                                <h4>Chief Executive Officer</h4>
                                <p>Business analytics & overview</p>
                            </div>
                        </div>
                        
                        <!-- Engineer - CONSTRUCTION WORKER ICON -->
                        <div class="role-option" onclick="window.location.href='?action=login&role=user'">
                            <div class="role-option-icon">
                                <i class="fas fa-hard-hat"></i> 
                            </div>
                            <div class="role-option-info">
                                <h4>Lead Engineer</h4>
                                <p>Technical operations & projects</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Create Account Button with Dropdown -->
                <div class="dropdown">
                    <button class="btn-header btn-create <?php echo $show_create_form ? 'btn-active' : ''; ?>" id="createDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                    <div class="dropdown-menu dropdown-menu-custom" aria-labelledby="createDropdown">
                        <h6 style="padding: 10px 15px; color: #64748b; font-weight: 600; margin: 0; font-size: 13px; letter-spacing: 0.5px;">SELECT ROLE TO CREATE</h6>
                        
                        <!-- Administrator -->
                        <div class="role-option" onclick="window.location.href='?action=create&role=admin'">
                            <div class="role-option-icon">
                                <i class="fas fa-vault"></i>
                            </div>
                            <div class="role-option-info">
                                <h4>Administrator</h4>
                                <p>Create system administrator</p>
                            </div>
                        </div>
                        
                        <!-- CEO -->
                        <div class="role-option" onclick="window.location.href='?action=create&role=ceo'">
                            <div class="role-option-icon">
                                <i class="fas fa-landmark"></i>
                            </div>
                            <div class="role-option-info">
                                <h4>Chief Executive Officer</h4>
                                <p>Create executive account</p>
                            </div>
                        </div>
                        
                        <!-- Engineer - CONSTRUCTION WORKER ICON -->
                        <div class="role-option" onclick="window.location.href='?action=create&role=user'">
                            <div class="role-option-icon">
                                <i class="fas fa-hard-hat"></i> 
                            </div>
                            <div class="role-option-info">
                                <h4>Lead Engineer</h4>
                                <p>Create engineering account</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Success Message (if any) -->
        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Main Content Area - Form on the RIGHT side -->
        <div class="main-content">
            <!-- LOGIN FORM - WITH HISTORY TEXT REMOVED FROM USERNAME -->
            <?php if ($show_login_form): ?>
            <div class="form-container">
                <div class="form-body">
                    <!-- Role Badge -->
                    <div class="role-badge">
                        <div class="role-badge-icon">
                            <?php if ($selected_role === 'admin'): ?>
                                <i class="fas fa-server"></i>
                            <?php elseif ($selected_role === 'ceo'): ?>
                                <i class="fas fa-briefcase"></i>
                            <?php else: ?>
                                <i class="fas fa-hard-hat"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <small>ACCESS LEVEL</small>
                            <div class="role-badge-text">
                                <?php 
                                    echo $selected_role === 'admin' ? 'ADMINISTRATOR' : 
                                        ($selected_role === 'ceo' ? 'CHIEF EXECUTIVE' : 'LEAD ENGINEER'); 
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Role Indicator -->
                    <div class="role-indicator">
                        <i class="fas fa-fingerprint"></i>
                        <span>Authenticating as <strong><?php echo !empty($selected_role) ? strtoupper($selected_role) : 'USER'; ?></strong></span>
                    </div>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">
                        <input type="hidden" name="login_direct" value="1">
                        
                        <div class="form-group">
                            <label><i class="fas fa-user-circle" style="color: #75e6da;"></i> Username</label>
                            <input type="text" class="form-control" name="username" placeholder="Enter your username" value="" autocomplete="off" required autofocus>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock" style="color: #75e6da;"></i> Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                        </div>
                        
                        <button type="submit" class="btn-submit">
                            <?php if ($selected_role === 'admin'): ?>
                                <i class="fas fa-server"></i>
                            <?php elseif ($selected_role === 'ceo'): ?>
                                <i class="fas fa-briefcase"></i>
                            <?php else: ?>
                                <i class="fas fa-hard-hat"></i>
                            <?php endif; ?>
                            Access as <?php 
                                echo $selected_role === 'admin' ? 'Administrator' : 
                                    ($selected_role === 'ceo' ? 'CEO' : 'Engineer'); 
                            ?>
                        </button>
                        
                        <!-- Forgot Password Link - WITH ROLE PARAMETER -->
                        <div class="forgot-password-link">
                            <a href="?forgot_password=true&role=<?php echo $selected_role; ?>">
                                <i class="fas fa-key"></i> Reset credentials
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- CREATE ACCOUNT FORM - MAS MAIKLI at MAY BACK BUTTON -->
            <?php if ($show_create_form): ?>
            <div class="form-container create-form">
                <div class="form-body">
                    <!-- Role Badge -->
                    <div class="role-badge">
                        <div class="role-badge-icon">
                            <?php if ($selected_role === 'admin'): ?>
                                <i class="fas fa-vault"></i>
                            <?php elseif ($selected_role === 'ceo'): ?>
                                <i class="fas fa-landmark"></i>
                            <?php else: ?>
                                <i class="fas fa-hard-hat"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <small>CREATING NEW</small>
                            <div class="role-badge-text">
                                <?php echo !empty($selected_role) ? strtoupper($selected_role) : ''; ?> ACCOUNT
                            </div>
                        </div>
                    </div>
                    
                    <!-- Role Indicator -->
                    <div class="role-indicator">
                        <i class="fas fa-id-card"></i>
                        <span>Registering as <strong><?php echo !empty($selected_role) ? strtoupper($selected_role) : ''; ?></strong></span>
                    </div>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- SCROLLABLE CONTENT - mas maikli na -->
                    <div class="scrollable-content">
                        <form method="POST" onsubmit="return validateCreatePassword()" id="createAccountForm">
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">
                            <input type="hidden" name="create_account_direct" value="1">
                            
                            <div class="form-group">
                                <label><i class="fas fa-user" style="color: #75e6da;"></i> Full Name</label>
                                <input type="text" class="form-control" name="full_name" placeholder="Enter full name" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-envelope" style="color: #75e6da;"></i> Email Address</label>
                                <input type="email" class="form-control" name="email" placeholder="Enter email" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-user-circle" style="color: #75e6da;"></i> Username</label>
                                <input type="text" class="form-control" name="username" placeholder="Choose username" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-lock" style="color: #75e6da;"></i> Password</label>
                                <input type="password" class="form-control" name="password" id="create_password" placeholder="Min. 6 characters" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-lock" style="color: #75e6da;"></i> Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" id="create_confirm_password" placeholder="Confirm password" required>
                            </div>
                            
                            <!-- Security Questions Section - mas compact -->
                            <div class="security-section">
                                <div class="security-title">
                                    <i class="fas fa-shield-alt"></i> Security Verification
                                </div>
                                
                                <div class="form-group">
                                    <label style="font-size: 11px;">Middle Name?</label>
                                    <input type="text" class="form-control" name="answer1" placeholder="Your middle name" required style="background: white; font-size: 12px; padding: 8px 12px;">
                                </div>
                                
                                <div class="form-group">
                                    <label style="font-size: 11px;">First Name?</label>
                                    <input type="text" class="form-control" name="answer2" placeholder="Your first name" required style="background: white; font-size: 12px; padding: 8px 12px;">
                                </div>
                                
                                <div class="form-group">
                                    <label style="font-size: 11px;">Last Name?</label>
                                    <input type="text" class="form-control" name="answer3" placeholder="Your last name" required style="background: white; font-size: 12px; padding: 8px 12px;">
                                </div>
                            </div>
                            
                            <!-- SUBMIT BUTTON - nasa loob ng form -->
                            <div class="button-group">
                                <button type="submit" class="btn-submit">
                                    <?php if ($selected_role === 'admin'): ?>
                                        <i class="fas fa-vault"></i>
                                    <?php elseif ($selected_role === 'ceo'): ?>
                                        <i class="fas fa-landmark"></i>
                                    <?php else: ?>
                                        <i class="fas fa-hard-hat"></i>
                                    <?php endif; ?>
                                    Create <?php echo !empty($selected_role) ? strtoupper($selected_role) : ''; ?> Account
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- BACK BUTTON - may kulay gaya ng Create Account button -->
                    <a href="login.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Main Page
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- SECURITY QUESTIONS FORM (Forgot Password) - WITH HISTORY TEXT REMOVED -->
            <?php if ($show_security_form): ?>
            <?php 
            // Kunin ang role mula sa URL (ito ang pinili sa dropdown)
            $reset_role = isset($_GET['role']) ? $_GET['role'] : 'user';
            
            include_once("connection.php");
            
            $default_q1 = "What is your mother's maiden name?";
            $default_q2 = "What was the name of your first pet?";
            $default_q3 = "What city were you born in?";
            $question1 = $default_q1;
            $question2 = $default_q2;
            $question3 = $default_q3;
            
            // Kunin ang questions base sa ROLE
            if ($reset_role === 'admin') {
                // PARA SA ADMINISTRATOR - mula sa super_user table
                $result = $conn->query("SELECT security_question1, security_question2, security_question3 FROM super_user WHERE security_question1 IS NOT NULL ORDER BY id DESC LIMIT 1");
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
                    $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
                    $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
                }
            } 
            elseif ($reset_role === 'ceo') {
                // PARA SA CEO - mula sa user_accounts na role = 'ceo'
                $stmt = $conn->prepare("SELECT security_question1, security_question2, security_question3 FROM user_accounts WHERE role = 'ceo' AND security_question1 IS NOT NULL ORDER BY id DESC LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
                    $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
                    $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
                }
                $stmt->close();
            }
            elseif ($reset_role === 'user') {
                // PARA SA LEAD ENGINEER - mula sa user_accounts na role = 'user'
                $stmt = $conn->prepare("SELECT security_question1, security_question2, security_question3 FROM user_accounts WHERE role = 'user' AND security_question1 IS NOT NULL ORDER BY id DESC LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
                    $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
                    $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
                }
                $stmt->close();
            }
            
            // Store questions in session for verify_security.php
            $_SESSION['reset_role'] = $reset_role;
            $_SESSION['reset_questions'] = [
                'q1' => $question1,
                'q2' => $question2,
                'q3' => $question3
            ];
            
            $conn->close();
            ?>
            <div class="form-container">
                <div class="form-body">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="width: 70px; height: 70px; background: #75e6da; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; box-shadow: 0 10px 20px rgba(117, 230, 218, 0.3);">
                            <i class="fas fa-shield-halved" style="font-size: 30px; color: #2c3e50;"></i>
                        </div>
                        <h4 style="color: #1e293b; font-weight: 800; margin-bottom: 3px; font-size: 20px;">
                            Account Recovery
                        </h4>
                        <p style="color: #64748b; font-size: 12px;">
                            Verify your identity for 
                            <strong>
                                <?php 
                                    if ($reset_role === 'admin') {
                                        echo 'ADMINISTRATOR';
                                    } elseif ($reset_role === 'ceo') {
                                        echo 'CHIEF EXECUTIVE OFFICER';
                                    } else {
                                        echo 'LEAD ENGINEER';
                                    }
                                ?>
                            </strong>
                        </p>
                    </div>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger" style="animation: shake 0.5s ease;">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="verify_security.php" id="securityForm">
                        <input type="hidden" name="role" value="<?php echo $reset_role; ?>">
                        
                        <div class="form-group">
                            <label style="font-size: 12px;"><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question1); ?></label>
                            <input type="text" class="form-control" name="answer1" placeholder="Your answer" value="" autocomplete="off" required>
                        </div>
                        
                        <div class="form-group">
                            <label style="font-size: 12px;"><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question2); ?></label>
                            <input type="text" class="form-control" name="answer2" placeholder="Your answer" value="" autocomplete="off" required>
                        </div>
                        
                        <div class="form-group">
                            <label style="font-size: 12px;"><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question3); ?></label>
                            <input type="text" class="form-control" name="answer3" placeholder="Your answer" value="" autocomplete="off" required>
                        </div>
                        
                        <div style="display: flex; gap: 8px; margin-top: 20px;">
                            <button type="submit" class="btn-submit" style="flex: 2; margin-top: 0; padding: 10px;">
                                <i class="fas fa-check-circle"></i> Verify
                            </button>
                            <a href="login.php" class="btn-submit" style="flex: 1; background: #64748b; text-decoration: none; display: inline-block; text-align: center; margin-top: 0; padding: 10px;">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <style>
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
                20%, 40%, 60%, 80% { transform: translateX(5px); }
            }
            
            .form-control:focus {
                border-color: #75e6da !important;
                box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1) !important;
                background: white !important;
            }
            </style>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Validate password for create account
        function validateCreatePassword() {
            var password = document.getElementById("create_password").value;
            var confirm = document.getElementById("create_confirm_password").value;
            
            if (password != confirm) {
                alert("Passwords do not match!");
                return false;
            }
            if (password.length < 6) {
                alert("Password must be at least 6 characters long!");
                return false;
            }
            return true;
        }
        
        // Auto-hide success message after 5 seconds
        $(document).ready(function(){
            setTimeout(function() {
                $('.alert-success').fadeOut('slow');
            }, 5000);
        });
    </script>
</body>
</html>