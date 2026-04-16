<?php
session_start();
if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}
include_once("connection.php");

// Handle CRUD operations for sites
// ... (similar to employee management)

// Display sites table with add/edit/delete options
?>