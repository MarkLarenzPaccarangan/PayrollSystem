<?php 
session_start();
include 'connection.php';

// Handle form submission for adding a new custom deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_custom_deduction'])) {
    $deduction_name = $_POST['deduction_name'];

    // Insert new deduction into the database
    $query = "INSERT INTO custom_deductions (name) VALUES (?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $deduction_name);

    if ($stmt->execute()) {
        echo "<script>alert('Custom Deduction Added Successfully!'); window.location.href='add_deduction.php';</script>";
    } else {
        echo "<script>alert('Error: " . $conn->error . "');</script>";
    }

    $stmt->close();
}

// Handle removal of a custom deduction
if (isset($_GET['remove'])) {
    $deduction_id = (int)$_GET['remove'];
    $stmt = $conn->prepare("DELETE FROM custom_deductions WHERE id = ?");
    $stmt->bind_param("i", $deduction_id);
    if ($stmt->execute()) {
        echo "<script>alert('Custom Deduction Removed Successfully!'); window.location.href='add_deduction.php';</script>";
    } else {
        echo "<script>alert('Error: " . $conn->error . "');</script>";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="./assets/css/add_deduction.css">
</head>
<body>

    <!-- header -->
    <?php include_once("./includes/header.php"); ?>

    <!-- sidebar -->
    <?php include_once("./includes/sidebar.php"); ?>

    <main class="content">        
        <div class="deduction-box">Add Custom Deduction</div>
        <form method="POST" action="add_deduction.php">
            <label for="deduction-name">Deduction Name:</label>
            <input type="text" id="deduction-name" name="deduction_name" required>
            <button type="submit" name="add_custom_deduction">Add Deduction</button>
        </form>
    
        <h3>Existing Custom Deductions</h3>
<table class="deduction-table">
    <thead>
        <tr>
            <th>Deduction Name</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Fetch all custom deductions
        $result = $conn->query("SELECT * FROM custom_deductions");
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "<td><button class='remove-btn' onclick='return confirm(\"Are you sure you want to remove this deduction?\") ? window.location.href=\"add_deduction.php?remove=" . (int)$row['id'] . "\" : false;'>Remove</button></td>";
                echo "</tr>";
            }
        } else {
            echo "<tr>";
            echo "<td colspan='3' class='empty-row'>No custom deductions found.</td>";
            echo "</tr>";
        }
        ?>
    </tbody>
</table>
<a href="deductionList.php" class="back-btn">Back to Deduction List</a>
    </main>

    <!-- footer -->
    <?php include_once("./includes/footer.php"); ?>
    <!-- modal -->
    <?php include_once("./modal/logout-modal.php"); ?>

</body>
</html>
