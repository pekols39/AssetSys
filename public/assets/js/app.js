(function () {
    'use strict';

    const logoutLink = document.getElementById('logoutLink');

    if (!logoutLink) {
        return;
    }

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
})();