<?php
session_start();

// Redirect if the admin is not logged in
if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include 'connection.php'; // Include database connection

// Default salary
$default_salary = 7500;

// Check if the salary has been assigned
$query = "SELECT id, first_name, last_name, salary FROM employees WHERE salary IS NULL OR salary = 0";
$result = $conn->query($query);

// Set salary for employees with no salary set
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_salary'])) {
    $employee_id = $_POST['employee_id'];

    // Update the salary to 7500
    $update_query = "UPDATE employees SET salary = ? WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("ii", $default_salary, $employee_id);

    if ($stmt->execute()) {
        echo "<script>alert('Salary assigned successfully!'); window.location.href='manageSalary.php';</script>";
    } else {
        echo "<script>alert('Error: " . $conn->error . "');</script>";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Salary</title>
    <link rel="stylesheet" href="./assets/css/deduction.css">
</head>
<body>

<!-- Header -->
 <?php include_once("./includes/header.php"); ?>

<!-- Sidebar -->
  <?php include_once("./includes/sidebar.php"); ?>


<!-- Main Content -->
<main class="content">
    <div class="deduction-box">MANAGE SALARY</div>

    <!-- Salary Assignment Form -->
    <div class="salary-form">
        <h3 style="text-align: center;">ASSIGN SALARY</h3>
        <form method="POST" action="manageSalary.php">
            <label for="employee-id">Employee</label>
            <select id="employee-id" name="employee_id" required>
                <option value="">Select Employee</option>
                <?php
                while ($employee = $result->fetch_assoc()) {
                    echo "<option value='{$employee['id']}'>{$employee['first_name']} {$employee['last_name']}</option>";
                }
                ?>
            </select>

            <button type="submit" name="assign_salary">Assign Salary of 7,500</button>
        </form>
    </div>

    <!-- Employee Salary List -->
    <table class="deduction-table">
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Employee Name</th>
                <th>Current Salary</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Fetch employees with no salary or zero salary
            $query = "SELECT id, first_name, last_name, salary FROM employees WHERE salary IS NULL OR salary = 0";
            $result = $conn->query($query);
            while ($row = $result->fetch_assoc()) {
                echo "<tr>
                        <td>{$row['id']}</td>
                        <td>{$row['first_name']} {$row['last_name']}</td>
                        <td>Not Assigned</td>
                        <td>
                            <form method='POST' action='manageSalary.php'>
                                <input type='hidden' name='employee_id' value='{$row['id']}'>
                                <button type='submit' name='assign_salary'>Assign 7,500</button>
                            </form>
                        </td>
                    </tr>";
            }
            ?>
        </tbody>
    </table>
</main>

<!-- Footer -->
 <?php include_once("./includes/footer.php"); ?>
</body>
</html>
