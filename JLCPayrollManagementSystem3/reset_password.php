<?php
session_start();

// Check if user is authorized to reset password
if (!isset($_SESSION['reset_username']) || !isset($_SESSION['reset_table'])) {
    header("Location: login.php");
    exit;
}

include_once("connection.php");

$error_message = '';
$success_message = '';
$username = $_SESSION['reset_username'];
$full_name = $_SESSION['reset_full_name'] ?? $username;
$table = $_SESSION['reset_table'];
$role = $_SESSION['reset_role'] ?? 'user';

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match!";
    } elseif (strlen($new_password) < 6) {
        $error_message = "Password must be at least 6 characters long!";
    } else {
        // Update password based on table
        if ($table === 'super_user') {
            $stmt = $conn->prepare("UPDATE super_user SET password = ? WHERE username = ?");
        } else {
            $stmt = $conn->prepare("UPDATE user_accounts SET password_hash = ? WHERE username = ?");
        }
        
        $stmt->bind_param("ss", $new_password, $username);
        
        if ($stmt->execute()) {
            $success_message = "Password reset successfully! You can now login with your new password.";
            
            // Clear reset session
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_username']);
            unset($_SESSION['reset_full_name']);
            unset($_SESSION['reset_table']);
            unset($_SESSION['reset_role']);
            unset($_SESSION['reset_questions']);
            
            // Redirect to login after 3 seconds
            header("refresh:3;url=login.php");
        } else {
            $error_message = "Failed to reset password. Please try again.";
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        
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
        
        .form-body {
            padding: 30px 25px;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            display: block;
            font-size: 13px;
        }
        
        .form-control {
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 13px;
            transition: all 0.3s ease;
            width: 100%;
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
            margin-top: 15px;
            box-shadow: 0 8px 16px rgba(117, 230, 218, 0.2);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(117, 230, 218, 0.3);
            background: #5bc5b9;
        }
        
        .btn-cancel {
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
            margin-top: 10px;
            text-decoration: none;
            box-shadow: 0 8px 16px rgba(46, 125, 50, 0.2);
        }
        
        .btn-cancel:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(46, 125, 50, 0.3);
            background: linear-gradient(135deg, #1b5e20, #1b5e20);
            text-decoration: none;
            color: white;
        }
        
        .alert {
            border-radius: 12px;
            padding: 12px 15px;
            margin-bottom: 20px;
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
        
        .text-center {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .text-center i {
            font-size: 50px;
            color: #75e6da;
            margin-bottom: 10px;
        }
        
        .text-center h2 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
        }
        
        .text-center p {
            color: #64748b;
            font-size: 13px;
        }
        
        .user-info {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: 2px solid #e2e8f0;
        }
        
        .user-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .user-info-item i {
            color: #75e6da;
            width: 20px;
            font-size: 16px;
        }
        
        .user-info-item span {
            color: #1e293b;
            font-weight: 500;
        }
        
        .user-info-item strong {
            color: #2E7D32;
            margin-left: 5px;
        }
        
        .security-questions {
            background: rgba(117, 230, 218, 0.05);
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 12px;
            color: #64748b;
            border-left: 3px solid #75e6da;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-body">
            <div class="text-center">
                <i class="fas fa-key"></i>
                <h2>Reset Password</h2>
                <p>Enter your new password</p>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="user-info">
                <div class="user-info-item">
                    <i class="fas fa-user-circle"></i>
                    <span>Account: <strong><?php echo htmlspecialchars($username); ?></strong></span>
                </div>
                <div class="user-info-item">
                    <i class="fas fa-tag"></i>
                    <span>Role: <strong><?php echo ucfirst($role); ?></strong></span>
                </div>
                <?php if (isset($_SESSION['reset_questions'])): ?>
                <div class="security-questions">
                    <i class="fas fa-shield-alt"></i> Your security questions have been verified
                </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="" onsubmit="return validatePassword()">
                <div class="form-group">
                    <label for="new_password">
                        <i class="fas fa-lock"></i> New Password
                    </label>
                    <input type="password" class="form-control" id="new_password" name="new_password" 
                           placeholder="Enter new password" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i> Confirm Password
                    </label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                           placeholder="Confirm new password" required>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Reset Password
                </button>
                
                <a href="login.php" class="btn-cancel">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </form>
        </div>
    </div>
    
    <script>
        function validatePassword() {
            var password = document.getElementById("new_password").value;
            var confirm = document.getElementById("confirm_password").value;
            
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
    </script>
</body>
</html>