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
    $user_fullname = '';
    
    error_log("LOOKING FOR USER: " . $username);
    
    // ===== UNA: Mag-check sa user_accounts table (para sa CEO at ENGINEER) =====
    $stmt = $conn->prepare("SELECT username, full_name, role, 
                            security_question1, security_question2, security_question3 
                            FROM user_accounts WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_found = true;
        $user_role = $row['role'];
        $user_fullname = $row['full_name'];
        $user_table = 'user_accounts';
        
        // Kunin ang questions (kung may laman)
        $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
        $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
        $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
        
        error_log("FOUND IN user_accounts - Username: $username, Role: $user_role");
        error_log("Questions: Q1=$question1, Q2=$question2, Q3=$question3");
    }
    $stmt->close();
    
    // ===== PANGALAWA: Kung hindi nahanap sa user_accounts, mag-check sa super_user (ADMIN) =====
    if (!$user_found) {
        $stmt = $conn->prepare("SELECT username, name as full_name, 
                                security_question1, security_question2, security_question3 
                                FROM super_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $user_found = true;
            $user_role = 'admin';
            $user_fullname = $row['full_name'];
            $user_table = 'super_user';
            
            // Kunin ang questions (kung may laman)
            $question1 = !empty($row['security_question1']) ? $row['security_question1'] : $default_q1;
            $question2 = !empty($row['security_question2']) ? $row['security_question2'] : $default_q2;
            $question3 = !empty($row['security_question3']) ? $row['security_question3'] : $default_q3;
            
            error_log("FOUND IN super_user - Username: $username, Role: ADMIN");
            error_log("Questions: Q1=$question1, Q2=$question2, Q3=$question3");
        }
        $stmt->close();
    }
    
    if (!$user_found) {
        // User not found
        error_log("USER NOT FOUND: " . $username);
        header("Location: login.php?forgot_password=true&error=user_not_found");
        exit();
    }
    
    // Store user data in session for the next step
    $_SESSION['reset_user'] = [
        'username' => $username,
        'fullname' => $user_fullname,
        'role' => $user_role,
        'table' => $user_table,
        'questions' => [
            'q1' => $question1,
            'q2' => $question2,
            'q3' => $question3
        ]
    ];
    
    $conn->close();
    
    // Redirect to security questions page
    header("Location: security_questions.php");
    exit();
    
} else {
    header("Location: login.php");
    exit();
}
?>