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
                title: 'Transfer Asset to Another Department?',
                text:
                    'Transfer this asset immediately to ' +
                    destinationName +
                    '? Its department will change after successful submission, while its location and employee assignment remain unchanged.',
                showCancelButton: true,
                confirmButtonText: 'Transfer Asset',
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
                'Transfer Completed',
                requestForm.dataset.successUrl
            );
        });
    }
})();