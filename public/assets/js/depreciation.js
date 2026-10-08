document.addEventListener('DOMContentLoaded', () => {

    const postButton = document.getElementById(
        'postDepreciationButton'
    );

    if (postButton) {

        postButton.addEventListener('click', async () => {
            const scheduleId = postButton.dataset.scheduleId;

            if (!scheduleId) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to continue',
                    text: 'Depreciation schedule was not found.',
                });

                return;
            }

            const confirmation = await Swal.fire({
                icon: 'warning',
                title: 'Post Depreciation?',
                text: 'This will create the next depreciation entry.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Post',
                confirmButtonColor: '#007472',
                cancelButtonText: 'Cancel',
                cancelButtonColor: '#6c757d'
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            const formData = new FormData();

            formData.append('schedule_id', scheduleId);

            const csrfToken = document.querySelector(
                'input[name="assetsys_csrf"]'
            );

            if (!csrfToken) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to continue',
                    text: 'Security token was not found.',
                });

                return;
            }

            formData.append(
                csrfToken.name,
                csrfToken.value
            );

            postButton.disabled = true;

            const originalHtml = postButton.innerHTML;

            postButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>' +
                'Posting...';

            try {

                const response = await fetch(
                    'depreciation_post_process.php',
                    {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message ||
                        'Unable to post depreciation.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Depreciation Posted',
                    text: result.message,
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {

                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Post Depreciation',
                    text: error.message ||
                        'An unexpected error occurred.',
                });

                postButton.disabled = false;
                postButton.innerHTML = originalHtml;
            }
        });

    }

    const createButton = document.getElementById(
        'createDepreciationButton'
    );

    if (createButton) {

        const modalElement = document.getElementById(
            'createDepreciationModal'
        );

        const modal = new bootstrap.Modal(modalElement);

        const saveButton = document.getElementById(
            'saveDepreciationScheduleButton'
        );

        const saveButtonText = document.getElementById(
            'saveDepreciationScheduleButtonText'
        );

        const startDateInput = document.getElementById(
            'depreciationStartDate'
        );

        createButton.addEventListener('click', () => {

            const defaultStartDate =
                createButton.dataset.startDate || '';

            startDateInput.value = defaultStartDate;

            modal.show();
        });

        saveButton.addEventListener('click', async () => {

            const assetId =
                createButton.dataset.assetId;

            const startDate =
                startDateInput.value;

            const csrfToken =
                document.querySelector(
                    'input[name="assetsys_csrf"]'
                );

            if (!assetId) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to continue',
                    text: 'Asset was not found.',
                });

                return;
            }

            if (!startDate) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Start Date Required',
                    text: 'Please select a depreciation start date.',
                });

                return;
            }

            if (!csrfToken) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to continue',
                    text: 'Security token was not found.',
                });

                return;
            }

            const confirmation = await Swal.fire({
                icon: 'question',
                title: 'Create Depreciation Schedule?',
                text: 'A new depreciation schedule will be created for this asset.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Create',
                confirmButtonColor: '#007472',
                cancelButtonText: 'Cancel',
                cancelButtonColor: '#6c757d'
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            const formData = new FormData();

            formData.append('asset_id', assetId);
            formData.append('start_date', startDate);
            formData.append(
                csrfToken.name,
                csrfToken.value
            );

            saveButton.disabled = true;

            const originalHtml =
                saveButton.innerHTML;

            saveButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>' +
                'Creating...';

            if (saveButtonText) {
                saveButtonText.textContent = 'Creating...';
            }

            try {

                const response = await fetch(
                    'depreciation_create_process.php',
                    {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message ||
                        'Unable to create depreciation schedule.'
                    );
                }

                modal.hide();

                await Swal.fire({
                    icon: 'success',
                    title: 'Schedule Created',
                    text: result.message,
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {

                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Create Schedule',
                    text: error.message ||
                        'An unexpected error occurred.',
                });

                saveButton.disabled = false;
                saveButton.innerHTML = originalHtml;

                if (saveButtonText) {
                    saveButtonText.textContent =
                        'Create Schedule';
                }
            }
        });
    }
});