<?php
session_start();

if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

// Include access control
include_once("check_access.php");

// Check if user has access to this page
$current_page = basename($_SERVER['PHP_SELF']);
if (!checkPageAccess($current_page)) {
    header("Location: home.php?error=access_denied");
    exit;
}

include_once("connection.php");
$username = $_SESSION['Admin_User'];
$error_message = '';
$success_message = '';

// ===== GET USER ROLE FROM SESSION =====
$role = $_SESSION['role'] ?? 'admin';
$user_type = $_SESSION['user_type'] ?? 'super_user';

// ===== FETCH USER DATA BASED ON ROLE =====
$currentUsername = '';
$currentPassword = '';
$security1 = '';
$security2 = '';
$security3 = '';
$security_q1 = '';
$security_q2 = '';
$security_q3 = '';
$full_name = $_SESSION['full_name'] ?? $username;

// Default security questions
$default_q1 = "What is your mother's maiden name?";
$default_q2 = "What was the name of your first pet?";
$default_q3 = "What city were you born in?";

// Handle form submission for updates - ILAGAY ITO BAGO I-FETCH ANG DATA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newUsername = trim($_POST['username']);
    $newPassword = trim($_POST['password']);
    
    // Security answers - either current or new
    $newAnswer1 = !empty(trim($_POST['security_answer1'])) ? trim($_POST['security_answer1']) : $security1;
    $newAnswer2 = !empty(trim($_POST['security_answer2'])) ? trim($_POST['security_answer2']) : $security2;
    $newAnswer3 = !empty(trim($_POST['security_answer3'])) ? trim($_POST['security_answer3']) : $security3;
    
    // Security questions - either current or new
    $newQuestion1 = !empty(trim($_POST['security_question1'])) ? trim($_POST['security_question1']) : $security_q1;
    $newQuestion2 = !empty(trim($_POST['security_question2'])) ? trim($_POST['security_question2']) : $security_q2;
    $newQuestion3 = !empty(trim($_POST['security_question3'])) ? trim($_POST['security_question3']) : $security_q3;

    // Update based on user type
    if ($user_type === 'super_user' || $role === 'admin') {
        // Check if security question columns exist in super_user
        $column_check = $conn->query("SHOW COLUMNS FROM super_user LIKE 'security_question1'");
        
        if ($column_check->num_rows == 0) {
            // Add security question columns to super_user table
            $conn->query("ALTER TABLE super_user ADD COLUMN security_question1 VARCHAR(255) DEFAULT 'What is your mother\'s maiden name?'");
            $conn->query("ALTER TABLE super_user ADD COLUMN security_question2 VARCHAR(255) DEFAULT 'What was the name of your first pet?'");
            $conn->query("ALTER TABLE super_user ADD COLUMN security_question3 VARCHAR(255) DEFAULT 'What city were you born in?'");
        }
        
        // Update super_user table with questions and answers
        $updateStmt = $conn->prepare("UPDATE super_user SET username = ?, password = ?, security_answer1 = ?, security_answer2 = ?, security_answer3 = ?, security_question1 = ?, security_question2 = ?, security_question3 = ? WHERE username = ?");
        $updateStmt->bind_param("sssssssss", $newUsername, $newPassword, $newAnswer1, $newAnswer2, $newAnswer3, $newQuestion1, $newQuestion2, $newQuestion3, $username);
    } else {
        // Check if security question columns exist in user_accounts
        $column_check = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'security_question1'");
        
        if ($column_check->num_rows == 0) {
            // Add security question columns to user_accounts table
            $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question1 VARCHAR(255) DEFAULT 'What is your mother\'s maiden name?'");
            $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question2 VARCHAR(255) DEFAULT 'What was the name of your first pet?'");
            $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question3 VARCHAR(255) DEFAULT 'What city were you born in?'");
        }
        
        // Update user_accounts table with questions and answers
        $updateStmt = $conn->prepare("UPDATE user_accounts SET username = ?, password_hash = ?, security_answer1 = ?, security_answer2 = ?, security_answer3 = ?, security_question1 = ?, security_question2 = ?, security_question3 = ? WHERE username = ?");
        $updateStmt->bind_param("sssssssss", $newUsername, $newPassword, $newAnswer1, $newAnswer2, $newAnswer3, $newQuestion1, $newQuestion2, $newQuestion3, $username);
    }

    if ($updateStmt->execute()) {
        $_SESSION['Admin_User'] = $newUsername; // Update session username
        $_SESSION['full_name'] = $newUsername; // Update full name if needed
        $success_message = "Profile updated successfully. Security questions and answers have been updated.";
        
        // I-refresh ang page para ipakita ang updated values
        header("Location: user.php?success=1");
        exit();
    } else {
        $error_message = "Failed to update profile. Please try again.";
    }

    $updateStmt->close();
}

