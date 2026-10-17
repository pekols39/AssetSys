(function () {

    'use strict';

    /*
     * Assign Asset
     */

    const assignmentForm =
        document.getElementById(
            'assetAssignmentForm'
        );

    const assignButton =
        document.getElementById(
            'assignAssetButton'
        );

    if (assignmentForm && assignButton) {

        assignmentForm.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();

                if (!assignmentForm.checkValidity()) {

                    assignmentForm.classList.add(
                        'was-validated'
                    );

                    await Swal.fire({

                        icon: 'warning',

                        title: 'Incomplete Form',

                        text: 'Please enter an employee name before assigning this asset.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const assignedToInput =
                    document.getElementById('assigned_to_name');

                const selectedText =
                    assignedToInput
                        ? assignedToInput.value.trim()
                        : '';

                if (!selectedText) {
                    await Swal.fire({
                        icon: 'warning',
                        title: 'Employee Name Required',
                        text: 'Please enter the employee name before assigning this asset.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const confirmation =
                    await Swal.fire({

                        icon: 'question',

                        title: 'Assign Asset?',

                        html:
                            'This asset will be assigned to:<br><br>' +
                            '<strong>' +
                            selectedText +
                            '</strong>',

                        showCancelButton: true,

                        confirmButtonText: 'Yes, Assign',

                        cancelButtonText: 'Cancel',

                        confirmButtonColor: '#007472',

                        cancelButtonColor: '#6c757d',

                        reverseButtons: true
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                assignButton.disabled = true;

                const originalText =
                    assignButton.innerHTML;

                assignButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>' +
                    'Assigning...';

                try {

                    const response = await fetch(
                        assignmentForm.action,
                        {
                            method: 'POST',

                            body: new FormData(
                                assignmentForm
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
                            || 'Unable to assign the asset.'
                        );
                    }

                    await Swal.fire({

                        icon: 'success',

                        title: 'Asset Assigned',

                        text:
                            result.message
                            || 'Asset assigned successfully.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    window.location.href =
                        result.redirect
                        || assignmentForm.dataset.viewUrl;

                } catch (error) {

                    await Swal.fire({

                        icon: 'error',

                        title: 'Assignment Failed',

                        text:
                            error.message
                            || 'Unable to assign the asset.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    assignButton.disabled =
                        false;

                    assignButton.innerHTML =
                        originalText;
                }
            }
        );
    }

    /*
     * Return Asset
     */

    const returnButton =
        document.getElementById(
            'returnAssetButton'
        );

    if (returnButton) {

        returnButton.addEventListener(
            'click',
            async function () {

                const assignmentId =
                    returnButton.dataset.assignmentId;

                if (!assignmentId) {

                    await Swal.fire({

                        icon: 'error',

                        title: 'Return Failed',

                        text: 'Invalid assignment.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const confirmation =
                    await Swal.fire({

                        icon: 'warning',

                        title: 'Return Asset?',

                        text: 'Are you sure you want to return this asset?',

                        input: 'textarea',

                        inputLabel: 'Remarks',

                        inputPlaceholder:
                            'Optional return remarks',

                        inputAttributes: {
                            maxlength: 1000
                        },

                        showCancelButton: true,

                        confirmButtonText: 'Yes, Return',

                        cancelButtonText: 'Cancel',

                        confirmButtonColor: '#dc3545',

                        cancelButtonColor: '#6c757d',

                        reverseButtons: true
                    });

                if (!confirmation.isConfirmed) {
                    return;
                }

                returnButton.disabled =
                    true;

                const originalText =
                    returnButton.innerHTML;

                returnButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>' +
                    'Returning...';

                try {

                    const formData =
                        new FormData();

                    formData.append(
                        'assignment_id',
                        assignmentId
                    );

                    formData.append(
                        'remarks',
                        confirmation.value || ''
                    );

                    const csrfInput =
                        document.querySelector(
                            'input[name="assetsys_csrf"]'
                        );

                    if (!csrfInput) {

                        throw new Error(
                            'Security token not found. Please refresh the page.'
                        );
                    }

                    formData.append(
                        csrfInput.name,
                        csrfInput.value
                    );

                    const response =
                        await fetch(
                            'return_process.php',
                            {
                                method: 'POST',

                                body: formData,

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
                            || 'Unable to return the asset.'
                        );
                    }

                    await Swal.fire({

                        icon: 'success',

                        title: 'Asset Returned',

                        text:
                            result.message
                            || 'Asset returned successfully.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    window.location.reload();

                } catch (error) {

                    await Swal.fire({

                        icon: 'error',

                        title: 'Return Failed',

                        text:
                            error.message
                            || 'Unable to return the asset.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    returnButton.disabled =
                        false;

                    returnButton.innerHTML =
                        originalText;
                }
            }
        );
    }

})();