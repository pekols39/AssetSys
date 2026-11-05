(function () {

    'use strict';

    /*
     * Maintenance Records
     */

    const maintenanceForm =
        document.getElementById(
            'maintenanceForm'
        );

    const maintenanceButton =
        document.getElementById(
            'saveMaintenanceButton'
        );

    const maintenanceModal =
        document.getElementById(
            'maintenanceModal'
        );

    const maintenanceModalTitle =
        document.getElementById(
            'maintenanceModalTitle'
        );

    const maintenanceButtonText =
        document.getElementById(
            'saveMaintenanceButtonText'
        );

    const maintenanceIdField =
        document.getElementById(
            'maintenanceId'
        );

    const maintenanceEditButtons =
        document.querySelectorAll(
            '.edit-maintenance-button'
        );

    /*
     * Maintenance endpoint URLs
     */

    const maintenanceCreateUrl =
        maintenanceForm?.getAttribute('action')
        || '';

    const maintenanceUpdateUrl =
        maintenanceForm?.dataset.updateAction
        || '';

    /*
     * Convert MySQL DATETIME into
     * datetime-local format.
     */

    function formatDateTimeLocal(value) {

        if (!value) {
            return '';
        }

        return String(value)
            .replace(' ', 'T')
            .slice(0, 16);
    }

    /*
     * Reset maintenance form into create mode.
     */

    function resetMaintenanceForm() {

        if (!maintenanceForm) {
            return;
        }

        maintenanceForm.reset();

        maintenanceForm.classList.remove(
            'was-validated'
        );

        if (maintenanceIdField) {

            maintenanceIdField.value = '';
        }

        maintenanceForm.action =
            maintenanceCreateUrl;

        if (maintenanceModalTitle) {

            maintenanceModalTitle.textContent =
                'Add Maintenance';
        }

        if (maintenanceButtonText) {

            maintenanceButtonText.textContent =
                'Save Maintenance';
        }

        if (maintenanceButton) {

            maintenanceButton.disabled =
                false;
        }

        updateMaintenanceDateRequirements();
    }

    if (
        maintenanceForm
        && maintenanceButton
    ) {

        /*
         * Populate form when editing
         * an existing maintenance record.
         */

        maintenanceEditButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        let maintenance;

                        try {

                            maintenance =
                                JSON.parse(
                                    button.dataset.maintenance
                                    || '{}'
                                );

                        } catch (error) {

                            Swal.fire({

                                icon: 'error',

                                title: 'Unable to Load',

                                text: 'Unable to load the maintenance record.',

                                confirmButtonText: 'OK',

                                confirmButtonColor: '#007472'
                            });

                            return;
                        }

                        maintenanceIdField.value =
                            maintenance.id || '';

                        document.getElementById(
                            'maintenanceType'
                        ).value =
                            maintenance.maintenance_type
                            || '';

                        document.getElementById(
                            'maintenanceTitle'
                        ).value =
                            maintenance.title
                            || '';

                        document.getElementById(
                            'maintenanceStatus'
                        ).value =
                            maintenance.maintenance_status
                            || 'scheduled';

                        document.getElementById(
                            'maintenanceProvider'
                        ).value =
                            maintenance.service_provider_id
                            || '';

                        document.getElementById(
                            'scheduledDate'
                        ).value =
                            maintenance.scheduled_date
                            || '';

                        document.getElementById(
                            'startedAt'
                        ).value =
                            formatDateTimeLocal(
                                maintenance.started_at
                            );

                        document.getElementById(
                            'completedAt'
                        ).value =
                            formatDateTimeLocal(
                                maintenance.completed_at
                            );

                        document.getElementById(
                            'maintenanceCost'
                        ).value =
                            maintenance.cost
                            ?? '0.00';

                        document.getElementById(
                            'referenceNumber'
                        ).value =
                            maintenance.reference_number
                            || '';

                        document.getElementById(
                            'maintenanceDescription'
                        ).value =
                            maintenance.description
                            || '';

                        document.getElementById(
                            'maintenanceFindings'
                        ).value =
                            maintenance.findings
                            || '';

                        document.getElementById(
                            'actionsTaken'
                        ).value =
                            maintenance.actions_taken
                            || '';

                        document.getElementById(
                            'recommendations'
                        ).value =
                            maintenance.recommendations
                            || '';

                        document.getElementById(
                            'nextMaintenanceDate'
                        ).value =
                            maintenance.next_maintenance_date
                            || '';

                        maintenanceModalTitle.textContent =
                            'Edit Maintenance';

                        maintenanceButtonText.textContent =
                            'Update Maintenance';

                        maintenanceForm.action =
                            maintenanceUpdateUrl;

                        updateMaintenanceDateRequirements();
                    }
                );
            }
        );

        /*
         * Add Maintenance button.
         *
         * Reset the form into create mode.
         */

        const addMaintenanceButton =
            document.querySelector(
                '[data-bs-target="#maintenanceModal"]:not(.edit-maintenance-button)'
            );

        if (addMaintenanceButton) {

            addMaintenanceButton.addEventListener(
                'click',
                function () {

                    resetMaintenanceForm();
                }
            );
        }

        /*
         * Maintenance status fields.
         */

        const statusField =
            document.getElementById(
                'maintenanceStatus'
            );

        const startedAtField =
            document.getElementById(
                'startedAt'
            );

        const completedAtField =
            document.getElementById(
                'completedAt'
            );

        function updateMaintenanceDateRequirements() {

            if (!statusField) {
                return;
            }

            const status =
                statusField.value;

            if (
                status === 'in_progress'
                && startedAtField
                && !startedAtField.value
            ) {

                const now =
                    new Date();

                const localDateTime =
                    new Date(
                        now.getTime()
                        - now.getTimezoneOffset() * 60000
                    )
                        .toISOString()
                        .slice(0, 16);

                startedAtField.value =
                    localDateTime;
            }

            if (
                status !== 'completed'
                && completedAtField
            ) {

                completedAtField.value = '';
            }
        }

        if (statusField) {

            statusField.addEventListener(
                'change',
                updateMaintenanceDateRequirements
            );
        }

        /*
         * Submit maintenance form.
         */

        maintenanceForm.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();

                if (!maintenanceForm.checkValidity()) {

                    maintenanceForm.classList.add(
                        'was-validated'
                    );

                    await Swal.fire({

                        icon: 'warning',

                        title: 'Incomplete Information',

                        text: 'Please complete all required fields.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const scheduledDateField =
                    document.getElementById(
                        'scheduledDate'
                    );

                const nextMaintenanceDateField =
                    document.getElementById(
                        'nextMaintenanceDate'
                    );

                const scheduledDate =
                    scheduledDateField?.value
                    || '';

                const startedAt =
                    startedAtField?.value
                    || '';

                const completedAt =
                    completedAtField?.value
                    || '';

                const nextMaintenanceDate =
                    nextMaintenanceDateField?.value
                    || '';

                /*
                 * Validate maintenance dates.
                 */

                if (
                    completedAt
                    && startedAt
                    && new Date(completedAt)
                    < new Date(startedAt)
                ) {

                    await Swal.fire({

                        icon: 'warning',

                        title: 'Invalid Dates',

                        text: 'Completed date cannot be earlier than the started date.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                if (
                    nextMaintenanceDate
                    && scheduledDate
                    && nextMaintenanceDate
                    < scheduledDate
                ) {

                    await Swal.fire({

                        icon: 'warning',

                        title: 'Invalid Dates',

                        text: 'Next maintenance date cannot be earlier than the scheduled date.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                const status =
                    statusField?.value
                    || 'scheduled';

                if (
                    status === 'completed'
                    && !completedAt
                ) {

                    await Swal.fire({

                        icon: 'warning',

                        title: 'Incomplete Information',

                        text: 'Completed maintenance requires a completed date.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    return;
                }

                /*
                 * Convert datetime-local values
                 * to MySQL DATETIME values.
                 */

                const formData =
                    new FormData(
                        maintenanceForm
                    );

                if (startedAt) {

                    formData.set(
                        'started_at',
                        startedAt.replace('T', ' ')
                        + ':00'
                    );
                }

                if (completedAt) {

                    formData.set(
                        'completed_at',
                        completedAt.replace('T', ' ')
                        + ':00'
                    );
                }

                const isEditing =
                    Boolean(
                        maintenanceIdField?.value
                    );

                maintenanceButton.disabled =
                    true;

                const originalText =
                    maintenanceButton.innerHTML;

                maintenanceButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>'
                    + (
                        isEditing
                            ? 'Updating...'
                            : 'Saving...'
                    );

                try {

                    const response =
                        await fetch(
                            maintenanceForm.action,
                            {
                                method: 'POST',

                                body: formData,

                                headers: {
                                    'X-Requested-With':
                                        'XMLHttpRequest',

                                    'Accept':
                                        'application/json'
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
                            || (
                                isEditing
                                    ? 'Unable to update the maintenance record.'
                                    : 'Unable to create the maintenance record.'
                            )
                        );
                    }

                    await Swal.fire({

                        icon: 'success',

                        title: isEditing
                            ? 'Maintenance Updated'
                            : 'Maintenance Added',

                        text:
                            result.message
                            || (
                                isEditing
                                    ? 'Maintenance record updated successfully.'
                                    : 'Maintenance record created successfully.'
                            ),

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    window.location.reload();

                } catch (error) {

                    await Swal.fire({

                        icon: 'error',

                        title: 'Maintenance Failed',

                        text:
                            error.message
                            || 'Unable to save the maintenance record.',

                        confirmButtonText: 'OK',

                        confirmButtonColor: '#007472'
                    });

                    maintenanceButton.disabled =
                        false;

                    maintenanceButton.innerHTML =
                        originalText;
                }
            }
        );

        /*
         * Reset maintenance form whenever
         * the modal is closed.
         */

        if (maintenanceModal) {

            maintenanceModal.addEventListener(
                'hidden.bs.modal',
                function () {

                    resetMaintenanceForm();
                }
            );
        }
    }

})();