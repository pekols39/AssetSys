document.addEventListener('DOMContentLoaded', function () {
    const form =
        document.getElementById('documentUploadForm');

    const modalElement =
        document.getElementById('documentUploadModal');

    const uploadButton =
        document.getElementById('uploadDocumentButton');

    if (!form || !modalElement || !uploadButton) {
        return;
    }

    const modal =
        bootstrap.Modal.getOrCreateInstance(
            modalElement
        );

    const originalButtonHtml =
        uploadButton.innerHTML;

    form.addEventListener(
        'submit',
        async function (event) {
            event.preventDefault();

            if (uploadButton.disabled) {
                return;
            }

            uploadButton.disabled = true;

            uploadButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                + 'Uploading...';

            try {
                const formData =
                    new FormData(form);

                const response =
                    await fetch(
                        form.getAttribute('action'),
                        {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
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
                        || 'Unable to upload the document.'
                    );
                }

                modal.hide();

                await Swal.fire({
                    icon: 'success',
                    title: 'Document Uploaded',
                    text:
                        result.message
                        || 'Document uploaded successfully.',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text:
                        error.message
                        || 'Unable to upload the document.',
                    confirmButtonColor: '#007472'
                });

            } finally {
                uploadButton.disabled = false;

                uploadButton.innerHTML =
                    originalButtonHtml;
            }
        }
    );

    modalElement.addEventListener(
        'hidden.bs.modal',
        function () {
            form.reset();

            const assetIdInput =
                form.querySelector(
                    'input[name="asset_id"]'
                );

            if (assetIdInput) {
                assetIdInput.value =
                    assetIdInput.defaultValue;
            }
        }
    );

    const deleteButtons =
        document.querySelectorAll('.delete-document-button');

    deleteButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) {
                return;
            }

            const documentId =
                button.dataset.documentId;

            if (!documentId) {
                return;
            }

            const confirmed = await Swal.fire({
                icon: 'warning',
                title: 'Delete Document?',
                text: 'This document will be permanently deleted.',
                showCancelButton: true,
                confirmButtonText: 'Delete Document',
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
                const form =
                    document.getElementById('documentUploadForm');

                if (!form) {
                    throw new Error(
                        'Document upload form was not found.'
                    );
                }

                const csrfInput =
                    form.querySelector(
                        'input[type="hidden"]:not([name="asset_id"])'
                    );

                if (!csrfInput || !csrfInput.value) {
                    throw new Error(
                        'Security token was not found.'
                    );
                }

                const deleteAction =
                    form.dataset.deleteAction;

                if (!deleteAction) {
                    throw new Error(
                        'Document delete endpoint was not configured.'
                    );
                }

                const formData = new FormData();

                formData.append(
                    'document_id',
                    documentId
                );

                const response =
                    await fetch(
                        deleteAction,
                        {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',
                                'X-CSRF-TOKEN':
                                    csrfInput.value
                            }
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
                        || 'Unable to delete the document.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Document Deleted',
                    text:
                        result.message
                        || 'Document deleted successfully.',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Delete Failed',
                    text:
                        error.message
                        || 'Unable to delete the document.',
                    confirmButtonColor: '#007472'
                });

                button.disabled = false;
                button.innerHTML =
                    originalButtonHtml;
            }
        });
    });
});