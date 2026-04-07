<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role'])) {
    $_SESSION['reset_role_request'] = $_POST['role'];
    echo 'success';
} else {
    echo 'error';
}
?>