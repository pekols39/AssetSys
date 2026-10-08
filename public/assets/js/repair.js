document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('repairModal');

    if (!modalElement) {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);

    const form = document.getElementById('repairForm');
    const saveButton = document.getElementById('saveRepairButton');
    const saveButtonText = document.getElementById('saveRepairButtonText');
    const modalTitle = document.getElementById('repairModalTitle');

    const repairIdInput = document.getElementById('repairId');
    const assetIdInput = form
        ? form.querySelector('input[name="asset_id"]')
        : null;

    const createAction = form ? form.getAttribute('action') : null;
    const updateAction = form
        ? form.dataset.updateAction
        : null;

    const addButton = document.getElementById('addRepairButton');

    if (!form || !saveButton || !repairIdInput || !assetIdInput) {
        return;
    }

    function setValue(id, value) {
        const element = document.getElementById(id);

        if (!element) {
            return;
        }

        element.value = value ?? '';
    }

    function formatDateTimeLocal(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return String(value).replace(' ', 'T').slice(0, 16);
        }

        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');

        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    function resetForm() {
        form.reset();

        repairIdInput.value = '';
        assetIdInput.value = assetIdInput.dataset.assetId || '';

        if (modalTitle) {
            modalTitle.textContent = 'Add Repair';
        }

        if (saveButtonText) {
            saveButtonText.textContent = 'Save Repair';
        }

        setValue('repairStatus', 'reported');

        const warrantyClaim = document.getElementById('warrantyClaim');

        if (warrantyClaim) {
            warrantyClaim.checked = false;
        }
    }

    function populateRepair(repair) {
        repairIdInput.value = repair.id || '';
        assetIdInput.value = repair.asset_id || '';

        setValue('repairType', repair.repair_type);
        setValue('repairTitle', repair.title);
        setValue('repairStatus', repair.repair_status);
        setValue('repairServiceProvider', repair.service_provider_id);
        setValue('repairReportedAt', formatDateTimeLocal(repair.reported_at));
        setValue('repairStartedAt', formatDateTimeLocal(repair.started_at));
        setValue('repairCompletedAt', formatDateTimeLocal(repair.completed_at));
        setValue('repairCost', repair.cost);
        setValue('repairReferenceNumber', repair.reference_number);
        setValue('problemDescription', repair.problem_description);
        setValue('repairDiagnosis', repair.diagnosis);
        setValue('repairActions', repair.repair_actions);
        setValue('repairPartsReplaced', repair.parts_replaced);
        setValue('repairRecommendations', repair.recommendations);

        const warrantyClaim = document.getElementById('warrantyClaim');

        if (warrantyClaim) {
            warrantyClaim.checked =
                Number(repair.warranty_claim) === 1
                || repair.warranty_claim === true
                || repair.warranty_claim === '1';
        }

        if (modalTitle) {
            modalTitle.textContent = 'Edit Repair';
        }

        if (saveButtonText) {
            saveButtonText.textContent = 'Update Repair';
        }
    }

    function setLoading(isLoading) {
        saveButton.disabled = isLoading;

        if (isLoading) {
            saveButtonText.textContent = 'Saving...';
        } else {
            saveButtonText.textContent =
                repairIdInput.value
                    ? 'Update Repair'
                    : 'Save Repair';
        }
    }

    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Unable to save repair',
            text: message || 'An unexpected error occurred.',
            confirmButtonColor: '#007472'
        });
    }

    function showSuccess(message) {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: message,
            confirmButtonColor: '#007472'
        }).then(function () {
            window.location.reload();
        });
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            resetForm();
            modal.show();
        });
    }

    document.querySelectorAll('.edit-repair-button').forEach(function (button) {
        button.addEventListener('click', function () {
            const rawRepair = button.dataset.repair;

            if (!rawRepair) {
                showError('Repair information is missing.');
                return;
            }

            try {
                const repair = JSON.parse(rawRepair);

                resetForm();
                populateRepair(repair);
                modal.show();
            } catch (error) {
                console.error('Unable to parse repair data:', error);
                showError('Unable to load the repair record.');
            }
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        resetForm();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const isUpdate = Boolean(repairIdInput.value);
        const action = isUpdate
            ? updateAction
            : createAction;

        if (!action) {
            showError('Repair request URL is not configured.');
            return;
        }

        if (!assetIdInput.value) {
            showError('Asset information is missing.');
            return;
        }

        setLoading(true);

        try {
            const formData = new FormData(form);

            const response = await fetch(action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message
                    || 'Unable to save the repair record.'
                );
            }

            modal.hide();

            showSuccess(
                result.message
                || (
                    isUpdate
                        ? 'Repair record updated successfully.'
                        : 'Repair record created successfully.'
                )
            );
        } catch (error) {
            console.error('Repair save error:', error);

            showError(
                error.message
                || 'Unable to connect to the server.'
            );
        } finally {
            setLoading(false);
        }
    });
});