// ===== FETCH USER DATA BASED ON ROLE (AFTER POTENTIAL UPDATE) =====
if ($user_type === 'super_user' || $role === 'admin') {
    // Fetch from super_user table (admin)
    // Check if security question columns exist
    $column_check = $conn->query("SHOW COLUMNS FROM super_user LIKE 'security_question1'");
    
    if ($column_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT username, password, security_answer1, security_answer2, security_answer3, security_question1, security_question2, security_question3 FROM super_user WHERE username = ?");
        $stmt->bind_param("s", $_SESSION['Admin_User']);
        $stmt->execute();
        $stmt->bind_result($currentUsername, $currentPassword, $security1, $security2, $security3, $security_q1, $security_q2, $security_q3);
        $stmt->fetch();
        $stmt->close();
    } else {
        // Add security question columns to super_user table
        $conn->query("ALTER TABLE super_user ADD COLUMN security_question1 VARCHAR(255) DEFAULT 'What is your mother\'s maiden name?'");
        $conn->query("ALTER TABLE super_user ADD COLUMN security_question2 VARCHAR(255) DEFAULT 'What was the name of your first pet?'");
        $conn->query("ALTER TABLE super_user ADD COLUMN security_question3 VARCHAR(255) DEFAULT 'What city were you born in?'");
        
        // Fetch regular data
        $stmt = $conn->prepare("SELECT username, password, security_answer1, security_answer2, security_answer3 FROM super_user WHERE username = ?");
        $stmt->bind_param("s", $_SESSION['Admin_User']);
        $stmt->execute();
        $stmt->bind_result($currentUsername, $currentPassword, $security1, $security2, $security3);
        $stmt->fetch();
        $stmt->close();
    }
    
    // If no security answers/questions, set defaults
    $security1 = $security1 ?? '';
    $security2 = $security2 ?? '';
    $security3 = $security3 ?? '';
    $security_q1 = $security_q1 ?? $default_q1;
    $security_q2 = $security_q2 ?? $default_q2;
    $security_q3 = $security_q3 ?? $default_q3;
    
} else {
    // Fetch from user_accounts table (ceo or user)
    // Check if security question columns exist
    $column_check = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'security_question1'");
    
    if ($column_check->num_rows > 0) {
        // May security question columns
        $stmt = $conn->prepare("SELECT username, password_hash as password, security_answer1, security_answer2, security_answer3, security_question1, security_question2, security_question3, full_name FROM user_accounts WHERE username = ?");
        $stmt->bind_param("s", $_SESSION['Admin_User']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $currentUsername = $row['username'];
            $currentPassword = $row['password'];
            $security1 = $row['security_answer1'] ?? '';
            $security2 = $row['security_answer2'] ?? '';
            $security3 = $row['security_answer3'] ?? '';
            $security_q1 = $row['security_question1'] ?? $default_q1;
            $security_q2 = $row['security_question2'] ?? $default_q2;
            $security_q3 = $row['security_question3'] ?? $default_q3;
            $full_name = $row['full_name'] ?? $full_name;
        }
        $stmt->close();
    } else {
        // Add security question columns to user_accounts table
        $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question1 VARCHAR(255) DEFAULT 'What is your mother\'s maiden name?'");
        $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question2 VARCHAR(255) DEFAULT 'What was the name of your first pet?'");
        $conn->query("ALTER TABLE user_accounts ADD COLUMN security_question3 VARCHAR(255) DEFAULT 'What city were you born in?'");
        
        // Fetch regular data
        $stmt = $conn->prepare("SELECT username, password_hash as password, security_answer1, security_answer2, security_answer3, full_name FROM user_accounts WHERE username = ?");
        $stmt->bind_param("s", $_SESSION['Admin_User']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $currentUsername = $row['username'];
            $currentPassword = $row['password'];
            $security1 = $row['security_answer1'] ?? '';
            $security2 = $row['security_answer2'] ?? '';
            $security3 = $row['security_answer3'] ?? '';
            $full_name = $row['full_name'] ?? $full_name;
        }
        $stmt->close();
        
        // Set default questions
        $security_q1 = $default_q1;
        $security_q2 = $default_q2;
        $security_q3 = $default_q3;
    }
}

