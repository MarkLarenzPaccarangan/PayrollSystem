// attendance.js - COMPLETE VERSION WITH TIME-IN/TIME-OUT
console.log('✅ attendance.js loaded');


// This file is now just a fallback - the main JavaScript is in attendance.php
// We keep it for backward compatibility

// Re-export all functions to ensure they're globally available
if (typeof window.openAddAttendanceModal === 'undefined') {
    console.warn('Attendance functions not found in main page, using fallback');
}

// Export functions that might be called from other files
window.checkExistingAttendance = window.checkExistingAttendance || function() {
    console.log('checkExistingAttendance called (fallback)');
};

window.formatTimeForDisplay = window.formatTimeForDisplay || function(timeString) {
    if (!timeString || timeString === '00:00:00' || timeString === null) return '--:-- --';
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12.toString().padStart(2, '0')}:${minutes} ${period}`;
};

window.formatDateForDisplay = window.formatDateForDisplay || function(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    
    
};