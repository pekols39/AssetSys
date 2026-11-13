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

    // Shared SweetAlert confirmation for forms and action links.
    document.addEventListener('submit', async function (event) {
        const form = event.target.closest('form[data-swal-confirm="true"]');
        if (!form || form.dataset.swalConfirmed === 'true') {
            return;
        }

        event.preventDefault();

        const result = await Swal.fire({
            icon: 'question',
            title: form.dataset.swalTitle || 'Are you sure?',
            text: form.dataset.swalText || 'Please confirm this action.',
            showCancelButton: true,
            confirmButtonText: form.dataset.swalConfirmText || 'Continue',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#007472',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
        });

        if (result.isConfirmed) {
            form.dataset.swalConfirmed = 'true';
            form.submit();
        }
    });

    document.addEventListener('click', async function (event) {
        const link = event.target.closest('a[data-swal-confirm="true"]');
        if (!link) {
            return;
        }

        event.preventDefault();

        const result = await Swal.fire({
            icon: 'question',
            title: link.dataset.swalTitle || 'Are you sure?',
            text: link.dataset.swalText || 'Please confirm this action.',
            showCancelButton: true,
            confirmButtonText: link.dataset.swalConfirmText || 'Continue',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#007472',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
        });

        if (result.isConfirmed) {
            window.location.href = link.href;
        }
    });

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