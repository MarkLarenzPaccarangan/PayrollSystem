<?php
session_start();

// Redirect if the admin is not logged in
if (!isset($_SESSION['Admin_User'])) {
    header("Location: login.php");
    exit;
}

include 'connection.php';

// Handle form submission for adding deductions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_deduction'])) {
    $employee_id = $_POST['employee_id'];
    $deduction_names = $_POST['deduction_names'];
    $deduction_amounts = $_POST['deduction_amounts'];
    $deduction_start_dates = $_POST['deduction_start_date'];
    $deduction_end_dates = $_POST['deduction_end_date'];

    foreach ($deduction_names as $index => $deduction_name) {
        $deduction_amount = $deduction_amounts[$index];
        $deduction_start_date = $deduction_start_dates[$index];
        $deduction_end_date = $deduction_end_dates[$index];

        // Insert deduction without Date Applied
        $insertQuery = "INSERT INTO deduction (employee_id, deduction_name, amount, start_date, end_date, status) 
                        VALUES (?, ?, ?, ?, ?, 'active')";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("isdss", $employee_id, $deduction_name, $deduction_amount, $deduction_start_date, $deduction_end_date);
        $stmt->execute();
    }

    echo "<script>alert('Deductions Applied Successfully!'); window.location.href='deductionList.php';</script>";
}

// Handle deletion of a deduction
if (isset($_GET['delete'])) {
    $deduction_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM deduction WHERE id = ?");
    $stmt->bind_param("i", $deduction_id);
    $stmt->execute();
    echo "<script>alert('Deduction has been deleted!'); window.location.href='deductionList.php';</script>";
}

