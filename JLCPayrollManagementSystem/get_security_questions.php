<?php
session_start();
include_once("connection.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    // Default questions
    $default_q1 = "What is your mother's maiden name?";
    $default_q2 = "What was the name of your first pet?";
    $default_q3 = "What city were you born in?";
    
    $question1 = $default_q1;
    $question2 = $default_q2;
    $question3 = $default_q3;
    $user_found = false;
    $user_role = '';
    $user_table = '';
    
    // DEBUG: I-log ang username na hinahanap
    error_log("Looking for username: " . $username);
    
    // Check in user_accounts table (for ceo and engineer)
    $stmt = $conn->prepare("SELECT security_question1, security_question2, security_question3, role FROM user_accounts WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // DEBUG: I-log ang nakuha mula sa database
        error_log("Found in user_accounts: " . print_r($row, true));
        
        // Kung may laman ang questions, gamitin ang mga ito
        $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
        $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
        $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
        $user_found = true;
        $user_role = $row['role'];
        $user_table = 'user_accounts';
    }
    $stmt->close();
    
    // If not found in user_accounts, check super_user (for admin)
    if (!$user_found) {
        $stmt = $conn->prepare("SELECT security_question1, security_question2, security_question3 FROM super_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // DEBUG: I-log ang nakuha mula sa super_user
            error_log("Found in super_user: " . print_r($row, true));
            
            $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
            $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
            $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
            $user_found = true;
            $user_role = 'admin';
            $user_table = 'super_user';
        }
        $stmt->close();
    }
    
    if (!$user_found) {
        // User not found
        error_log("User not found: " . $username);
        header("Location: login.php?forgot_password=true&error=user_not_found");
        exit();
    }
    
    // DEBUG: I-log ang mga questions na ipapakita
    error_log("Questions to display for $username ($user_role): Q1=$question1, Q2=$question2, Q3=$question3");
    
    // Store username and questions in session for next step
    $_SESSION['forgot_username'] = $username;
    $_SESSION['forgot_role'] = $user_role;
    $_SESSION['forgot_table'] = $user_table;
    $_SESSION['forgot_questions'] = [
        'q1' => $question1,
        'q2' => $question2,
        'q3' => $question3
    ];
    
    $conn->close();
} else {
    header("Location: login.php");
    exit();
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
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-info i {
            color: #75e6da;
            font-size: 18px;
        }
        
        .user-info span {
            color: #1e293b;
            font-weight: 600;
        }
        
        .user-info .role-badge {
            margin-left: auto;
            background: <?php echo ($user_role == 'admin') ? '#667eea' : (($user_role == 'ceo') ? '#f093fb' : '#4facfe'); ?>;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Style para sa questions */
        .question-label {
            font-size: 14px;
            font-weight: 600;
            color: #2E7D32;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-body">
            <div class="text-center">
                <i class="fas fa-shield-halved"></i>
                <h2>Security Questions</h2>
                <p>Please answer your security questions</p>
            </div>
            
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span>Account: <strong><?php echo htmlspecialchars($username); ?></strong></span>
                <span class="role-badge"><?php echo strtoupper($user_role); ?></span>
            </div>
            
            <form method="POST" action="verify_security.php">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                
                <div class="form-group">
                    <div class="question-label">Question #1:</div>
                    <label><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question1); ?></label>
                    <input type="text" class="form-control" name="answer1" placeholder="Your answer" required>
                </div>
                
                <div class="form-group">
                    <div class="question-label">Question #2:</div>
                    <label><i class="fas fa-question-circle" style="color: #75e6da;"></i> <?php echo htmlspecialchars($question2); ?></label>
                    <input type="text" class="form-control" name="answer2" placeholder="Your answer" required>
                </div>
                
                <div class="form-group">
                    <div class="question-label">Question #3:</div>
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