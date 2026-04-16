<?php
$conn = new mysqli('localhost', 'root', '', 'payrollsystem');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}