// Check for success message from URL
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = "Profile updated successfully. Security questions and answers have been updated.";
}

$conn->close();

// Get first letter for avatar
$firstLetter = !empty($currentUsername) ? strtoupper(substr($currentUsername, 0, 1)) : 'A';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="stylesheet" href="./assets/css/user2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ENHANCED PROFILE LAYOUT - FULL SCREEN */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            height: 100vh;
            overflow: hidden;
        }
        
        .content {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }
        
        /* FIXED: Content wrapper dapat mag-scroll */
        .content-wrapper {
            flex: 1;
            padding: 30px 40px;
            overflow-y: auto !important; /* Force scroll */
            overflow-x: hidden;
            background-color: transparent;
            height: calc(100vh - 120px); /* Adjust based on header height */
        }
        
        /* Custom Scrollbar */
        .content-wrapper::-webkit-scrollbar {
            width: 10px;
        }
        
        .content-wrapper::-webkit-scrollbar-track {
            background: #e9ecef;
            border-radius: 10px;
        }
        
        .content-wrapper::-webkit-scrollbar-thumb {
            background: #2E7D32;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }
        
        .content-wrapper::-webkit-scrollbar-thumb:hover {
            background: #1B5E20;
        }
        
        /* Welcome Box - Same as home.php */
        .welcome-box2 {
            background: linear-gradient(135deg, #75e6da 0%, #75e6da 100%);
            color: #ffffff;
            padding: 20px 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            font-size: 1.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .welcome-box2 i {
            font-size: 2rem;
            filter: drop-shadow(2px 2px 4px rgba(0, 0, 0, 0.2));
        }
        
        /* Main Profile Container - Full Width */
        .profile-container {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding-bottom: 50px; /* Add space at bottom */
        }
        
        /* Profile Avatar Section - Enhanced */
        .profile-avatar {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }
        
        .profile-avatar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 10px;
            background: linear-gradient(90deg, #75e6da, #5fd6c9, #75e6da);
        }
        
        .avatar-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 48px;
            font-weight: 700;
            color: white;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            border: 4px solid rgba(255, 255, 255, 0.5);
        }
        
        .avatar-circle.admin {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .avatar-circle.ceo {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }
        
        .avatar-circle.user {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }
        
        .profile-avatar h3 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .profile-avatar p {
            font-size: 16px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .profile-avatar p i {
            color: #2E7D32;
        }
        
        .role-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-badge.admin {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }
        
        .role-badge.ceo {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            box-shadow: 0 2px 10px rgba(240, 147, 251, 0.3);
        }
        
        .role-badge.user {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            box-shadow: 0 2px 10px rgba(79, 172, 254, 0.3);
        }
        
        .account-type {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            background: #e9ecef;
            color: #495057;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .account-type i {
            color: #2E7D32 !important;
        }
        
        /* Messages */
        .message {
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        /* Account Information Container - Header Color */
        .info-container {
            background: linear-gradient(135deg, #75e6da 0%, #5fd6c9 100%);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .info-container h3 {
            color: white;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.2);
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            padding-bottom: 15px;
        }
        
        .info-container h3 i {
            font-size: 24px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .info-item {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.5);
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            background: white;
        }
        
        .info-item i {
            font-size: 24px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        
        .info-item i.fa-user { color: #2E7D32; background: rgba(46, 125, 50, 0.1); padding: 8px; }
        .info-item i.fa-user-tag { color: #667eea; background: rgba(102, 126, 234, 0.1); padding: 8px; }
        .info-item i.fa-calendar-alt { color: #f093fb; background: rgba(240, 147, 251, 0.1); padding: 8px; }
        .info-item i.fa-key { color: #ff6b6b; background: rgba(255, 107, 107, 0.1); padding: 8px; }
        .info-item i.fa-shield-alt { color: #4facfe; background: rgba(79, 172, 254, 0.1); padding: 8px; }
        
        .info-item .info-content {
            flex: 1;
        }
        
        .info-item .info-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .info-item .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .info-item .info-value i {
            font-size: 16px;
            width: auto;
            height: auto;
            background: none;
            padding: 0;
            margin-left: 5px;
        }
        
        /* Form Sections */
        .form-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }
        
        .form-section h3 {
            color: #1e293b;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 2px solid #75e6da;
            padding-bottom: 15px;
        }
        
        .form-section h3 i {
            color: #2E7D32;
            font-size: 24px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group label i {
            color: #2E7D32;
            font-size: 16px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .form-group input:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 4px rgba(117, 230, 218, 0.1);
            outline: none;
            background: white;
        }
        
        .password-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 45px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #2E7D32;
        }
        
        /* Security Questions Container - SPLIT DESIGN */
        .security-container {
            background: #f8fafc;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .security-container:hover {
            border-color: #75e6da;
            box-shadow: 0 4px 12px rgba(117, 230, 218, 0.15);
        }
        
        .security-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #2E7D32;
            font-weight: 700;
            font-size: 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid #75e6da;
        }
        
        .security-header i {
            font-size: 22px;
        }
        
        /* Split Design: Current (Left) and New (Right) */
        .security-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .current-section, .new-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid #e2e8f0;
        }
        
        .current-section {
            border-left: 4px solid #2E7D32;
        }
        
        .new-section {
            border-left: 4px solid #75e6da;
        }
        
        .section-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .section-title i {
            font-size: 18px;
        }
        
        .current-title {
            color: #2E7D32;
        }
        
        .new-title {
            color: #75e6da;
        }
        
        .current-question, .current-answer {
            margin-bottom: 15px;
        }
        
        .current-question label, .current-answer label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            display: block;
        }
        
        .current-question .question-text {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            word-break: break-word;
        }
        
        .current-answer .answer-text {
            font-size: 16px;
            color: #2E7D32;
            font-weight: 500;
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
            word-break: break-word;
        }
        
        .current-answer .answer-text i {
            color: #2E7D32;
            flex-shrink: 0;
        }
        
        .new-field {
            margin-bottom: 20px;
        }
        
        .new-field label {
            font-size: 13px;
            color: #1e293b;
            margin-bottom: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .new-field label i {
            color: #75e6da;
        }
        
        .new-field input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .new-field input:focus {
            border-color: #75e6da;
            box-shadow: 0 0 0 3px rgba(117, 230, 218, 0.1);
            outline: none;
        }
        
        .new-field input::placeholder {
            color: #94a3b8;
            font-style: italic;
        }
        
        .update-hint {
            background: rgba(117, 230, 218, 0.1);
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 15px;
            font-size: 13px;
            color: #2E7D32;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .update-hint i {
            font-size: 16px;
        }
        
        /* Security Questions Note */
        .security-note {
            background: rgba(117, 230, 218, 0.1);
            border-left: 4px solid #75e6da;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .security-note i {
            color: #2E7D32;
            font-size: 24px;
        }
        
        .security-note p {
            color: #1e293b;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
        }
        
        .security-note strong {
            color: #2E7D32;
        }
        
        /* Debug info - temporary */
        .debug-info {
            background: #f0f0f0;
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            font-family: monospace;
            display: none;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 20px;
            margin-top: 30px;
            justify-content: flex-end;
        }
        
        .btn-save, .btn-cancel {
            padding: 14px 35px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.5px;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            color: white;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }
        
        .btn-save:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.4);
        }
        
        .btn-cancel {
            background: #f8f9fa;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }
        
        .btn-cancel:hover {
            background: #e74c3c;
            color: white;
            border-color: #e74c3c;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
        }
        
        .btn-cancel:hover i {
            color: white;
        }
        
        .success-pulse {
            animation: successPulse 0.5s ease;
        }
        
        @keyframes successPulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.3);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .text-success {
            color: #28a745;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .content-wrapper {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .security-split {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .profile-avatar h3 {
                font-size: 24px;
            }
        }
        
        @media (max-width: 768px) {
            .welcome-box2 {
                font-size: 1.4rem;
                padding: 15px 20px;
            }
            
            .profile-avatar {
                padding: 30px 20px;
            }
            
            .avatar-circle {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-save, .btn-cancel {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .content-wrapper {
                padding: 15px;
            }
            
            .welcome-box2 {
                font-size: 1.2rem;
            }
            
            .info-item {
                flex-direction: column;
                text-align: center;
            }
            
            .info-item i {
                margin-bottom: 10px;
            }
            
            .profile-avatar p {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    
<!-- Header -->
<?php include_once("./includes/header.php"); ?>

<main class="content">
    <!-- FIXED: Content wrapper with proper scrolling -->
    <div class="content-wrapper" style="overflow-y: auto !important; height: calc(100vh - 120px);">
        <!-- Profile Header -->
        <div class="welcome-box2">
            <i class="fas fa-user-cog"></i> USER PROFILE
        </div>

        <div class="profile-container">
            <!-- Profile Avatar - Enhanced -->
            <div class="profile-avatar">
                <div class="avatar-circle <?php echo $role; ?>">
                    <?php echo $firstLetter; ?>
                </div>
                <h3><?php echo htmlspecialchars($currentUsername); ?></h3>
                <p>
                    <i class="fas fa-user-shield"></i> 
                    <?php echo ucfirst($role); ?> Account
                    <span class="role-badge <?php echo $role; ?>"><?php echo strtoupper($role); ?></span>
                    <span class="account-type">
                        <i class="fas fa-database"></i> 
                        <?php echo ($user_type === 'super_user') ? 'Administrator' : 'User Account'; ?>
                    </span>
                </p>
            </div>

            <!-- Messages -->
            <?php if (!empty($error_message)): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success_message)): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <!-- Account Information - Container with Header Color -->
            <div class="info-container">
                <h3><i class="fas fa-user-circle"></i> Account Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <div class="info-content">
                            <div class="info-label">Username</div>
                            <div class="info-value"><?php echo htmlspecialchars($currentUsername); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-user-tag"></i>
                        <div class="info-content">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($full_name); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="info-content">
                            <div class="info-label">Account Created</div>
                            <div class="info-value"><?php echo date('F d, Y'); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-key"></i>
                        <div class="info-content">
                            <div class="info-label">Password Status</div>
                            <div class="info-value">
                                <i class="fas fa-check-circle text-success"></i> Active
                            </div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-shield-alt"></i>
                        <div class="info-content">
                            <div class="info-label">Security Level</div>
                            <div class="info-value"><?php echo ucfirst($role); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Update Profile Form -->
            <form method="POST" action="" id="profileForm">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-section">
                    <h3><i class="fas fa-edit"></i> Update Profile</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">
                                <i class="fas fa-user"></i> New Username
                            </label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($currentUsername); ?>" 
                                   required
                                   placeholder="Enter new username">
                        </div>
                        
                        <div class="form-group password-group">
                            <label for="password">
                                <i class="fas fa-lock"></i> New Password
                            </label>
                            <input type="password" id="password" name="password" 
                                   value="<?php echo htmlspecialchars($currentPassword); ?>" 
                                   required
                                   placeholder="Enter new password">
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Security Questions - SPLIT DESIGN: Current (left) and New (right) -->
                <div class="form-section">
                    <h3><i class="fas fa-question-circle"></i> Security Questions</h3>
                    
                    <!-- Security Questions Note -->
                    <div class="security-note">
                        <i class="fas fa-shield-alt"></i>
                        <p><strong>Your current security questions are shown on the left.</strong> To update, enter new questions and answers on the right. Leave fields empty to keep current values.</p>
                    </div>
                    
                    <!-- Security Question 1 -->
                    <div class="security-container">
                        <div class="security-header">
                            <i class="fas fa-question-circle"></i> Security Question #1
                        </div>
                        
                        <div class="security-split">
                            <!-- Current Section - Left Side -->
                            <div class="current-section">
                                <div class="section-title current-title">
                                    <i class="fas fa-eye"></i> Current Question & Answer
                                </div>
                                <div class="current-question">
                                    <label><i class="fas fa-pencil-alt"></i> Question:</label>
                                    <div class="question-text"><?php echo !empty($security_q1) ? htmlspecialchars($security_q1) : '<em style="color:#999;">No question set</em>'; ?></div>
                                </div>
                                <div class="current-answer">
                                    <label><i class="fas fa-lock"></i> Answer:</label>
                                    <div class="answer-text">
                                        <i class="fas fa-check-circle"></i> <?php echo !empty($security1) ? htmlspecialchars($security1) : '<em style="color:#999;">No answer set</em>'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- New Section - Right Side - Keep predefined text but remove history -->
                            <div class="new-section">
                                <div class="section-title new-title">
                                    <i class="fas fa-pencil-alt"></i> New Question & Answer
                                </div>
                                <div class="new-field">
                                    <label for="security_question1"><i class="fas fa-question"></i> New Question</label>
                                    <input type="text" id="security_question1" name="security_question1" 
                                           placeholder="Enter new question (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="new-field">
                                    <label for="security_answer1"><i class="fas fa-lock"></i> New Answer</label>
                                    <input type="text" id="security_answer1" name="security_answer1" 
                                           placeholder="Enter new answer (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="update-hint">
                                    <i class="fas fa-info-circle"></i> Leave empty to keep current values
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Question 2 -->
                    <div class="security-container">
                        <div class="security-header">
                            <i class="fas fa-question-circle"></i> Security Question #2
                        </div>
                        
                        <div class="security-split">
                            <!-- Current Section - Left Side -->
                            <div class="current-section">
                                <div class="section-title current-title">
                                    <i class="fas fa-eye"></i> Current Question & Answer
                                </div>
                                <div class="current-question">
                                    <label><i class="fas fa-pencil-alt"></i> Question:</label>
                                    <div class="question-text"><?php echo !empty($security_q2) ? htmlspecialchars($security_q2) : '<em style="color:#999;">No question set</em>'; ?></div>
                                </div>
                                <div class="current-answer">
                                    <label><i class="fas fa-lock"></i> Answer:</label>
                                    <div class="answer-text">
                                        <i class="fas fa-check-circle"></i> <?php echo !empty($security2) ? htmlspecialchars($security2) : '<em style="color:#999;">No answer set</em>'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- New Section - Right Side - Keep predefined text but remove history -->
                            <div class="new-section">
                                <div class="section-title new-title">
                                    <i class="fas fa-pencil-alt"></i> New Question & Answer
                                </div>
                                <div class="new-field">
                                    <label for="security_question2"><i class="fas fa-question"></i> New Question</label>
                                    <input type="text" id="security_question2" name="security_question2" 
                                           placeholder="Enter new question (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="new-field">
                                    <label for="security_answer2"><i class="fas fa-lock"></i> New Answer</label>
                                    <input type="text" id="security_answer2" name="security_answer2" 
                                           placeholder="Enter new answer (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="update-hint">
                                    <i class="fas fa-info-circle"></i> Leave empty to keep current values
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Question 3 -->
                    <div class="security-container">
                        <div class="security-header">
                            <i class="fas fa-question-circle"></i> Security Question #3
                        </div>
                        
                        <div class="security-split">
                            <!-- Current Section - Left Side -->
                            <div class="current-section">
                                <div class="section-title current-title">
                                    <i class="fas fa-eye"></i> Current Question & Answer
                                </div>
                                <div class="current-question">
                                    <label><i class="fas fa-pencil-alt"></i> Question:</label>
                                    <div class="question-text"><?php echo !empty($security_q3) ? htmlspecialchars($security_q3) : '<em style="color:#999;">No question set</em>'; ?></div>
                                </div>
                                <div class="current-answer">
                                    <label><i class="fas fa-lock"></i> Answer:</label>
                                    <div class="answer-text">
                                        <i class="fas fa-check-circle"></i> <?php echo !empty($security3) ? htmlspecialchars($security3) : '<em style="color:#999;">No answer set</em>'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- New Section - Right Side - Keep predefined text but remove history -->
                            <div class="new-section">
                                <div class="section-title new-title">
                                    <i class="fas fa-pencil-alt"></i> New Question & Answer
                                </div>
                                <div class="new-field">
                                    <label for="security_question3"><i class="fas fa-question"></i> New Question</label>
                                    <input type="text" id="security_question3" name="security_question3" 
                                           placeholder="Enter new question (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="new-field">
                                    <label for="security_answer3"><i class="fas fa-lock"></i> New Answer</label>
                                    <input type="text" id="security_answer3" name="security_answer3" 
                                           placeholder="Enter new answer (leave empty to keep current)"
                                           value="" autocomplete="off">
                                </div>
                                <div class="update-hint">
                                    <i class="fas fa-info-circle"></i> Leave empty to keep current values
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Update Profile & Security Settings
                    </button>
                    <button type="button" class="btn-cancel" onclick="resetForm()">
                        <i class="fas fa-undo-alt"></i> Reset Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Footer -->
<?php include_once("./includes/footer.php"); ?>

<?php include_once("./modal/logout-modal.php"); ?>

<script>
    // Toggle password visibility
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.password-toggle i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // Reset form to original values
    function resetForm() {
        if (confirm('Are you sure you want to reset all changes? This will clear all new question fields.')) {
            document.getElementById('profileForm').reset();
            // Reset password toggle
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle i');
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // Form validation
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value.trim();
        
        if (!username || !password) {
            e.preventDefault();
            alert('Please fill in all required fields before updating your profile.');
            return false;
        }
        
        if (password.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long.');
            return false;
        }
        
        // Confirm profile update
        if (confirm('Are you sure you want to update your profile? Any new security questions and answers will be saved.')) {
            // Add success animation to button
            const submitBtn = document.querySelector('.btn-save');
            submitBtn.classList.add('success-pulse');
            setTimeout(() => {
                submitBtn.classList.remove('success-pulse');
            }, 500);
            
            return true;
        } else {
            e.preventDefault();
            return false;
        }
    });

    // Auto-hide messages after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const successMessage = document.querySelector('.message.success');
        if (successMessage) {
            setTimeout(() => {
                successMessage.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (successMessage.parentNode) {
                        successMessage.remove();
                    }
                }, 300);
            }, 5000);
        }
        
        const errorMessage = document.querySelector('.message.error');
        if (errorMessage) {
            setTimeout(() => {
                errorMessage.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (errorMessage.parentNode) {
                        errorMessage.remove();
                    }
                }, 300);
            }, 5000);
        }
    });

    // Add slideOut animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }
    `;
    document.head.appendChild(style);
</script>

</body>
</html>