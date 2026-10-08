document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('disposalForm');
    const saveButton = document.getElementById('saveDisposalButton');

    if (form && saveButton) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const csrfInput =
                form.querySelector(
                    'input[type="hidden"][name]'
                );

            const formData = new FormData(form);

            saveButton.disabled = true;

            const originalButtonHtml = saveButton.innerHTML;

            saveButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status"></span>' +
                'Submitting...';

            try {
                const response = await fetch(
                    form.action,
                    {
                        method: 'POST',
                        body: formData,
                        headers: csrfInput
                            ? {
                                'X-CSRF-TOKEN':
                                    csrfInput.value
                            }
                            : {}
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message
                        || 'Unable to submit the disposal request.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Disposal Request Submitted',
                    text:
                        result.message
                        || 'The disposal request has been submitted successfully.',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {

                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Submit Request',
                    text:
                        error.message
                        || 'Unable to submit the disposal request.',
                    confirmButtonColor: '#007472'
                });

            } finally {

                saveButton.disabled = false;
                saveButton.innerHTML = originalButtonHtml;
            }
        });
    }

    document
        .querySelectorAll('.disposal-approve-button')
        .forEach((button) => {

            button.addEventListener('click', async () => {

                const disposalId =
                    button.dataset.disposalId;

                if (!disposalId) {
                    return;
                }

                const confirmation =
                    await Swal.fire({
                        icon: 'question',
                        title: 'Approve Disposal?',
                        text:
                            'This will approve the disposal request.',
                        showCancelButton: true,
                        confirmButtonText: 'Approve',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#007472',
                        cancelButtonColor: '#6c757d'
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                await processDisposalAction(
                    button,
                    'disposal_approve_process.php',
                    disposalId,
                    'approve'
                );
            });
        });

    document
        .querySelectorAll('.disposal-complete-button')
        .forEach((button) => {

            button.addEventListener('click', async () => {

                const disposalId =
                    button.dataset.disposalId;

                if (!disposalId) {
                    return;
                }

                const confirmation =
                    await Swal.fire({
                        icon: 'warning',
                        title: 'Complete Disposal?',
                        text:
                            'This will mark the disposal as completed and the asset as Disposed.',
                        showCancelButton: true,
                        confirmButtonText: 'Complete Disposal',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#007472',
                        cancelButtonColor: '#6c757d'
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                await processDisposalAction(
                    button,
                    'disposal_complete_process.php',
                    disposalId,
                    'complete'
                );
            });
        });

    document
        .querySelectorAll('.disposal-cancel-button')
        .forEach((button) => {

            button.addEventListener('click', async () => {

                const disposalId =
                    button.dataset.disposalId;

                if (!disposalId) {
                    return;
                }

                const confirmation =
                    await Swal.fire({
                        icon: 'warning',
                        title: 'Cancel Disposal Request?',
                        text:
                            'The disposal request will be cancelled and the asset will return to its operational status.',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Cancel Request',
                        cancelButtonText: 'Keep Request',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d'
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                await processDisposalAction(
                    button,
                    'disposal_cancel_process.php',
                    disposalId,
                    'cancel'
                );
            });
        });
});


async function processDisposalAction(
    button,
    endpoint,
    disposalId,
    action
) {
    const originalButtonHtml =
        button.innerHTML;

    const form =
        document.getElementById('disposalForm');

    /*
     * Use the actual configured CSRF field generated
     * by Csrf::field().
     *
     * Do not hard-code "csrf_token".
     */
    const csrfInput = form
        ? form.querySelector(
            'input[name="assetsys_csrf"]'
        )
        : null;

    button.disabled = true;

    button.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status"></span>' +
        'Processing...';

    const formData = new FormData();

    formData.append(
        'disposal_id',
        disposalId
    );

    if (csrfInput) {
        formData.append(
            csrfInput.name,
            csrfInput.value
        );
    }

    try {

        const response = await fetch(
            endpoint,
            {
                method: 'POST',
                body: formData,
                headers: csrfInput
                    ? {
                        'X-CSRF-TOKEN':
                            csrfInput.value
                    }
                    : {}
            }
        );

        const result =
            await response.json();

        if (
            !response.ok
            || !result.success
        ) {
            throw new Error(
                result.message
                || `Unable to ${action} the disposal request.`
            );
        }

        await Swal.fire({
            icon: 'success',
            title: 'Success',
            text:
                result.message
                || `Disposal request ${action}d successfully.`,
            confirmButtonColor: '#007472'
        });

        window.location.reload();

    } catch (error) {

        await Swal.fire({
            icon: 'error',
            title: 'Action Failed',
            text:
                error.message
                || `Unable to ${action} the disposal request.`,
            confirmButtonColor: '#007472'
        });

    } finally {

        button.disabled = false;
        button.innerHTML =
            originalButtonHtml;
    }
}