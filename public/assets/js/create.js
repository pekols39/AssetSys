(function () {
    'use strict';

    const form = document.getElementById('assetCreateForm');
    const submitButton = document.getElementById('saveAssetButton');

    if (!form) {
        return;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!form.checkValidity()) {
            form.classList.add('was-validated');

            await Swal.fire({
                icon: 'warning',
                title: 'Incomplete Form',
                text: 'Please complete all required fields before saving.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            return;
        }

        submitButton.disabled = true;

        const originalText = submitButton.innerHTML;

        submitButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1"></span>' +
            'Saving...';

        try {
            const response = await fetch(
                form.action,
                {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            let result;

            try {
                result = await response.json();
            } catch (jsonError) {
                throw new Error(
                    'The server returned an unexpected response.'
                );
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message
                    || 'Unable to create the asset.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: 'Asset Created',
                text: result.message
                    || 'Asset created successfully.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            if (result.asset_id) {
                window.location.href =
                    'view.php?id='
                    + encodeURIComponent(result.asset_id);

                return;
            }

            const config = document.getElementById(
                'assetCreateConfig'
            );

            const listUrl = config
                ? config.dataset.listUrl
                : null;

            window.location.href =
                result.redirect
                || listUrl
                || window.location.href;

        } catch (error) {

            await Swal.fire({
                icon: 'error',
                title: 'Creation Failed',
                text: error.message
                    || 'Unable to create the asset.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
        }
    });
})();