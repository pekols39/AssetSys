(function () {
    'use strict';

    // Live date and time in Philippine time.
    const dateElement = document.getElementById('appTopbarDate');
    const timeElement = document.getElementById('appTopbarTime');

    if (dateElement && timeElement) {
        const dateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });

        const timeFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });

        function updateTopbarDateTime() {
            const now = new Date();

            dateElement.textContent = dateFormatter.format(now);
            timeElement.textContent = timeFormatter.format(now);
        }

        updateTopbarDateTime();
        window.setInterval(updateTopbarDateTime, 1000);
    }

    // Shared logout confirmation for sidebar and top-bar links.
    const logoutLinks = [
        document.getElementById('logoutLink'),
        document.getElementById('topbarLogoutLink')
    ].filter(Boolean);

    logoutLinks.forEach(function (logoutLink) {
        logoutLink.addEventListener('click', async function (event) {
            event.preventDefault();

            const logoutUrl = logoutLink.href;

            const result = await Swal.fire({
                icon: 'question',
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                showCancelButton: true,
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            });

            if (result.isConfirmed) {
                window.location.href = logoutUrl;
            }
        });
    });
})();