// Handle enabling or disabling a deduction
if (isset($_GET['toggle_status']) && isset($_GET['deduction_id'])) {
    $deduction_id = $_GET['deduction_id'];
    $current_status = $_GET['status'];

    // Toggle the status
    $new_status = ($current_status === 'active') ? 'disabled' : 'active';

    // Update the deduction status
    $stmt = $conn->prepare("UPDATE deduction SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $deduction_id);
    $stmt->execute();
    echo "<script>alert('Deduction status updated!'); window.location.href='deductionList.php';</script>";
}

// Query to fetch data for deduction list with grouping for employee deductions
$employeeDeductionsQuery = "
    SELECT d.id, d.employee_id, e.first_name, e.last_name, e.monthly_salary, 
           GROUP_CONCAT(d.deduction_name ORDER BY d.start_date) AS deduction_names, 
           GROUP_CONCAT(d.amount ORDER BY d.start_date) AS amounts,
           GROUP_CONCAT(d.start_date ORDER BY d.start_date) AS start_dates,
           GROUP_CONCAT(d.end_date ORDER BY d.start_date) AS end_dates,
           d.status,
           SUM(d.amount) OVER (PARTITION BY d.employee_id) AS total_deduction
    FROM deduction d 
    INNER JOIN employees e ON d.employee_id = e.id
    WHERE e.status = 'active'  -- Only active employees will appear
    GROUP BY d.employee_id, e.first_name, e.last_name, e.monthly_salary, d.status
";

// Query to fetch employees who don't have deductions yet
$employeeQuery = "
    SELECT id, CONCAT(first_name, ' ', last_name) AS name
    FROM employees
    WHERE id NOT IN (SELECT DISTINCT employee_id FROM deduction)
";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deduction List</title>
    <link rel="stylesheet" href="./assets/css/deduction2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Additional styles */
        .content-wrapper {
            min-height: calc(100vh - var(--header-height) - 40px);
        }
        
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .deduction-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        
        .add-deduction-btn {
            background-color: var(--sidebar-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--box-shadow);
            white-space: nowrap;
            margin: 20px 0;
            width: 100%;
            justify-content: center;
        }
        
        .add-deduction-btn:hover {
            background-color: var(--sidebar-dark-green);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
        }
        
        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 15px 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .search-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .net-pay-highlight {
            font-weight: 700;
            color: var(--success-color);
        }
        
        .employee-info {
            display: grid;
            gap: 4px;
        }
        
        .employee-info strong {
            color: var(--sidebar-dark-green);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include_once("./includes/header.php"); ?>

<main class="content">
    <div class="content-wrapper">
        <!-- Add Deduction Button -->
        <button class="add-deduction-btn" onclick="window.location.href='add_deduction.php'">
            <i class="fas fa-plus-circle"></i> Add/Remove Deduction
        </button>

        <!-- Deduction Form -->
        <div class="deduction-form">
            <h3><i class="fas fa-file-invoice-dollar"></i> DEDUCTION FORM</h3>
            
            <form method="POST" action="deductionList.php">
                <label for="employee-id"><i class="fas fa-user"></i> Employee</label>
                <select id="employee-id" name="employee_id" required>
                    <option value="">Select Employee</option>
                    <?php
                    $employeeResult = $conn->query($employeeQuery);
                    while ($employee = $employeeResult->fetch_assoc()) {
                        echo "<option value='{$employee['id']}'>{$employee['name']}</option>";
                    }
                    ?>
                </select>

                <label for="deduction-names"><i class="fas fa-tags"></i> Deductions</label>
                <div class="custom-select-box">
                    <div class="dropdown-content">
                        <?php
                        $customDeductionsQuery = "SELECT name FROM custom_deductions";
                        $customDeductionsResult = $conn->query($customDeductionsQuery);
                        while ($customDeduction = $customDeductionsResult->fetch_assoc()) {
                            echo "<label><input type='checkbox' class='deduction-checkbox' name='deduction_names[]' value='{$customDeduction['name']}'> {$customDeduction['name']}</label>";
                        }
                        ?>
                    </div>
                </div>

                <div id="deduction-amounts"></div>

                <button type="submit" name="save_deduction" class="btn-save">
                    <i class="fas fa-check-circle"></i> APPLY DEDUCTION
                </button>
            </form>
        </div>

        <!-- Deduction Table -->
        <div class="table-responsive">
            <div class="deduction-table-container">
                <table class="deduction-table">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Salary</th>
                            <th>Deduction Names</th>
                            <th>Amounts</th>
                            <th>Start Dates</th>
                            <th>End Dates</th>
                            <th>Net Pay</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $deductionResults = $conn->query($employeeDeductionsQuery);
                        if ($deductionResults && $deductionResults->num_rows > 0) {
                            while ($row = $deductionResults->fetch_assoc()) {
                                $deduction_names = explode(",", $row['deduction_names']);
                                $amounts = explode(",", $row['amounts']);
                                $start_dates = explode(",", $row['start_dates']);
                                $end_dates = explode(",", $row['end_dates']);
                                $net_pay = $row['monthly_salary'] - $row['total_deduction'];
                                
                                // Format dates
                                $formatted_start_dates = array_map(function($date) {
                                    return date('M d, Y', strtotime($date));
                                }, $start_dates);
                                
                                $formatted_end_dates = array_map(function($date) {
                                    return date('M d, Y', strtotime($date));
                                }, $end_dates);
                                
                                echo "<tr>
                                    <td><strong>{$row['employee_id']}</strong></td>
                                    <td>
                                        <div class='employee-info'>
                                            <div><strong>Name:</strong> {$row['first_name']} {$row['last_name']}</div>
                                        </div>
                                    </td>
                                    <td>₱" . number_format($row['monthly_salary'], 2) . "</td>
                                    <td>" . implode("<br>", $deduction_names) . "</td>
                                    <td>₱" . implode("<br>₱", array_map('number_format', $amounts)) . "</td>
                                    <td>" . implode("<br>", $formatted_start_dates) . "</td>
                                    <td>" . implode("<br>", $formatted_end_dates) . "</td>
                                    <td class='net-pay-highlight'>₱" . number_format($net_pay, 2) . "</td>
                                    <td><span class='status-badge " . ($row['status'] == 'active' ? 'status-active' : 'status-disabled') . "'>" . ucfirst($row['status']) . "</span></td>
                                    <td>
                                        <a href='deductionList.php?toggle_status=1&deduction_id={$row['id']}&status={$row['status']}' class='toggle-status " . ($row['status'] == 'active' ? 'active' : 'disabled') . "'>
                                            <i class='fas " . ($row['status'] == 'active' ? 'fa-toggle-on' : 'fa-toggle-off') . "'></i>
                                            " . ($row['status'] == 'active' ? 'Disable' : 'Enable') . "
                                        </a>
                                    </td>
                                </tr>";
                            }
                        } else {
                            echo "<tr>
                                <td colspan='10'>
                                    <div class='empty-state'>
                                        <i class='fas fa-money-bill-wave'></i>
                                        <p>No deductions found</p>
                                    </div>
                                </td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include_once("./modal/logout-modal.php"); ?>

<script>
    document.querySelectorAll('.deduction-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            let deductionAmountFields = document.getElementById('deduction-amounts');
            if (checkbox.checked) {
                let amountInput = document.createElement('div');
                amountInput.classList.add('deduction-input-group');
                amountInput.innerHTML = ` 
                    <label for="amount-${checkbox.value}">
                        <i class="fas fa-dollar-sign"></i> Amount for ${checkbox.value}
                    </label>
                    <input type="number" id="amount-${checkbox.value}" name="deduction_amounts[]" required placeholder="Enter amount" step="0.01">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <div>
                            <label>Start Date</label>
                            <input type="date" name="deduction_start_date[]" required>
                        </div>
                        <div>
                            <label>End Date</label>
                            <input type="date" name="deduction_end_date[]" required>
                        </div>
                    </div>
                    <hr style="margin: 15px 0; border: none; border-top: 1px solid #e0e0e0;">`;
                deductionAmountFields.appendChild(amountInput);
            } else {
                let inputField = document.querySelector(`#amount-${checkbox.value}`).closest('.deduction-input-group');
                if (inputField) {
                    inputField.remove();
                }
            }
        });
    });

    // Auto-close modal if URL has parameters
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('delete') || urlParams.has('toggle_status')) {
            document.body.classList.add('modal-open');
        }
    });
</script>

<!-- Footer -->
<?php include_once("./includes/footer.php"); ?>
</body>
</html>