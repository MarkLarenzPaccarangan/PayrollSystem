<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    
    // For demo purposes - in production, use password_verify()
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['role'] = 'administrator';
        header("Location: home.php");
        exit();
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JLC BEST CONSTRUCTION OPC - Inventory Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            background-image: url('assets/images/login2.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: cover;
            background-attachment: fixed;
        }

        /* Light theme only */
        :root {
            --login-bg: #ffffff;
            --login-text: #2c3e50;
            --login-text-secondary: #5a6a7a;
            --login-border: #d0e6e3;
            --login-input-bg: #f0f9f8;
            --login-accent: #75e6da;
            --login-accent-purple: #6c5ce7;
            --login-accent-pink: #e84393;
            --login-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            --login-card-shadow: 0 8px 32px rgba(117, 230, 218, 0.15);
        }

        .login-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 380px;
            margin-left: auto;
            margin-right: 100px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-card {
            background: var(--login-bg);
            border-radius: 16px;
            padding: 30px 25px;
            box-shadow: var(--login-card-shadow);
            border: 1px solid var(--login-border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(117, 230, 218, 0.1), transparent);
            animation: shine 8s infinite;
            pointer-events: none;
        }

        @keyframes shine {
            0% { left: -100%; }
            20% { left: 100%; }
            100% { left: 100%; }
        }

        .login-card:hover {
            transform: translateY(-5px);
            border-color: var(--login-accent);
        }

        .company-name {
            text-align: center;
            margin-bottom: 15px;
        }

        .company-name h1 {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--login-accent), var(--login-accent-purple));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.5px;
            line-height: 1.3;
        }

        .company-subtitle {
            text-align: center;
            margin-bottom: 20px;
        }

        .company-subtitle h2 {
            font-size: 14px;
            font-weight: 600;
            color: var(--login-text-secondary);
            margin-bottom: 5px;
        }

        .company-subtitle p {
            font-size: 11px;
            color: var(--login-accent);
            font-weight: 500;
            letter-spacing: 1px;
        }

        .company-subtitle p:last-child {
            color: var(--login-accent-pink);
            margin-top: 5px;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--login-border), transparent);
            margin: 20px 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--login-text);
            font-size: 12px;
        }

        .form-group label i {
            color: var(--login-accent);
            margin-right: 5px;
            font-size: 11px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--login-border);
            border-radius: 10px;
            font-size: 13px;
            transition: all 0.3s ease;
            background: var(--login-input-bg);
            color: var(--login-text);
        }

        .form-group input:focus {
            border-color: var(--login-accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
            transform: translateY(-2px);
        }

        .form-group input:hover {
            border-color: var(--login-accent);
        }

        .form-group input::placeholder {
            color: var(--login-text-secondary);
            font-size: 12px;
        }

        .login-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--login-accent), var(--login-accent-purple));
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 12px 0 15px 0;
            box-shadow: var(--login-card-shadow);
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
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

        .login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(117, 230, 218, 0.4);
        }

        .login-btn i {
            margin-right: 8px;
            transition: transform 0.3s ease;
        }

        .login-btn:hover i {
            transform: translateX(-3px);
        }

        .forgot-password {
            text-align: center;
        }

        .forgot-password a {
            color: var(--login-text-secondary);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .forgot-password a i {
            margin-right: 5px;
            color: var(--login-accent);
            font-size: 11px;
        }

        .forgot-password a:hover {
            color: var(--login-accent);
        }

        .error-message {
            background: rgba(214, 48, 49, 0.1);
            border: 1px solid #d63031;
            color: #d63031;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 12px;
            text-align: center;
            border-left: 3px solid #d63031;
            animation: shakeIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes shakeIn {
            0% {
                opacity: 0;
                transform: scale(0.5) rotate(-10deg);
            }
            50% {
                transform: scale(1.1) rotate(5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0);
            }
        }

        .error-message i {
            margin-right: 5px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                text-shadow: 0 0 0 rgba(214, 48, 49, 0.7);
            }
            50% {
                transform: scale(1.1);
                text-shadow: 0 0 10px rgba(214, 48, 49, 0.5);
            }
            100% {
                transform: scale(1);
                text-shadow: 0 0 0 rgba(214, 48, 49, 0);
            }
        }

        .footer-info {
            text-align: center;
            margin-top: 15px;
            color: var(--login-text-secondary);
            font-size: 11px;
        }

        .footer-info i {
            margin-right: 5px;
            color: var(--login-accent);
            transition: all 0.3s ease;
        }

        .footer-info i:hover {
            transform: scale(1.2) rotate(5deg);
            color: var(--login-accent-purple);
        }

        /* Loading animation for login button */
        .login-btn.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .login-btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Auto-fill styles */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px var(--login-input-bg) inset !important;
            -webkit-text-fill-color: var(--login-text) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-container {
                margin-left: auto;
                margin-right: auto;
                padding: 0 20px;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                max-width: 350px;
            }
            
            .login-card {
                padding: 25px 20px;
            }
            
            .company-name h1 {
                font-size: 18px;
            }
            
            .company-subtitle h2 {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="company-name">
                <h1>JLC BEST CONSTRUCTION OPC</h1>
            </div>

            <div class="company-subtitle">
                <h2 style="font-family: 'Times New Roman', Times, serif,">INVENTORY MANAGEMENT SYSTEM</h2>
                <h6> PARTNERING COMPANY FOR 
                    AUXILIARY DESIGN AND BUILD</h6>
            </div>

            <div class="divider"></div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label>
                        <i class="fas fa-user"></i> Username
                    </label>
                    <input type="text" name="username" placeholder="Enter your username" required autofocus>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    Login
                </button>
            </form>

            <div class="footer-info">
                <i class="fas fa-hard-hat"></i> JLC BEST CONSTRUCTION OPC © 2026
            </div>
        </div>
    </div>

    <script>
        // Add loading animation on form submit
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginBtn = document.getElementById('loginBtn');
            loginBtn.classList.add('loading');
            loginBtn.innerHTML = '<i class="fas fa-spinner"></i> Logging in...';
        });

        // Add floating label effect
        const inputs = document.querySelectorAll('.form-group input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.querySelector('label i').style.color = 'var(--login-accent)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.querySelector('label i').style.color = '';
            });
        });

        // Add keyboard shortcut (Ctrl+Enter) to submit form
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });

        // Add tooltip for keyboard shortcut
        const loginBtn = document.getElementById('loginBtn');
        loginBtn.title = 'Click to login or press Ctrl+Enter';
    </script>
</body>
</html>