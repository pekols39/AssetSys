(function () {
    'use strict';

    async function sendForm(form, successTitle, successUrl) {
        const submitButtons = form.querySelectorAll(
            'button[type="submit"]'
        );

        submitButtons.forEach(function (button) {
            button.disabled = true;
        });

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json'
                }
            });

            const result = await response.json().catch(function () {
                return {};
            });

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'The request could not be completed.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: successTitle,
                text: result.message || 'Completed successfully.',
                confirmButtonColor: '#007472'
            });

            if (successUrl) {
                window.location.assign(successUrl);
            } else {
                window.location.reload();
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'Unable to Complete',
                text: error.message || 'Please try again.',
                confirmButtonColor: '#007472'
            });

            submitButtons.forEach(function (button) {
                button.disabled = false;
            });
        }
    }

    const requestForm = document.getElementById(
        'departmentTransferRequestForm'
    );

    if (requestForm) {
        requestForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!requestForm.reportValidity()) {
                return;
            }

            const destinationInput = requestForm.querySelector(
                '[name="destination_department_name"]'
            );

            const destinationName = destinationInput.value.trim();

            if (destinationName === '') {
                destinationInput.focus();
                return;
            }

            const confirmation = await Swal.fire({
                icon: 'question',
                title: 'Submit Department-Transfer Request?',
                text:
                    'Submit a request to transfer this asset to ' +
                    destinationName +
                    '? The asset’s department will remain unchanged until the Asset Manager approves the request.',
                showCancelButton: true,
                confirmButtonText: 'Submit Request',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            await sendForm(
                requestForm,
                'Request Submitted',
                requestForm.dataset.successUrl
            );
        });
    }

    const decisionForm = document.getElementById(
        'departmentTransferDecisionForm'
    );

    if (decisionForm) {
        document.querySelectorAll(
            '.js-department-transfer-decision'
        ).forEach(function (button) {
            button.addEventListener('click', async function () {
                const requestId = button.dataset.requestId;
                const decision = button.dataset.decision;

                if (
                    !requestId ||
                    !['approve', 'reject'].includes(decision)
                ) {
                    return;
                }

                let reviewNotes = '';

                if (decision === 'reject') {
                    const result = await Swal.fire({
                        icon: 'warning',
                        title: 'Reject Transfer Request?',
                        input: 'textarea',
                        inputLabel: 'Reason for rejection',
                        inputPlaceholder:
                            'Explain why this request is being rejected...',
                        inputAttributes: {
                            maxlength: 500,
                            'aria-label': 'Reason for rejection'
                        },
                        inputValidator: function (value) {
                            if (!value || !value.trim()) {
                                return 'A rejection reason is required.';
                            }

                            if (value.trim().length > 500) {
                                return 'The reason must not exceed 500 characters.';
                            }

                            return undefined;
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Reject Request',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        reverseButtons: true
                    });

                    if (!result.isConfirmed) {
                        return;
                    }

                    reviewNotes = result.value.trim();
                } else {
                    const result = await Swal.fire({
                        icon: 'question',
                        title: 'Approve Transfer Request?',
                        text:
                            'The asset’s department will change to the requested destination.',
                        showCancelButton: true,
                        confirmButtonText: 'Approve Request',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#007472',
                        cancelButtonColor: '#6c757d',
                        reverseButtons: true
                    });

                    if (!result.isConfirmed) {
                        return;
                    }
                }

                decisionForm.querySelector(
                    '[name="request_id"]'
                ).value = requestId;

                decisionForm.querySelector(
                    '[name="decision"]'
                ).value = decision;

                decisionForm.querySelector(
                    '[name="review_notes"]'
                ).value = reviewNotes;

                button.disabled = true;

                try {
                    await sendForm(
                        decisionForm,
                        decision === 'approve'
                            ? 'Request Approved'
                            : 'Request Rejected',
                        null
                    );
                } finally {
                    button.disabled = false;
                }
            });
        });
    }
})();