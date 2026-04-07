<style>
            .confirm-btn {
            background-color: #ffa07a;
            color: white;
        }

        .cancel-btn {
            background-color: #ccc;
            color: black;
        }
        .confirm-btn, .cancel-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
        }
        .logout-footer {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
        }
        .icon {
            margin-right: 10px;
        }
</style>
<div class="logout-overlay" id="logoutOverlay">
        <div class="logout-content">
            <div class="logout-header" style="padding: 20px;">Confirmation</div>
            <div style="border-top: 2px solid black; margin: 10px 0;"></div>
            <p style="padding: 30px;">Are you sure you want to log out?</p>
            <div style="border-top: 2px solid black; margin: 10px 0;"></div>
            <div class="logout-footer">
                <button class="cancel-btn" onclick="closeLogoutConfirmation()">No</button>
                <a href="logout.php?logout=confirm"><button class="confirm-btn">Yes</button></a>
            </div>
        </div>
    </div>

    <script>
        function showLogoutConfirmation(event) {
            event.preventDefault();
            document.getElementById("logoutOverlay").style.display = "flex";
        }

        function closeLogoutConfirmation() {
            document.getElementById("logoutOverlay").style.display = "none";
        }
        function showLogoutModal() {
            document.getElementById('logoutOverlay').style.display = 'flex';
        }
        function closeLogoutConfirmation() {
            document.getElementById('logoutOverlay').style.display = 'none';
        }
    </script>