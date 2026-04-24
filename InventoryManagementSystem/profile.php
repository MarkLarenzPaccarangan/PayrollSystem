<?php
require_once 'config.php';
require_once 'user.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$user = new User($conn);
$current_user = $user->getUserById($_SESSION['user_id']);

// Check if user exists
if (!$current_user) {
    session_destroy();
    header("Location: login.php?error=user_not_found");
    exit();
}

$message = '';
$message_type = '';

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_username'])) {
        $new_username = $conn->real_escape_string($_POST['new_username']);
        $result = $user->updateUsername($_SESSION['user_id'], $new_username);
        
        if ($result['success']) {
            $message = $result['message'];
            $message_type = 'success';
            $current_user['username'] = $new_username;
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $message = 'New password and confirm password do not match';
            $message_type = 'error';
        } else {
            $result = $user->updatePassword($_SESSION['user_id'], $current_password, $new_password);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'error';
        }
    }
}

// Include header from include folder
include 'include/header.php';
?>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container"></div>

<div class="profile-wrapper">
    <div class="profile-container">
        
        <!-- USER PROFILE Header -->
        <div class="user-profile-header">
            <div class="user-profile-title">
                <i class="fas fa-user-circle"></i>
                <h1>USER PROFILE</h1>
            </div>
            <div class="user-profile-badge">
                <span class="profile-badge">Profile Settings</span>
            </div>
        </div>

        <!-- Display toast notification for messages -->
        <?php if ($message): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo $message_type; ?>');
                });
            </script>
        <?php endif; ?>

        <!-- Update Profile Container -->
        <div class="update-profile-container">
            <div class="update-profile-header">
                <i class="fas fa-edit"></i>
                <h3>Update Profile</h3>
                <p>Modify your username and password</p>
            </div>
            
            <div class="update-profile-content">
                <form method="POST" action="" class="profile-update-form" id="profileForm">
                    <!-- CHANGE USERNAME SECTION -->
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-user-edit"></i>
                            <h4>Change Username</h4>
                        </div>
                        <div class="form-group">
                            <label for="new_username">
                                <i class="fas fa-user"></i>
                                <span>New Username</span>
                            </label>
                            <input type="text" 
                                   id="new_username" 
                                   name="new_username" 
                                   value="<?php echo isset($current_user['username']) ? htmlspecialchars($current_user['username']) : ''; ?>"
                                   placeholder="Enter new username"
                                   required>
                        </div>
                    </div>

                    <!-- CHANGE PASSWORD SECTION -->
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-lock"></i>
                            <h4>Change Password</h4>
                        </div>
                        
                        <div class="form-group">
                            <label for="current_password">
                                <i class="fas fa-lock"></i>
                                <span>Current Password</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" 
                                       id="current_password" 
                                       name="current_password" 
                                       placeholder="Enter current password"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye" id="toggle_current_password"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="new_password">
                                <i class="fas fa-key"></i>
                                <span>New Password</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" 
                                       id="new_password" 
                                       name="new_password" 
                                       placeholder="Enter new password (min. 8 characters)"
                                       pattern=".{8,}" 
                                       title="Password must be at least 8 characters"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye" id="toggle_new_password"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">
                                <i class="fas fa-check-circle"></i>
                                <span>Confirm Password</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       placeholder="Confirm new password"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                    <i class="fas fa-eye" id="toggle_confirm_password"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- BUTTONS - Update, Cancel, and Logout -->
                    <div class="form-actions">
                        <button type="submit" name="update_username" class="btn-update" id="updateUsernameBtn">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                        <button type="button" class="btn-cancel" onclick="resetForm()">
                            <i class="fas fa-undo-alt"></i> Cancel
                        </button>
                        
                    
                    <!-- Hidden submit for password update (will be triggered by JS) -->
                    <input type="hidden" name="update_password" id="update_password_field" value="1">
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal (adapted from footer.php) -->
<div id="logoutModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2>CONFIRM LOGOUT</h2>
            <button class="close-btn" onclick="closeLogoutModal()">&times;</button>
        </div>
        <div style="padding: 30px; text-align: center;">
            <i class="fas fa-sign-out-alt" style="font-size: 48px; color: #e84393; margin-bottom: 20px;"></i>
            <p style="margin-bottom: 20px; color: var(--text-primary);">Are you sure you want to logout?</p>
            <div style="display: flex; gap: 15px;">
                <button class="btn btn-danger" onclick="confirmLogout()" style="flex: 1;">YES,LOGOUT</button>
                <button class="btn btn-secondary" onclick="closeLogoutModal()" style="flex: 1;">CANCEL</button>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --profile-primary: #75e6da;
    --profile-primary-dark: #5bc8be;
    --profile-primary-light: #a1f0e8;
    --profile-secondary: #6c5ce7;
    --profile-success: #2ecc71;
    --profile-danger: #e74c3c;
    
    /* Light theme variables (default) */
    --profile-bg-start: #f5f7fa;
    --profile-bg-end: #e9ecef;
    --profile-card-bg: #ffffff;
    --profile-text-primary: #2c3e50;
    --profile-text-secondary: #5a6a7a;
    --profile-border: #d0e6e3;
    --profile-input-bg: #f8fafc;
    --profile-input-border: #d0e6e3;
    --profile-input-text: #2c3e50;
    --profile-shadow: rgba(0, 0, 0, 0.1);
    --profile-toast-bg: rgba(255, 255, 255, 0.98);
}

