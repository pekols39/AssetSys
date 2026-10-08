document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('photoUploadForm');
    const modalElement = document.getElementById('photoUploadModal');
    const uploadButton = document.getElementById('uploadPhotoButton');

    if (!form || !modalElement || !uploadButton) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    const originalButtonHtml = uploadButton.innerHTML;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (uploadButton.disabled) {
            return;
        }

        uploadButton.disabled = true;

        uploadButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
            + 'Uploading...';

        try {
            const formData = new FormData(form);

            const response = await fetch(
                form.getAttribute('action'),
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message
                    || 'Unable to upload the photo.'
                );
            }

            modal.hide();

            await Swal.fire({
                icon: 'success',
                title: 'Photo Uploaded',
                text: result.message || 'Photo uploaded successfully.',
                confirmButtonColor: '#007472'
            });

            window.location.reload();
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: error.message
                    || 'Unable to upload the photo.',
                confirmButtonColor: '#007472'
            });
        } finally {
            uploadButton.disabled = false;
            uploadButton.innerHTML = originalButtonHtml;
        }
    });

    modalElement.addEventListener(
        'hidden.bs.modal',
        function () {
            form.reset();

            const assetIdInput =
                form.querySelector('input[name="asset_id"]');

            if (assetIdInput) {
                assetIdInput.value =
                    assetIdInput.defaultValue;
            }
        }
    );

    const primaryButtons =
        document.querySelectorAll('.set-primary-photo-button');

    primaryButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) {
                return;
            }

            const photoId = button.dataset.photoId;

            if (!photoId) {
                return;
            }

            const confirmed = await Swal.fire({
                icon: 'question',
                title: 'Set as Primary Photo?',
                text: 'This photo will become the primary photo for this asset.',
                showCancelButton: true,
                confirmButtonText: 'Set as Primary',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472',
                cancelButtonColor: '#6c757d'
            });

            if (!confirmed.isConfirmed) {
                return;
            }

            button.disabled = true;

            const originalButtonHtml = button.innerHTML;

            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                + 'Updating...';

            try {
                const uploadForm =
                    document.getElementById('photoUploadForm');

                if (!uploadForm) {
                    throw new Error(
                        'Photo upload form was not found.'
                    );
                }

                const csrfInput =
                    uploadForm.querySelector(
                        'input[type="hidden"]:not([name="asset_id"])'
                    );

                if (!csrfInput || !csrfInput.value) {
                    throw new Error(
                        'Security token was not found.'
                    );
                }

                const formData = new FormData();

                formData.append('photo_id', photoId);

                const primaryAction =
                    uploadForm.dataset.primaryAction;

                if (!primaryAction) {
                    throw new Error(
                        'Primary photo endpoint was not configured.'
                    );
                }

                const response = await fetch(
                    primaryAction,
                    {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfInput.value
                        }
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message
                        || 'Unable to update the primary photo.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Primary Photo Updated',
                    text: result.message
                        || 'The primary photo has been updated.',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Update Failed',
                    text: error.message
                        || 'Unable to update the primary photo.',
                    confirmButtonColor: '#007472'
                });

                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        });
    });

    const deleteButtons =
        document.querySelectorAll('.delete-photo-button');

    deleteButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) {
                return;
            }

            const photoId = button.dataset.photoId;

            if (!photoId) {
                return;
            }

            const confirmed = await Swal.fire({
                icon: 'warning',
                title: 'Delete Photo?',
                text: 'This photo will be permanently deleted.',
                showCancelButton: true,
                confirmButtonText: 'Delete Photo',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            });

            if (!confirmed.isConfirmed) {
                return;
            }

            button.disabled = true;

            const originalButtonHtml =
                button.innerHTML;

            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                + 'Deleting...';

            try {
                const uploadForm =
                    document.getElementById('photoUploadForm');

                if (!uploadForm) {
                    throw new Error(
                        'Photo upload form was not found.'
                    );
                }

                const csrfInput =
                    uploadForm.querySelector(
                        'input[type="hidden"]:not([name="asset_id"])'
                    );

                if (!csrfInput || !csrfInput.value) {
                    throw new Error(
                        'Security token was not found.'
                    );
                }

                const formData = new FormData();

                formData.append(
                    'photo_id',
                    photoId
                );

                const deleteAction =
                    uploadForm.dataset.deleteAction;

                if (!deleteAction) {
                    throw new Error(
                        'Photo delete endpoint was not configured.'
                    );
                }

                const response = await fetch(
                    deleteAction,
                    {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfInput.value
                        }
                    }
                );

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message
                        || 'Unable to delete the photo.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Photo Deleted',
                    text: result.message
                        || 'Photo deleted successfully.',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Delete Failed',
                    text: error.message
                        || 'Unable to delete the photo.',
                    confirmButtonColor: '#007472'
                });

                button.disabled = false;
                button.innerHTML =
                    originalButtonHtml;
            }
        });
    });
});