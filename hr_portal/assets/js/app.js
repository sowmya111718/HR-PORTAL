// app.js
document.addEventListener('DOMContentLoaded', function() {
    // Initialize date fields - MODIFIED: Removed max date restriction
    const today = new Date().toISOString().split('T')[0];
    const dateFields = document.querySelectorAll('input[type="date"]');
    
    dateFields.forEach(field => {
        // Only set default value for empty fields (excluding leave form dates)
        if (!field.value && !field.closest('form[id="leaveForm"]')) {
            field.value = today;
        }
        
        // Set minimum date but NOT maximum date to allow future dates
        if (!field.hasAttribute('min')) {
            field.min = '2024-01-01';
        }
        // REMOVED: field.max = today - This was preventing future dates
    });
    
    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#f56565';
                } else {
                    field.style.borderColor = '#e2e8f0';
                }
            });
            
            // Additional validation for leave form dates
            if (form.id === 'leaveForm') {
                const fromDate = document.getElementById('from_date');
                const toDate = document.getElementById('to_date');
                
                if (fromDate && toDate && fromDate.value && toDate.value) {
                    const from = new Date(fromDate.value);
                    const to = new Date(toDate.value);
                    
                    if (from > to) {
                        isValid = false;
                        showToast('From date cannot be after To date', 'error');
                        fromDate.style.borderColor = '#f56565';
                        toDate.style.borderColor = '#f56565';
                        e.preventDefault();
                        return false;
                    }
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                showToast('Please fill all required fields', 'error');
            }
        });
    });
    
    // Toast notification system
    window.showToast = function(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type}`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };
        
        toast.innerHTML = `
            <i class="${icons[type]}"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    };
    
    // Add fadeOut animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeOut {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(100%); }
        }
    `;
    document.head.appendChild(style);
    
    // Confirm delete actions
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this?')) {
                e.preventDefault();
            }
        });
    });
    
    // Time calculation for timesheet
    const hourInputs = document.querySelectorAll('.timesheet-hours');
    const minuteInputs = document.querySelectorAll('.timesheet-minutes');
    
    function updateTotalHours() {
        let totalHours = 0;
        let totalMinutes = 0;
        
        hourInputs.forEach((input, index) => {
            const hours = parseInt(input.value) || 0;
            const minutes = parseInt(minuteInputs[index]?.value) || 0;
            
            totalHours += hours;
            totalMinutes += minutes;
        });
        
        totalHours += Math.floor(totalMinutes / 60);
        totalMinutes = totalMinutes % 60;
        
        const totalDisplay = document.getElementById('totalHoursDisplay');
        if (totalDisplay) {
            totalDisplay.textContent = `${totalHours}.${totalMinutes.toString().padStart(2, '0')} hrs`;
        }
    }
    
    hourInputs.forEach(input => {
        input.addEventListener('input', updateTotalHours);
    });
    
    minuteInputs.forEach(input => {
        input.addEventListener('input', updateTotalHours);
    });
    
    // Initialize total hours if on timesheet page
    if (hourInputs.length > 0) {
        updateTotalHours();
    }
    
    // Date range validation for leave form
    const fromDateInput = document.getElementById('from_date');
    const toDateInput = document.getElementById('to_date');
    
    if (fromDateInput && toDateInput) {
        // When from_date changes, ensure to_date is not before it
        fromDateInput.addEventListener('change', function() {
            if (toDateInput.value && new Date(toDateInput.value) < new Date(this.value)) {
                toDateInput.value = this.value;
            }
        });
        
        // When to_date changes, ensure it's not before from_date
        toDateInput.addEventListener('change', function() {
            if (fromDateInput.value && new Date(this.value) < new Date(fromDateInput.value)) {
                this.value = fromDateInput.value;
            }
        });
    }
});