/* Dark theme variables */
body:not(.light-theme) {
    --profile-bg-start: #1a1a1a;
    --profile-bg-end: #2c2c2c;
    --profile-card-bg: #2c2c2c;
    --profile-text-primary: #ffffff;
    --profile-text-secondary: #b0b0b0;
    --profile-border: #404040;
    --profile-input-bg: #3c3c3c;
    --profile-input-border: #404040;
    --profile-input-text: #ffffff;
    --profile-shadow: rgba(0, 0, 0, 0.4);
    --profile-toast-bg: rgba(44, 44, 44, 0.98);
}

/* Toast Notification Container */
.toast-container {
    position: fixed;
    bottom: 40px;
    right: 40px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 15px;
    pointer-events: none;
}

.toast-notification {
    min-width: 350px;
    max-width: 450px;
    padding: 18px 22px;
    border-radius: 14px;
    background: var(--profile-toast-bg);
    box-shadow: 0 15px 35px var(--profile-shadow);
    display: flex;
    align-items: center;
    gap: 15px;
    transform: translateX(100%);
    opacity: 0;
    animation: slideInRight 0.3s ease forwards;
    pointer-events: auto;
    border-left: 5px solid transparent;
    backdrop-filter: blur(10px);
    font-size: 15px;
}

.toast-notification.success {
    border-left-color: var(--profile-success);
}

.toast-notification.success i:first-child {
    color: var(--profile-success);
}

.toast-notification.error {
    border-left-color: var(--profile-danger);
}

.toast-notification.error i:first-child {
    color: var(--profile-danger);
}

.toast-notification i:first-child {
    font-size: 26px;
}

.toast-content {
    flex: 1;
    font-weight: 500;
    color: var(--profile-text-primary);
    font-size: 15px;
}

.toast-close {
    background: none;
    border: none;
    color: var(--profile-text-secondary);
    cursor: pointer;
    font-size: 20px;
    padding: 0;
    transition: all 0.3s ease;
    opacity: 0.6;
}

.toast-close:hover {
    opacity: 1;
    color: var(--profile-primary);
    transform: scale(1.15);
}

.toast-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--profile-primary), var(--profile-secondary));
    border-radius: 0 0 0 14px;
    animation: progress 5s linear forwards;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

@keyframes progress {
    from {
        width: 100%;
    }
    to {
        width: 0%;
    }
}

/* Main Wrapper - NO BACKGROUND */
.profile-wrapper {
    padding: 30px;
    min-height: calc(100vh - 200px);
    background: transparent !important;
    transition: background 0.3s ease;
}
/* Profile Container */
.profile-container {
    max-width: 800px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 25px;
}

