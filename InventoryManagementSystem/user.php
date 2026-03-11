<?php
require_once 'config.php';

class User {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Get user by ID
    public function getUserById($user_id) {
        $stmt = $this->conn->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    // Update username
    public function updateUsername($user_id, $new_username) {
        // Check if username already exists
        $check = $this->conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->bind_param("si", $new_username, $user_id);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'Username already exists'];
        }
        
        $stmt = $this->conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $new_username, $user_id);
        
        if ($stmt->execute()) {
            // Update session
            $_SESSION['username'] = $new_username;
            return ['success' => true, 'message' => 'Username updated successfully'];
        }
        
        return ['success' => false, 'message' => 'Failed to update username: ' . $this->conn->error];
    }
    
    // Update password - FIXED with proper MD5 handling
    // Update password - PLAIN TEXT VERSION (FOR LOCAL DEV ONLY)
public function updatePassword($user_id, $current_password, $new_password) {
    // Get current password from database
    $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'User not found'];
    }
    
    $user = $result->fetch_assoc();
    
    // Plain text comparison
    if ($user['password'] !== $current_password) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }
    
    // Store as plain text (NOT RECOMMENDED for production)
    $update = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update->bind_param("si", $new_password, $user_id);
    
    if ($update->execute()) {
        return ['success' => true, 'message' => 'Password updated successfully'];
    }
    
    return ['success' => false, 'message' => 'Failed to update password: ' . $this->conn->error];
}
    // Verify if user exists (for login) - FIXED with proper MD5 handling
    // Verify if user exists (for login) - PLAIN TEXT VERSION
public function verifyLogin($username, $password) {
    $stmt = $this->conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Plain text comparison
        if ($user['password'] === $password) {
            return ['id' => $user['id'], 'username' => $user['username']];
        }
    }
    
    return false;
}
}
?>

