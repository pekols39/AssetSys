(function () {
    'use strict';

    const form = document.getElementById('assetEditForm');
    const saveButton = document.getElementById('saveAssetButton');

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

        saveButton.disabled = true;

        const originalText = saveButton.innerHTML;

        saveButton.innerHTML =
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
                    || 'Unable to update the asset.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: 'Asset Updated',
                text: result.message
                    || 'Asset updated successfully.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            window.location.href =
                result.redirect
                || form.dataset.viewUrl;

        } catch (error) {

            await Swal.fire({
                icon: 'error',
                title: 'Update Failed',
                text: error.message
                    || 'Unable to update the asset.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            saveButton.disabled = false;
            saveButton.innerHTML = originalText;
        }
    });
})();