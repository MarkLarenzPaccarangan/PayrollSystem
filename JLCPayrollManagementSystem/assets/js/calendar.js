// calendar.js - Calendar functionality for attendance page

let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();

// Initialize calendar on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Calendar.js loaded');
    
    // Set current month and year based on selected date
    const selectedDateInput = document.getElementById('selectedDate');
    if (selectedDateInput && selectedDateInput.value) {
        const selectedDate = new Date(selectedDateInput.value);
        if (!isNaN(selectedDate.getTime())) {
            currentMonth = selectedDate.getMonth();
            currentYear = selectedDate.getFullYear();
        }
    }
    
    // Initialize calendar display
    updateCalendar();
});

function toggleCalendar() {
    const calendarWrapper = document.getElementById('calendarWrapper');
    if (calendarWrapper) {
        calendarWrapper.classList.toggle('show');
        updateCalendar();
    }
}

function updateCalendar() {
    const calendarDaysGrid = document.querySelector('.calendar-days-grid');
    const monthYearDisplay = document.querySelector('.calendar-month-year');
    
    if (!calendarDaysGrid || !monthYearDisplay) return;
    
    // Clear the grid
    calendarDaysGrid.innerHTML = '';
    
    // Get first day of the month
    const firstDay = new Date(currentYear, currentMonth, 1);
    const lastDay = new Date(currentYear, currentMonth + 1, 0);
    const daysInMonth = lastDay.getDate();
    const firstDayOfWeek = firstDay.getDay();
    
    // Update month-year display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
    monthYearDisplay.textContent = `${monthNames[currentMonth]} ${currentYear} ▼`;
    
    // Add days from previous month
    const prevMonthLastDay = new Date(currentYear, currentMonth, 0).getDate();
    for (let i = firstDayOfWeek - 1; i >= 0; i--) {
        const day = document.createElement('div');
        day.className = 'calendar-day other-month';
        day.textContent = prevMonthLastDay - i;
        calendarDaysGrid.appendChild(day);
    }
    
    // Add days of current month
    const today = new Date();
    const selectedDateInput = document.getElementById('selectedDate');
    const selectedDate = selectedDateInput ? selectedDateInput.value : null;
    
    for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(currentYear, currentMonth, day);
        const dateString = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        
        const dayElement = document.createElement('a');
        dayElement.href = 'javascript:void(0)';
        dayElement.className = 'calendar-day';
        
        // Check if today
        if (date.getDate() === today.getDate() &&
            date.getMonth() === today.getMonth() &&
            date.getFullYear() === today.getFullYear()) {
            dayElement.classList.add('today');
        }
        
        // Check if selected
        if (dateString === selectedDate) {
            dayElement.classList.add('selected');
        }
        
        // Check if weekend
        const dayOfWeek = date.getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            dayElement.classList.add('weekend');
        }
        
        dayElement.textContent = day;
        dayElement.onclick = function() { 
            if (typeof window.selectDate === 'function') {
                window.selectDate(dateString); 
            } else {
                selectDate(dateString);
            }
        };
        
        calendarDaysGrid.appendChild(dayElement);
    }
    
    // Fill remaining cells
    const totalCells = 42; // 6 rows * 7 columns
    const cellsUsed = firstDayOfWeek + daysInMonth;
    const cellsRemaining = totalCells - cellsUsed;
    
    for (let i = 1; i <= cellsRemaining; i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day other-month';
        day.textContent = i;
        calendarDaysGrid.appendChild(day);
    }
}

function navigateMonth(direction) {
    currentMonth += direction;
    
    // Handle year rollover
    if (currentMonth < 0) {
        currentMonth = 11;
        currentYear--;
    } else if (currentMonth > 11) {
        currentMonth = 0;
        currentYear++;
    }
    
    updateCalendar();
}

function selectDate(date) {
    const dateInput = document.getElementById('dateInput');
    const selectedDateInput = document.getElementById('selectedDate');
    
    if (date) {
        const dateObj = new Date(date);
        const formattedDate = dateObj.toLocaleDateString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric'
        });
        
        if (dateInput) dateInput.value = formattedDate;
        if (selectedDateInput) selectedDateInput.value = date;
    } else {
        if (dateInput) dateInput.value = '';
        if (selectedDateInput) selectedDateInput.value = '';
    }
    
    // Hide calendar
    const calendarWrapper = document.getElementById('calendarWrapper');
    if (calendarWrapper) {
        calendarWrapper.classList.remove('show');
    }
    
    // Submit form if date is selected
    const form = document.getElementById('attendanceForm');
    if (form && date) {
        form.submit();
    }
}

// Make functions globally accessible
window.toggleCalendar = toggleCalendar;
window.navigateMonth = navigateMonth;
window.selectDate = selectDate;
window.updateCalendar = updateCalendar;