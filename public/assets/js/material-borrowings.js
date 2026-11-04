document.addEventListener('DOMContentLoaded', () => {
    const apiUrl = (name) => new URL(`../api/${name}`, window.location.href).toString();

    const showMessage = async (icon, title, text) => {
        if (window.Swal) {
            return Swal.fire({ icon, title, text, confirmButtonColor: '#007472' });
        }
        window.alert(text || title);
        return undefined;
    };

    const confirmAction = async (title, text, confirmText) => {
        if (window.Swal) {
            const result = await Swal.fire({
                icon: 'question', title, text, showCancelButton: true,
                confirmButtonText: confirmText, cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472', reverseButtons: true
            });
            return result.isConfirmed;
        }
        return window.confirm(`${title}\n\n${text}`);
    };

    const readJson = async (response) => {
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'The request could not be completed.');
        }
        return data;
    };

    const setBusy = (button, busy, label) => {
        if (!button) return;
        if (busy) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${label}`;
        } else {
            button.disabled = false;
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
        }
    };

    const borrowForm = document.getElementById('materialBorrowForm');
    const materialSelect = document.getElementById('borrowMaterialId');
    const availableStock = document.getElementById('borrowAvailableStock');
    const quantityInput = document.getElementById('borrowQuantity');

    if (materialSelect && availableStock) {
        const updateAvailable = () => {
            const option = materialSelect.selectedOptions[0];
            if (!option || !option.value) {
                availableStock.textContent = 'Select a material to view available stock.';
                quantityInput?.removeAttribute('max');
                return;
            }
            const quantity = Number(option.dataset.available || 0);
            const unit = option.dataset.unit || '';
            availableStock.textContent = `Available: ${quantity.toLocaleString(undefined, { maximumFractionDigits: 3 })} ${unit}`;
            quantityInput?.setAttribute('max', String(quantity));
        };
        materialSelect.addEventListener('change', updateAvailable);
        updateAvailable();
    }

    if (borrowForm) {
        let borrowSubmitting = false;

        borrowForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (borrowSubmitting) return;
            if (!borrowForm.reportValidity()) return;

            borrowSubmitting = true;
            const button = document.getElementById('submitMaterialBorrow');

            try {
                const confirmed = await confirmAction(
                    'Record this borrowing?',
                    'The borrowed quantity will be deducted immediately from available stock.',
                    'Record Borrowing'
                );

                if (!confirmed) return;

                setBusy(button, true, 'Saving...');

                const response = await fetch(apiUrl('material_borrowing_create.php'), {
                    method: 'POST',
                    body: new FormData(borrowForm),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await readJson(response);
                await showMessage('success', 'Borrowing Recorded', data.message);
                window.location.href = new URL('../materials/borrowings.php', window.location.href).toString();
            } catch (error) {
                await showMessage('error', 'Unable to Record Borrowing', error.message);
            } finally {
                setBusy(button, false);
                borrowSubmitting = false;
            }
        });
    }

    const detailsModalElement = document.getElementById('borrowingDetailsModal');
    const detailsModal = detailsModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(detailsModalElement)
        : null;
    const detailsLoading = document.getElementById('borrowingDetailsLoading');
    const detailsContent = document.getElementById('borrowingDetailsContent');

    const addDetailRow = (container, label, value) => {
        const row = document.createElement('div');
        row.className = 'row g-2 py-2 border-bottom';
        const key = document.createElement('div');
        key.className = 'col-sm-4 text-muted small';
        key.textContent = label;
        const val = document.createElement('div');
        val.className = 'col-sm-8';
        val.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
        row.append(key, val);
        container.appendChild(row);
    };

    document.querySelectorAll('.js-borrowing-details').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!detailsModal || !detailsContent || !detailsLoading) return;
            detailsContent.replaceChildren();
            detailsLoading.hidden = false;
            detailsModal.show();
            try {
                const url = new URL(apiUrl('material_borrowing_details.php'));
                url.searchParams.set('id', button.dataset.id || '');
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await readJson(response);
                const item = data.borrowing;
                detailsLoading.hidden = true;

                const summary = document.createElement('div');
                summary.className = 'mb-3';
                const heading = document.createElement('h3');
                heading.className = 'h6 mb-1';
                heading.textContent = `${item.material_name} (${item.material_code})`;
                const borrower = document.createElement('p');
                borrower.className = 'text-muted small mb-0';
                borrower.textContent = `Borrower: ${item.borrower_name}`;
                summary.append(heading, borrower);
                detailsContent.appendChild(summary);

                const fields = document.createElement('div');
                addDetailRow(fields, 'Borrowed quantity', `${item.borrowed_quantity} ${item.material_unit}`);
                addDetailRow(fields, 'Returned quantity', item.returned_quantity);
                addDetailRow(fields, 'Outstanding quantity', item.outstanding_quantity);
                addDetailRow(fields, 'Location', item.location);
                addDetailRow(fields, 'Purpose', item.purpose);
                addDetailRow(fields, 'Borrowed on', item.borrowed_at);
                addDetailRow(fields, 'Expected return', item.expected_return_date);
                addDetailRow(fields, 'Reference', item.reference_number);
                addDetailRow(fields, 'Remarks', item.notes);
                detailsContent.appendChild(fields);

                const returnsTitle = document.createElement('h3');
                returnsTitle.className = 'h6 mt-4 mb-2';
                returnsTitle.textContent = 'Return History';
                detailsContent.appendChild(returnsTitle);
                if (!item.returns || item.returns.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'text-muted small';
                    empty.textContent = 'No returns recorded yet.';
                    detailsContent.appendChild(empty);
                } else {
                    const tableWrap = document.createElement('div');
                    tableWrap.className = 'table-responsive';
                    const table = document.createElement('table');
                    table.className = 'table table-sm align-middle';
                    const head = document.createElement('thead');
                    const headRow = document.createElement('tr');
                    ['Returned On', 'Quantity', 'Condition', 'Details'].forEach((label) => {
                        const th = document.createElement('th');
                        th.textContent = label;
                        headRow.appendChild(th);
                    });
                    head.appendChild(headRow);
                    table.appendChild(head);
                    const body = document.createElement('tbody');
                    item.returns.forEach((entry) => {
                        const row = document.createElement('tr');
                        [entry.returned_at, entry.quantity, entry.item_condition, entry.condition_notes || entry.notes || '—'].forEach((value) => {
                            const td = document.createElement('td');
                            td.textContent = value === null || value === undefined ? '—' : String(value);
                            row.appendChild(td);
                        });
                        body.appendChild(row);
                    });
                    table.appendChild(body);
                    tableWrap.appendChild(table);
                    detailsContent.appendChild(tableWrap);
                }
            } catch (error) {
                detailsLoading.hidden = true;
                detailsContent.textContent = error.message;
            }
        });
    });

    const returnModalElement = document.getElementById('recordBorrowingReturnModal');
    const returnModal = returnModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(returnModalElement)
        : null;
    const returnForm = document.getElementById('materialBorrowReturnForm');
    const returnCondition = document.getElementById('returnCondition');
    const conditionNotesGroup = document.getElementById('returnConditionNotesGroup');
    const conditionNotes = document.getElementById('returnConditionNotes');

    const updateConditionNotes = () => {
        const needsNotes = returnCondition && ['damaged', 'incomplete', 'other'].includes(returnCondition.value);
        if (conditionNotesGroup) conditionNotesGroup.hidden = !needsNotes;
        if (conditionNotes) conditionNotes.required = Boolean(needsNotes);
    };
    returnCondition?.addEventListener('change', updateConditionNotes);
    updateConditionNotes();

    document.querySelectorAll('.js-record-return').forEach((button) => {
        button.addEventListener('click', () => {
            if (!returnModal || !returnForm) return;
            returnForm.reset();
            document.getElementById('returnBorrowingId').value = button.dataset.id || '';
            document.getElementById('returnQuantity').max = button.dataset.outstanding || '';
            document.getElementById('returnQuantityHelp').textContent = `Outstanding quantity: ${button.dataset.outstanding}`;
            document.getElementById('returnBorrowingSummary').textContent = `${button.dataset.material} — ${button.dataset.borrower}`;
            const returnedAt = document.getElementById('returnedAt');
            if (returnedAt) {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                returnedAt.value = now.toISOString().slice(0, 16);
            }
            updateConditionNotes();
            returnModal.show();
        });
    });

    if (returnForm) {
        let returnSubmitting = false;

        returnForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (returnSubmitting) return;
            if (!returnForm.reportValidity()) return;

            returnSubmitting = true;
            const button = document.getElementById('submitBorrowingReturn');

            try {
                const confirmed = await confirmAction(
                    'Record this return?',
                    'The returned quantity will be added back to available stock.',
                    'Record Return'
                );

                if (!confirmed) return;

                setBusy(button, true, 'Saving...');

                const response = await fetch(apiUrl('material_borrowing_return.php'), {
                    method: 'POST',
                    body: new FormData(returnForm),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await readJson(response);
                returnModal?.hide();
                await showMessage('success', 'Return Recorded', data.message);
                window.location.reload();
            } catch (error) {
                await showMessage('error', 'Unable to Record Return', error.message);
            } finally {
                setBusy(button, false);
                returnSubmitting = false;
            }
        });
    }
});
