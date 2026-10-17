(function () {

    'use strict';

    /*
     * Transfer Asset
     */

    const transferForm = document.getElementById(
        'assetTransferForm'
    );

    const transferButton = document.getElementById(
        'transferAssetButton'
    );

    if (transferForm && transferButton) {

        transferForm.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();

                if (!transferForm.checkValidity()) {

                    transferForm.classList.add(
                        'was-validated'
                    );

                    await Swal.fire({
                        icon: 'warning',
                        title: 'Incomplete Form',
                        text: 'Please select a destination location before transferring this asset.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const locationInput =
                    document.getElementById(
                        'destination_location_name'
                    );

                const userInput =
                    document.getElementById(
                        'assigned_to_name'
                    );

                const locationText = locationInput
                    ? locationInput.value.trim()
                    : '';

                const userText = userInput
                    && userInput.value.trim() !== ''
                    ? userInput.value.trim()
                    : 'Unassigned / Shared Asset';

                const confirmation =
                    await Swal.fire({

                        icon: 'question',

                        title: 'Transfer Asset?',

                        html:
                            'Destination location:<br>' +
                            '<strong>' +
                            locationText +
                            '</strong><br><br>' +
                            'Assigned to:<br>' +
                            '<strong>' +
                            userText +
                            '</strong>',

                        showCancelButton: true,

                        confirmButtonText: 'Yes, Transfer',

                        cancelButtonText: 'Cancel',

                        confirmButtonColor: '#007472',

                        cancelButtonColor: '#6c757d',

                        reverseButtons: true
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                transferButton.disabled = true;

                const originalText =
                    transferButton.innerHTML;

                transferButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>' +
                    'Transferring...';

                try {

                    const response = await fetch(
                        transferForm.action,
                        {
                            method: 'POST',

                            body: new FormData(
                                transferForm
                            ),

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                    let result;

                    try {

                        result =
                            await response.json();

                    } catch (jsonError) {

                        throw new Error(
                            'The server returned an unexpected response.'
                        );
                    }

                    if (
                        !response.ok
                        || !result.success
                    ) {

                        throw new Error(
                            result.message
                            || 'Unable to transfer the asset.'
                        );
                    }

                    await Swal.fire({

                        icon: 'success',

                        title: 'Asset Transferred',

                        text:
                            result.message
                            || 'Asset transferred successfully.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    window.location.href =
                        transferForm.dataset.viewUrl;

                } catch (error) {

                    await Swal.fire({

                        icon: 'error',

                        title: 'Transfer Failed',

                        text:
                            error.message
                            || 'Unable to transfer the asset.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    transferButton.disabled = false;

                    transferButton.innerHTML =
                        originalText;
                }
            }
        );
    }

})();