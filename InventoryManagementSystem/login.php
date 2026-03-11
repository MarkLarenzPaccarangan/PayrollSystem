<?php
require_once 'config.php';
require_once 'user.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

$error = '';
$user = new User($conn);

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    
    // Verify login from database
    $loggedInUser = $user->verifyLogin($username, $password);
    
    if ($loggedInUser) {
        $_SESSION['user_id'] = $loggedInUser['id'];
        $_SESSION['username'] = $loggedInUser['username'];
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
    <title>JLC BEST CONSTRUCTION OPC</title>
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
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card {
            background: var(--login-bg);
            border-radius: 16px;
            padding: 30px 25px;
            box-shadow: var(--login-card-shadow);
            border: 1px solid var(--login-border);
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
            position: relative;
            overflow: hidden;
            outline: 2px solid transparent;
            outline-offset: 2px;
        }

        .login-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(117, 230, 218, 0.2);
            border-color: var(--login-accent);
            outline-color: var(--login-accent);
        }

        /* Welcome Admin Text - Simple Animation */
        .welcome-admin {
            text-align: center;
            margin-bottom: 20px;
        }

        .welcome-admin h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--login-accent), var(--login-accent-purple));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .welcome-admin h1:hover {
            transform: scale(1.02);
            background: linear-gradient(135deg, var(--login-accent-purple), var(--login-accent-pink));
            -webkit-background-clip: text;
            background-clip: text;
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
            position: relative;
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
            transition: transform 0.2s ease;
        }

        .form-group:hover label i {
            transform: translateX(2px);
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
            outline: 2px solid transparent;
            outline-offset: 2px;
        }

        .form-group input:focus {
            border-color: var(--login-accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
            outline-color: var(--login-accent);
        }

        .form-group input:hover {
            border-color: var(--login-accent);
            outline-color: var(--login-accent);
        }

        .form-group input::placeholder {
            color: var(--login-text-secondary);
            font-size: 12px;
        }

        /* Password field with eye icon */
        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 40px !important;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--login-text-secondary);
            font-size: 16px;
            transition: all 0.2s ease;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 3;
        }

        .password-wrapper.show-toggle .password-toggle {
            opacity: 1;
            visibility: visible;
        }

        .password-toggle:hover {
            color: var(--login-accent);
        }

        .password-toggle i {
            font-size: 16px;
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
            outline: 2px solid transparent;
            outline-offset: 2px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(117, 230, 218, 0.3);
            outline-color: var(--login-accent);
        }

        .login-btn:focus {
            outline-color: var(--login-accent);
        }

        .login-btn i {
            margin-right: 8px;
            transition: transform 0.2s ease;
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
            transition: color 0.2s ease;
            display: inline-block;
            outline: 2px solid transparent;
            outline-offset: 2px;
            border-radius: 4px;
        }

        .forgot-password a i {
            margin-right: 5px;
            color: var(--login-accent);
            font-size: 11px;
            transition: transform 0.2s ease;
        }

        .forgot-password a:hover {
            color: var(--login-accent);
            outline-color: var(--login-accent);
        }

        .forgot-password a:hover i {
            transform: translateX(-2px);
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
            animation: fadeIn 0.3s ease;
        }

        .error-message i {
            margin-right: 5px;
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
            transition: transform 0.2s ease;
        }

        .footer-info i:hover {
            transform: scale(1.1);
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
            
            .welcome-admin h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Welcome Admin Text -->
            <div class="welcome-admin">
                <h1>WELCOME ADMIN!</h1>
            </div>

            <div class="company-name">
                <h1 style="font-family: 'Times New Roman', Times, serif,">JLC BEST CONSTRUCTION OPC</h1>
            </div>

            <div class="divider"></div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" autocomplete="off">
                <div class="form-group">
                    <label>
                        <i class="fas fa-user"></i> Username
                    </label>
                    <input type="text" 
                           name="username" 
                           placeholder="Enter your username" 
                           required 
                           autofocus
                           autocomplete="off"
                           readonly 
                           onfocus="this.removeAttribute('readonly')">
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="password-wrapper" id="passwordWrapper">
                        <input type="password" 
                               name="password" 
                               id="passwordInput"
                               placeholder="Enter your password" 
                               required
                               autocomplete="new-password"
                               readonly
                               onfocus="this.removeAttribute('readonly')"
                               oninput="toggleEyeIcon(this)">
                        <span class="password-toggle" id="passwordToggle" onclick="togglePasswordVisibility()">
                            <i class="fas fa-eye-slash" id="toggleIcon"></i>
                        </span>
                    </div>
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
        // Function to show/hide eye icon based on input
        function toggleEyeIcon(input) {
            const wrapper = document.getElementById('passwordWrapper');
            const toggle = document.getElementById('passwordToggle');
            
            if (input.value.length > 0) {
                wrapper.classList.add('show-toggle');
            } else {
                wrapper.classList.remove('show-toggle');
                // Reset to password type when empty
                if (document.getElementById('passwordInput').type === 'text') {
                    document.getElementById('passwordInput').type = 'password';
                    document.getElementById('toggleIcon').className = 'fas fa-eye-slash';
                }
            }
        }

        // Function to toggle password visibility
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('passwordInput');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye-slash';
            }
            
            // Keep focus on input field
            passwordInput.focus();
        }

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

        // Prevent browser from saving form data
        window.addEventListener('load', function() {
            document.querySelector('input[name="username"]').value = '';
            document.querySelector('input[name="password"]').value = '';
        });

        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Check initial state of password field (in case of browser autofill)
        window.addEventListener('load', function() {
            const passwordInput = document.getElementById('passwordInput');
            if (passwordInput.value.length > 0) {
                document.getElementById('passwordWrapper').classList.add('show-toggle');
            }
        });

        
    </script>
</body>
</html>