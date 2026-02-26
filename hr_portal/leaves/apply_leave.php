<?php
require_once '../config/db.php';
require_once '../includes/leave_functions.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';// Apply for leave
function applyLeave() {
    const leaveData = {
        leave_type: document.getElementById('leave_type').value,
        from_date: document.getElementById('from_date').value,
        to_date: document.getElementById('to_date').value,
        reason: document.getElementById('reason').value,
        day_type: document.querySelector('input[name="day_type"]:checked').value
    };
    
    fetch('apply_leave.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(leaveData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Cancel leave
function cancelLeave(leaveId) {
    if (!confirm('Are you sure you want to cancel this leave application?')) {
        return;
    }
    
    fetch('cancel_leave.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ leave_id: leaveId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Get leave balance
function getLeaveBalance(leaveType = null) {
    let url = 'get_leave_balance.php';
    if (leaveType) {
        url += '?leave_type=' + encodeURIComponent(leaveType);
    }
    
    fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Leave balance:', data.data);
            // Update UI with balance data
            if (data.data.balance.Sick) {
                document.getElementById('sick_balance').textContent = data.data.balance.Sick.remaining;
            }
            if (data.data.balance.Casual) {
                document.getElementById('casual_balance').textContent = data.data.balance.Casual.remaining;
            }
            if (data.data.balance.Other) {
                document.getElementById('other_balance').textContent = data.data.balance.Other.remaining;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}
?>