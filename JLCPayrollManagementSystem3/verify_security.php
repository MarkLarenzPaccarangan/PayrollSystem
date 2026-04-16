<?php
session_start();
include_once("connection.php");

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answer1 = trim($_POST['answer1']);
    $answer2 = trim($_POST['answer2']);
    $answer3 = trim($_POST['answer3']);
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    
    error_log("VERIFY SECURITY - Verifying answers for role: $role");
    
    // Check if user_accounts table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'user_accounts'");
    if ($table_check->num_rows == 0) {
        header("Location: login.php?forgot_password=true&error=table_not_found");
        exit();
    }
    
    $user_found = false;
    $user_data = [];
    $user_table = '';
    
    // ===== UNA: Kung ADMIN ang role, mag-check sa super_user =====
    if ($role === 'admin') {
        error_log("Checking super_user for ADMINISTRATOR");
        
        $admin_table_check = $conn->query("SHOW TABLES LIKE 'super_user'");
        if ($admin_table_check->num_rows > 0) {
            $column_check = $conn->query("SHOW COLUMNS FROM super_user LIKE 'security_answer1'");
            if ($column_check->num_rows > 0) {
                $stmt = $conn->prepare("SELECT id, username, name as full_name,
                                        security_question1, security_question2, security_question3 
                                        FROM super_user 
                                        WHERE LOWER(security_answer1) = LOWER(?) 
                                        AND LOWER(security_answer2) = LOWER(?) 
                                        AND LOWER(security_answer3) = LOWER(?) 
                                        LIMIT 1");
                $stmt->bind_param("sss", $answer1, $answer2, $answer3);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user_data = $result->fetch_assoc();
                    $user_found = true;
                    $user_table = 'super_user';
                    error_log("ADMINISTRATOR found in super_user");
                } else {
                    error_log("No ADMINISTRATOR found with those answers");
                }
                $stmt->close();
            }
        }
    } 
    
    // ===== PANGALAWA: Kung CEO ang role, mag-check sa user_accounts na role = 'ceo' =====
    if (!$user_found && $role === 'ceo') {
        error_log("Checking user_accounts for CEO");
        
        $stmt = $conn->prepare("SELECT id, username, full_name, role, 
                                security_question1, security_question2, security_question3 
                                FROM user_accounts 
                                WHERE role = 'ceo'
                                AND LOWER(security_answer1) = LOWER(?) 
                                AND LOWER(security_answer2) = LOWER(?) 
                                AND LOWER(security_answer3) = LOWER(?) 
                                AND is_active = 1 LIMIT 1");
        $stmt->bind_param("sss", $answer1, $answer2, $answer3);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user_data = $result->fetch_assoc();
            $user_found = true;
            $user_table = 'user_accounts';
            error_log("CEO found in user_accounts");
        } else {
            error_log("No CEO found with those answers");
        }
        $stmt->close();
    }
    
    // ===== PANGATLO: Kung ENGINEER ang role, mag-check sa user_accounts na role = 'user' =====
    if (!$user_found && $role === 'user') {
        error_log("Checking user_accounts for LEAD ENGINEER");
        
        $stmt = $conn->prepare("SELECT id, username, full_name, role, 
                                security_question1, security_question2, security_question3 
                                FROM user_accounts 
                                WHERE role = 'user'
                                AND LOWER(security_answer1) = LOWER(?) 
                                AND LOWER(security_answer2) = LOWER(?) 
                                AND LOWER(security_answer3) = LOWER(?) 
                                AND is_active = 1 LIMIT 1");
        $stmt->bind_param("sss", $answer1, $answer2, $answer3);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user_data = $result->fetch_assoc();
            $user_found = true;
            $user_table = 'user_accounts';
            error_log("LEAD ENGINEER found in user_accounts");
        } else {
            error_log("No LEAD ENGINEER found with those answers");
        }
        $stmt->close();
    }
    
    // ===== Kung may nakitang user, i-redirect sa reset_password.php =====
    if ($user_found) {
        error_log("User verified successfully: " . $user_data['username']);
        
        // I-set ang session para sa password reset
        $_SESSION['reset_user_id'] = $user_data['id'];
        $_SESSION['reset_username'] = $user_data['username'];
        $_SESSION['reset_full_name'] = $user_data['full_name'];
        $_SESSION['reset_table'] = $user_table;
        
        // Set role based on table
        if ($user_table === 'super_user') {
            $_SESSION['reset_role'] = 'admin';
        } else {
            $_SESSION['reset_role'] = $user_data['role'] ?? 'user';
        }
        
        // Store security questions in session for display (optional)
        if (isset($user_data['security_question1'])) {
            $_SESSION['reset_questions'] = [
                'q1' => $user_data['security_question1'],
                'q2' => $user_data['security_question2'],
                'q3' => $user_data['security_question3']
            ];
        }
        
        // Redirect to password reset page
        header("Location: reset_password.php");
        exit();
    }
    
    // ===== Kung walang match sa kahit anong table =====
    error_log("No matching user found for role: $role");
    header("Location: login.php?forgot_password=true&error=1");
    exit();
    
} else {
    header("Location: login.php");
    exit();
}
?>