<?php
session_start();

// Check if user data exists in session
if (!isset($_SESSION['reset_user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['reset_user'];
$username = $user['username'];
$fullname = $user['fullname'];
$role = $user['role'];
$question1 = $user['questions']['q1'];
$question2 = $user['questions']['q2'];
$question3 = $user['questions']['q3'];

// Determine role display name
if ($role === 'admin') {
    $role_display = 'ADMINISTRATOR';
} elseif ($role === 'ceo') {
    $role_display = 'CHIEF EXECUTIVE OFFICER';
} else {
    $role_display = 'LEAD ENGINEER';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Questions</title>
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
        
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            color: white;
            margin-left: 10px;
        }
        
        .role-badge.admin {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .role-badge.ceo {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }
        
        .role-badge.user {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-body">
            <div class="text-center">
                <i class="fas fa-shield-halved"></i>
                <h2>Security Questions</h2>
                <p>Verify your identity</p>
            </div>
            
            <div class="user-info">
                <div class="user-info-item">
                    <i class="fas fa-user-circle"></i>
                    <span>Account: <strong><?php echo htmlspecialchars($username); ?></strong></span>
                    <span class="role-badge <?php echo $role; ?>"><?php echo $role_display; ?></span>
                </div>
                <div class="user-info-item">
                    <i class="fas fa-id-card"></i>
                    <span>Name: <strong><?php echo htmlspecialchars($fullname); ?></strong></span>
                </div>
            </div>
            
            <form method="POST" action="verify_security.php">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question1); ?></label>
                    <input type="text" class="form-control" name="answer1" placeholder="Your answer" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question2); ?></label>
                    <input type="text" class="form-control" name="answer2" placeholder="Your answer" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question3); ?></label>
                    <input type="text" class="form-control" name="answer3" placeholder="Your answer" required>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-check-circle"></i> Verify Answers
                </button>
                
                <a href="login.php" class="btn-cancel">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </form>
        </div>
    </div>
</body>
</html>