/* USER PROFILE HEADER */
.user-profile-header {
    background: #75e6da;
    border-radius: 16px;
    padding: 20px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 8px 20px rgba(117, 230, 218, 0.3);
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.user-profile-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-profile-title i {
    font-size: 36px;
    color: white;
    filter: drop-shadow(2px 2px 4px rgba(0, 0, 0, 0.2));
}

.user-profile-title h1 {
    color: white;
    font-size: 28px;
    font-weight: 800;
    margin: 0;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    letter-spacing: 1px;
}

.user-profile-badge .profile-badge {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 8px 18px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 600;
    backdrop-filter: blur(5px);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

/* UPDATE PROFILE CONTAINER */
.update-profile-container {
    background: var(--profile-card-bg);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 25px var(--profile-shadow);
    border: 1px solid rgba(117, 230, 218, 0.2);
}

.update-profile-header {
    background: linear-gradient(135deg, rgba(117, 230, 218, 0.15), rgba(108, 92, 231, 0.15));
    padding: 20px 25px;
    border-bottom: 3px solid var(--profile-primary);
}

.update-profile-header i {
    font-size: 26px;
    color: var(--profile-primary);
    margin-bottom: 8px;
}

.update-profile-header h3 {
    color: var(--profile-text-primary);
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 5px 0;
}

.update-profile-header p {
    color: var(--profile-text-secondary);
    font-size: 14px;
    margin: 0;
}

.update-profile-content {
    padding: 25px;
}

/* Form Sections */
.form-section {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid rgba(117, 230, 218, 0.2);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.section-header i {
    font-size: 20px;
    color: var(--profile-primary);
}

.section-header h4 {
    color: var(--profile-text-primary);
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}

/* Form Groups */
.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
    color: var(--profile-text-primary);
    font-size: 14px;
    font-weight: 500;
}

.form-group label i {
    color: var(--profile-primary);
    font-size: 14px;
}

.form-group input {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid var(--profile-input-border);
    border-radius: 12px;
    font-size: 15px;
    transition: all 0.3s ease;
    background: var(--profile-input-bg);
    color: var(--profile-input-text);
}

.form-group input:focus {
    outline: none;
    border-color: var(--profile-primary);
    box-shadow: 0 0 0 4px rgba(117, 230, 218, 0.15);
}

.form-group input:hover {
    border-color: var(--profile-primary-light);
}

.form-group input::placeholder {
    color: var(--profile-text-secondary);
    opacity: 0.7;
    font-size: 14px;
}

/* Password Wrapper */
.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 45px;
}

.password-toggle {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--profile-primary);
    cursor: pointer;
    padding: 8px;
    font-size: 16px;
    transition: all 0.3s ease;
}

.password-toggle:hover {
    color: var(--profile-primary-dark);
    transform: translateY(-50%) scale(1.15);
}

/* Form Actions - Buttons side by side */
.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 25px;
}

.btn-update, .btn-cancel, .btn-logout-profile {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 20px;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-update {
    background: linear-gradient(135deg, var(--profile-primary), var(--profile-secondary));
    color: white;
    box-shadow: 0 8px 20px rgba(117, 230, 218, 0.25);
}

.btn-update:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 25px rgba(117, 230, 218, 0.35);
}

.btn-cancel {
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
}

.btn-cancel:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(231, 76, 60, 0.25);
}

.btn-logout-profile {
    background: #f1f5f9;
    color: #e74c3c;
    border: 2px solid #fecaca;
}

.btn-logout-profile:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(231, 76, 60, 0.25);
}

.btn-update::before, .btn-cancel::before, .btn-logout-profile::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.btn-update:hover::before, .btn-cancel:hover::before, .btn-logout-profile:hover::before {
    width: 350px;
    height: 350px;
}

/* Modal Styles (from footer.php) */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

.modal-content {
    background: var(--profile-card-bg);
    margin: 10% auto;
    border-radius: 16px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(117, 230, 218, 0.2);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 25px;
    border-bottom: 2px solid rgba(117, 230, 218, 0.2);
}

.modal-header h2 {
    margin: 0;
    color: var(--profile-text-primary);
    font-size: 22px;
    font-weight: 600;
}

.close-btn {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: var(--profile-text-secondary);
    transition: all 0.3s ease;
    line-height: 1;
}

