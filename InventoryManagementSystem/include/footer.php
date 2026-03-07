        </main>
    </div>

    <!-- Add Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Product</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form id="productForm" method="POST" action="">
                <input type="hidden" name="product_action" id="formAction" value="add">
                <input type="hidden" name="id" id="productId">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="name" id="productName" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" id="productCategory" required>
                        <option value="Accessories">Accessories</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Furniture">Furniture</option>
                        <option value="Office Supplies">Office Supplies</option>
                        <option value="Clothing">Clothing</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Product Description</label>
                    <textarea name="description" id="productDescription" rows="3"></textarea>
                </div>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Price (₱)</label>
                        <input type="number" name="price" id="productPrice" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Quantity Stock</label>
                        <select name="quantity" id="productQuantity" required>
                            <option value="">Select quantity</option>
                            <?php for($i = 1; $i <= 20; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?> units</option>
                            <?php endfor; ?>
                            <option value="25">25 units</option>
                            <option value="30">30 units</option>
                            <option value="40">40 units</option>
                            <option value="50">50 units</option>
                            <option value="75">75 units</option>
                            <option value="100">100 units</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Product Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Product Details</h2>
                <button class="close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="productDetails" class="product-details-view" style="padding: 20px;"></div>
        </div>
    </div>

    <!-- New Order Modal -->
    <div id="newOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Order</h2>
                <button class="close-btn" onclick="closeNewOrderModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="new_order" value="1">
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" required>
                </div>
                <div class="form-group">
                    <label>Customer Email</label>
                    <input type="email" name="customer_email" required>
                </div>
                <div class="form-group">
                    <label>Total Amount (₱)</label>
                    <input type="number" name="total_amount" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" required>
                        <option value="cash">Cash</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="paypal">PayPal</option>
                        <option value="bank_transfer">Bank Transfer</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Order</button>
                    <button type="button" class="btn btn-secondary" onclick="closeNewOrderModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Order Modal -->
    <div id="viewOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Order Details</h2>
                <button class="close-btn" onclick="closeViewOrderModal()">&times;</button>
            </div>
            <div id="orderDetails" class="product-details-view" style="padding: 20px;"></div>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="editOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Order Status</h2>
                <button class="close-btn" onclick="closeEditOrderModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="edit_order" value="1">
                <input type="hidden" name="id" id="editOrderId">
                <div class="form-group">
                    <label>Order Status</label>
                    <select name="status" id="editOrderStatus" required>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Status</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditOrderModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Customer Modal -->
    <div id="viewCustomerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Customer Details</h2>
                <button class="close-btn" onclick="closeViewCustomerModal()">&times;</button>
            </div>
            <div id="customerDetails" class="product-details-view" style="padding: 20px;"></div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div style="padding: 30px; text-align: center;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #e84393; margin-bottom: 20px;"></i>
                <p id="deleteMessage" style="margin-bottom: 20px; color: var(--text-primary);">Are you sure you want to delete this item?</p>
                <p id="deleteItemName" style="margin-bottom: 30px; font-weight: 600; color: var(--text-primary);"></p>
                <div style="display: flex; gap: 15px;">
                    <button class="btn btn-danger" onclick="confirmDelete()" style="flex: 1;">Delete</button>
                    <button class="btn btn-secondary" onclick="closeDeleteModal()" style="flex: 1;">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2>Confirm Logout</h2>
                <button class="close-btn" onclick="closeLogoutModal()">&times;</button>
            </div>
            <div style="padding: 30px; text-align: center;">
                <i class="fas fa-sign-out-alt" style="font-size: 48px; color: #e84393; margin-bottom: 20px;"></i>
                <p style="margin-bottom: 20px; color: var(--text-primary);">Are you sure you want to logout?</p>
                <div style="display: flex; gap: 15px;">
                    <button class="btn btn-danger" onclick="confirmLogout()" style="flex: 1;">Logout</button>
                    <button class="btn btn-secondary" onclick="closeLogoutModal()" style="flex: 1;">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMessage">Action completed successfully!</span>
    </div>

    <script>
        // Global variables
        let currentPage = 'dashboard';
        let deleteId = null;
        let deleteType = null;

        // Page Navigation
        function switchPage(pageName) {
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`.nav-item[data-page="${pageName}"]`).classList.add('active');
            
            document.querySelectorAll('.page').forEach(page => {
                page.classList.remove('active-page');
            });
            document.getElementById(`${pageName}-page`).classList.add('active-page');
            currentPage = pageName;
            
            // Update URL without reloading
            history.pushState({}, '', `?page=${pageName}`);
        }

        // Check initial page from URL
        function getInitialPage() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page');
            if (page) {
                switchPage(page);
            }
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const icon = toast.querySelector('i');
            
            toastMessage.textContent = message;
            
            if (type === 'success') {
                icon.style.color = '#75e6da';
                icon.className = 'fas fa-check-circle';
            } else if (type === 'error') {
                icon.style.color = '#d63031';
                icon.className = 'fas fa-exclamation-circle';
            } else if (type === 'warning') {
                icon.style.color = '#f39c12';
                icon.className = 'fas fa-exclamation-triangle';
            }
            
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Product Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Product';
            document.getElementById('formAction').value = 'add';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('productModal').style.display = 'block';
        }

        function openEditModal(id, name, description, price, quantity, category) {
            document.getElementById('modalTitle').textContent = 'Edit Product';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('productId').value = id;
            document.getElementById('productName').value = name;
            document.getElementById('productDescription').value = description;
            document.getElementById('productPrice').value = price;
            document.getElementById('productQuantity').value = quantity;
            document.getElementById('productCategory').value = category;
            document.getElementById('productModal').style.display = 'block';
        }

        function openViewModal(product) {
            const maxStock = 100;
            const percentage = Math.min(100, Math.round((product.quantity / maxStock) * 100));
            
            let status, statusClass;
            if (percentage >= 70) {
                status = 'In Stock (' + percentage + '%)';
                statusClass = 'status-instock';
            } else if (percentage >= 30) {
                status = 'Medium Stock (' + percentage + '%)';
                statusClass = 'status-low';
            } else if (percentage > 0) {
                status = 'Low Stock (' + percentage + '%)';
                statusClass = 'status-low';
            } else {
                status = 'Out of Stock (0%)';
                statusClass = 'status-out';
            }
            
            const detailsHtml = `
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div style="display: flex; align-items: center; gap: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                        <div class="product-icon" style="width: 60px; height: 60px; font-size: 24px;">
                            <i class="fas fa-box"></i>
                        </div>
                        <div>
                            <h3 style="color: var(--text-primary); margin-bottom: 5px;">${product.name}</h3>
                            <span class="category-badge">${product.category || 'Accessories'}</span>
                        </div>
                    </div>
                    <p style="color: var(--text-primary);"><strong>Description:</strong> ${product.description || 'No description'}</p>
                    <p style="color: var(--text-primary);"><strong>Price:</strong> <span style="color: #75e6da; font-size: 18px;">₱${parseFloat(product.price).toFixed(2)}</span></p>
                    <p style="color: var(--text-primary);"><strong>Quantity Stock:</strong> ${product.quantity} units</p>
                    <p style="color: var(--text-primary);"><strong>Status:</strong> <span class="status-badge ${statusClass}">${status}</span></p>
                </div>
            `;
            
            document.getElementById('productDetails').innerHTML = detailsHtml;
            document.getElementById('viewModal').style.display = 'block';
        }

        function closeModal() {
            const modal = document.getElementById('productModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        function closeViewModal() {
            const modal = document.getElementById('viewModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        function openCustomerViewModal(customer) {
            const detailsHtml = `
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div style="display: flex; align-items: center; gap: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                        <div class="product-icon" style="width: 60px; height: 60px; font-size: 24px; background: ${customer.customer_type === 'vip' ? '#f39c12' : (customer.customer_type === 'wholesale' ? '#00b894' : 'var(--bg-secondary)')};">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <h3 style="color: var(--text-primary); margin-bottom: 5px;">${customer.first_name} ${customer.last_name}</h3>
                            <span class="category-badge" style="background: ${customer.customer_type === 'vip' ? '#f39c12' : (customer.customer_type === 'wholesale' ? '#00b894' : 'var(--bg-secondary)')}; color: white;">${customer.customer_type}</span>
                        </div>
                    </div>
                    <p style="color: var(--text-primary);"><strong>Email:</strong> ${customer.email}</p>
                    <p style="color: var(--text-primary);"><strong>Phone:</strong> ${customer.phone || 'N/A'}</p>
                    <p style="color: var(--text-primary);"><strong>Address:</strong> ${customer.address || 'N/A'}, ${customer.city || 'N/A'}</p>
                    <p style="color: var(--text-primary);"><strong>Status:</strong> <span class="status-badge ${customer.status === 'active' ? 'status-instock' : 'status-out'}">${customer.status}</span></p>
                    <p style="color: var(--text-primary);"><strong>Joined:</strong> ${new Date(customer.created_at).toLocaleDateString()}</p>
                </div>
            `;
            document.getElementById('customerDetails').innerHTML = detailsHtml;
            document.getElementById('viewCustomerModal').style.display = 'block';
        }

        function closeViewCustomerModal() {
            const modal = document.getElementById('viewCustomerModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        // New Order Modal functions
        function openNewOrderModal() {
            document.getElementById('newOrderModal').style.display = 'block';
        }

        function closeNewOrderModal() {
            const modal = document.getElementById('newOrderModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        // Order Modal functions
        function openViewOrderModal(order) {
            const statusClass = order.status === 'completed' ? 'status-instock' : (order.status === 'pending' ? 'status-out' : 'status-low');
            
            const detailsHtml = `
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div style="display: flex; align-items: center; gap: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                        <div class="product-icon" style="width: 60px; height: 60px; font-size: 24px;">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div>
                            <h3 style="color: var(--text-primary); margin-bottom: 5px;">Order #${order.order_number}</h3>
                            <span class="status-badge ${statusClass}">${order.status}</span>
                        </div>
                    </div>
                    <p style="color: var(--text-primary);"><strong>Customer:</strong> ${order.customer_name}</p>
                    <p style="color: var(--text-primary);"><strong>Email:</strong> ${order.customer_email}</p>
                    <p style="color: var(--text-primary);"><strong>Date:</strong> ${new Date(order.order_date).toLocaleString()}</p>
                    <p style="color: var(--text-primary);"><strong>Total:</strong> <span style="color: #75e6da; font-size: 18px;">₱${parseFloat(order.total_amount).toFixed(2)}</span></p>
                    <p style="color: var(--text-primary);"><strong>Payment Method:</strong> ${order.payment_method || 'N/A'}</p>
                </div>
            `;
            
            document.getElementById('orderDetails').innerHTML = detailsHtml;
            document.getElementById('viewOrderModal').style.display = 'block';
        }

        function openEditOrderModal(order) {
            document.getElementById('editOrderId').value = order.id;
            document.getElementById('editOrderStatus').value = order.status;
            document.getElementById('editOrderModal').style.display = 'block';
        }

        function closeViewOrderModal() {
            const modal = document.getElementById('viewOrderModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        function closeEditOrderModal() {
            const modal = document.getElementById('editOrderModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
        }

        // Delete Modal functions
        function openDeleteProductModal(id, name) {
            deleteId = id;
            deleteType = 'product';
            document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete this product?';
            document.getElementById('deleteItemName').textContent = `"${name}"`;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function openDeleteCustomerModal(id, name) {
            deleteId = id;
            deleteType = 'customer';
            document.getElementById('deleteMessage').textContent = 'Are you sure you want to delete this customer?';
            document.getElementById('deleteItemName').textContent = `"${name}"`;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
            }, 200);
            deleteId = null;
            deleteType = null;
        }

        function confirmDelete() {
            if (!deleteId || !deleteType) return;
            
            // For demo purposes, show success message
            showToast(`${deleteType} deleted successfully!`);
            const row = document.querySelector(`tr[data-${deleteType}-id="${deleteId}"]`);
            if (row) {
                row.remove();
            }
            closeDeleteModal();
        }

        // Logout Modal functions
        function openLogoutModal() {
            document.getElementById('logoutModal').style.display = 'block';
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

        // Export function
        function exportTableToCSV(type) {
            showToast(`Exporting ${type}...`, 'info');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['productModal', 'viewModal', 'customerModal', 'viewCustomerModal', 'newOrderModal', 'viewOrderModal', 'editOrderModal', 'deleteModal', 'logoutModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.animation = 'fadeOut 0.3s ease';
                    setTimeout(() => {
                        modal.style.display = 'none';
                        modal.style.animation = '';
                    }, 200);
                }
            });
        };
    </script>

    <?php if (isset($_SESSION['toast'])): ?>
    <script>
        showToast(<?php echo json_encode($_SESSION['toast']['message']); ?>, <?php echo json_encode($_SESSION['toast']['type']); ?>);
    </script>
    <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>
</body>
</html>
<?php
// End output buffering
if (ob_get_level() > 0) ob_end_flush();
?>