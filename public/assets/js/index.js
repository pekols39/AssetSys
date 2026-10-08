(function () {
    'use strict';

    const table = document.getElementById('assetsTable');

    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(function (row) {
        row.addEventListener('click', function (event) {
            const action = event.target.closest(
                'a, button, input, select'
            );

            if (action) {
                return;
            }

            const viewLink = row.querySelector(
                'a[title="View asset"]'
            );

            if (viewLink) {
                window.location.href = viewLink.href;
            }
        });
    });
})();