<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $to = $_POST['employee_email'];
    $subject = "Your Salary Slip";
    $message = "Dear Employee, your salary slip details are attached below.";
    $headers = "From: admin@company.com";

    // Send email (You can also attach the salary slip if needed)
    if (mail($to, $subject, $message, $headers)) {
        echo "Salary slip sent to " . htmlspecialchars($to);
    } else {
        echo "Failed to send the salary slip.";
    }
}
?>