.close-btn:hover {
    color: var(--profile-primary);
    transform: rotate(90deg);
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-danger {
    background: linear-gradient(135deg, #ff6b6b, #e74c3c);
    color: white;
    border: none;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes fadeOut {
    from {
        opacity: 1;
    }
    to {
        opacity: 0;
    }
}

/* Dark theme adjustments */
body:not(.light-theme) .btn-cancel {
    background: #334155;
    color: #f1f5f9;
    border-color: #475569;
}

body:not(.light-theme) .btn-cancel:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
}

body:not(.light-theme) .btn-logout-profile {
    background: #334155;
    color: #fca5a5;
    border-color: #7f1d1d;
}

body:not(.light-theme) .btn-logout-profile:hover {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
}

/* Responsive */
@media (max-width: 768px) {
    .profile-wrapper {
        padding: 15px;
    }
    
    .profile-container {
        max-width: 100%;
    }
    
    .user-profile-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
        padding: 20px;
    }
    
    .user-profile-title {
        flex-direction: column;
        text-align: center;
    }
    
    .user-profile-title h1 {
        font-size: 24px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .toast-container {
        bottom: 20px;
        right: 20px;
        left: 20px;
    }
    
    .toast-notification {
        min-width: auto;
        max-width: 100%;
    }
    
    .modal-content {
        margin: 20% auto;
        width: 95%;
    }
}
</style>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const toggleIcon = document.getElementById('toggle_' + inputId);
    
    if (input.type === 'password') {
        input.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        toggleIcon.className = 'fas fa-eye';
    }
}

// Toast notification function
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <div class="toast-content">${message}</div>
        <button class="toast-close" onclick="this.closest('.toast-notification').remove()">&times;</button>
        <div class="toast-progress"></div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, 5000);
    
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        toast.style.animation = 'slideOutRight 0.3s ease forwards';
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 300);
    });
}

// Reset form function
function resetForm() {
    // Reset the form to its initial values
    document.getElementById('profileForm').reset();
    
    // Restore the original username value
    document.getElementById('new_username').value = '<?php echo isset($current_user['username']) ? htmlspecialchars($current_user['username']) : ''; ?>';
    
    // Clear all password fields
    document.getElementById('current_password').value = '';
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
    
    // Show success toast notification
    showToast('Form has been reset', 'success');
}

// Logout Modal Functions (adapted from footer.php)
function openLogoutModal() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

function closeLogoutModal() {
    const modal = document.getElementById('logoutModal');
    modal.style.animation = 'fadeOut 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
    }, 200);
}

function confirmLogout() {
    window.location.href = 'logout.php';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('logoutModal');
    if (event.target == modal) {
        modal.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            modal.style.display = 'none';
            modal.style.animation = '';
        }, 200);
    }
};

// Form validation and submission
document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const username = document.getElementById('new_username').value;
    const currentPass = document.getElementById('current_password').value;
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    
    // Check if any password fields are filled
    const hasPasswordChanges = currentPass || newPass || confirmPass;
    
    // Username validation
    if (username.length < 3) {
        showToast('Username must be at least 3 characters!', 'error');
        return;
    }
    
    // Password validation (if any password field is filled)
    if (hasPasswordChanges) {
        if (!currentPass) {
            showToast('Please enter your current password', 'error');
            return;
        }
        
        if (newPass !== confirmPass) {
            showToast('New passwords do not match!', 'error');
            return;
        }
        
        if (newPass.length < 8) {
            showToast('New password must be at least 8 characters!', 'error');
            return;
        }
        
        // Submit with password update
        this.submit();
    } else {
        // Only update username
        // Create a copy of the form and submit only username
        const usernameForm = document.createElement('form');
        usernameForm.method = 'POST';
        usernameForm.action = '';
        
        const usernameInput = document.createElement('input');
        usernameInput.type = 'hidden';
        usernameInput.name = 'new_username';
        usernameInput.value = username;
        usernameForm.appendChild(usernameInput);
        
        const updateInput = document.createElement('input');
        updateInput.type = 'hidden';
        updateInput.name = 'update_username';
        updateInput.value = '1';
        usernameForm.appendChild(updateInput);
        
        document.body.appendChild(usernameForm);
        usernameForm.submit();
    }
});

// Theme synchronization with header
document.addEventListener('DOMContentLoaded', function() {
    const isLightTheme = document.body.classList.contains('light-theme');
    console.log('Current theme:', isLightTheme ? 'Light' : 'Dark');
});

// Theme observer
const observer2 = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.attributeName === 'class') {
            const isLightTheme = document.body.classList.contains('light-theme');
            console.log('Theme changed to:', isLightTheme ? 'Light' : 'Dark');
        }
    });
});

observer2.observe(document.body, { attributes: true });
</script>

<?php
// Include footer if you have one
// include 'include/footer.php';
?>

