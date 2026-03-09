document.addEventListener('DOMContentLoaded', function() {
    // Get data from global window object
    const data = window.dashboardData || {
        categories: [],
        categoryCounts: [],
        dates: [],
        values: []
    };

    // Initialize flatpickr for date range
    const startDatePicker = flatpickr("#startDate", {
        dateFormat: "Y-m-d",
        maxDate: "today",
        defaultDate: document.getElementById('startDate').value
    });

    const endDatePicker = flatpickr("#endDate", {
        dateFormat: "Y-m-d",
        maxDate: "today",
        defaultDate: document.getElementById('endDate').value
    });

    // Category Distribution Chart
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    
    new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: data.categories,
            datasets: [{
                data: data.categoryCounts,
                backgroundColor: [
                    '#75e6da',
                    '#6c5ce7',
                    '#e84393',
                    '#f39c12',
                    '#00b894'
                ],
                borderWidth: 0,
                borderRadius: 10,
                spacing: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#a0a3c0',
                        font: {
                            size: 11
                        },
                        padding: 15,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'var(--bg-secondary)',
                    titleColor: 'var(--text-primary)',
                    bodyColor: 'var(--text-secondary)',
                    borderColor: '#75e6da',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${value} products (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

    // Stock Value by Date Chart
    const stockCtx = document.getElementById('stockChart').getContext('2d');
    
    let stockChart = new Chart(stockCtx, {
        type: 'line',
        data: {
            labels: data.dates,
            datasets: [
                {
                    label: 'Inventory Value',
                    data: data.values,
                    borderColor: '#75e6da',
                    backgroundColor: 'rgba(117, 230, 218, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#75e6da',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'var(--bg-secondary)',
                    titleColor: 'var(--text-primary)',
                    bodyColor: 'var(--text-secondary)',
                    borderColor: '#75e6da',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            return '₱' + context.raw.toLocaleString(undefined, {
                                minimumFractionDigits: 2, 
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)'
                    },
                    ticks: {
                        color: '#a0a3c0',
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    },
                    title: {
                        display: true,
                        text: 'Value (₱)',
                        color: '#a0a3c0'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#a0a3c0',
                        font: {
                            size: 11
                        },
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }
    });

    // Handle Apply button click
    const applyBtn = document.getElementById('applyDateRange');
    const chartLoading = document.getElementById('chartLoading');
    
    applyBtn.addEventListener('click', function() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        if (!startDate || !endDate) {
            showNotification('Please select both start and end dates', 'warning');
            return;
        }

        if (new Date(startDate) > new Date(endDate)) {
            showNotification('Start date cannot be after end date', 'error');
            return;
        }

        // Show loading
        chartLoading.style.display = 'flex';
        applyBtn.disabled = true;

        // Make AJAX request
        fetch('get_chart_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                start_date: startDate,
                end_date: endDate
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            // Update chart with new data
            stockChart.data.labels = data.dates;
            stockChart.data.datasets[0].data = data.values;
            stockChart.update();
            
            // Show success notification
            showNotification('Chart updated successfully', 'success');
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error loading chart data', 'error');
        })
        .finally(() => {
            // Hide loading
            chartLoading.style.display = 'none';
            applyBtn.disabled = false;
        });
    });

    // Notification function
    function showNotification(message, type = 'info') {
        // You can implement a toast notification here
        // For now, we'll use console.log
        console.log(`[${type.toUpperCase()}] ${message}`);
        
        // Optional: Create a simple toast if you have one
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        }
    }

    // Add keyboard support for date inputs
    const dateInputs = document.querySelectorAll('.date-input');
    dateInputs.forEach(input => {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyBtn.click();
            }
        });
    });
});