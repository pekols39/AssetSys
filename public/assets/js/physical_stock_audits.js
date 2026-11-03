document.addEventListener('DOMContentLoaded', () => {
    const auditForm = document.getElementById('physicalStockAuditForm');
    const includeAllCheckbox = document.getElementById('audit-include-all');
    const submitButton = document.getElementById('submitPhysicalStockAudit');

    const detailsModalElement = document.getElementById(
        'physicalStockAuditDetailsModal'
    );

    const detailsModal = detailsModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(detailsModalElement)
        : null;

    const detailsReference = document.getElementById(
        'physicalStockAuditDetailsReference'
    );
    const detailsLoading = document.getElementById(
        'physicalStockAuditDetailsLoading'
    );
    const detailsError = document.getElementById(
        'physicalStockAuditDetailsError'
    );
    const detailsContent = document.getElementById(
        'physicalStockAuditDetailsContent'
    );
    const detailsSummary = document.getElementById(
        'physicalStockAuditDetailsSummary'
    );
    const detailsHead = document.getElementById(
        'physicalStockAuditDetailsHead'
    );
    const detailsBody = document.getElementById(
        'physicalStockAuditDetailsBody'
    );
    const returnHistorySection = document.getElementById(
        'physicalStockAuditReturnHistorySection'
    );
    const returnHistoryBody = document.getElementById(
        'physicalStockAuditReturnHistoryBody'
    );

    const apiUrl = (filename) => new URL(
        `../api/${filename}`,
        window.location.href
    ).toString();

    const showMessage = (icon, title, text) => {
        if (window.Swal) {
            return Swal.fire({ icon, title, text });
        }

        window.alert(text || title);
        return Promise.resolve();
    };

    const confirmAction = async (title, text, confirmText) => {
        if (window.Swal) {
            const result = await Swal.fire({
                icon: 'question',
                title,
                text,
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472',
                reverseButtons: true
            });

            return result.isConfirmed;
        }

        return window.confirm(`${title}\n\n${text}`);
    };

    const readJsonResponse = async (response) => {
        let data;

        try {
            data = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'The request could not be completed.'
            );
        }

        return data;
    };

    const appendCell = (row, value) => {
        const cell = document.createElement('td');
        cell.textContent = value === null || value === undefined || value === ''
            ? '—'
            : String(value);

        row.appendChild(cell);
        return cell;
    };

    const formatDate = (value) => {
        if (!value) {
            return '—';
        }

        const date = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString();
    };

    const formatQuantity = (value) => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        const number = Number(value);

        return Number.isFinite(number)
            ? number.toLocaleString(undefined, {
                maximumFractionDigits: 3
            })
            : String(value);
    };

    const setRowEnabled = (checkbox) => {
        const row = checkbox.closest('tr');

        if (!row) {
            return;
        }

        row.querySelectorAll('.audit-item-field').forEach((field) => {
            field.disabled = !checkbox.checked;
        });
    };

    const updateIncludeAllState = () => {
        if (!includeAllCheckbox || !auditForm) {
            return;
        }

        const checkboxes = Array.from(
            auditForm.querySelectorAll('.audit-item-select')
        );

        if (checkboxes.length === 0) {
            includeAllCheckbox.checked = false;
            includeAllCheckbox.indeterminate = false;
            return;
        }

        const selectedCount = checkboxes.filter(
            (checkbox) => checkbox.checked
        ).length;

        includeAllCheckbox.checked = selectedCount === checkboxes.length;
        includeAllCheckbox.indeterminate =
            selectedCount > 0 && selectedCount < checkboxes.length;
    };

    if (auditForm) {
        auditForm.querySelectorAll('.audit-item-select').forEach((checkbox) => {
            setRowEnabled(checkbox);

            checkbox.addEventListener('change', () => {
                setRowEnabled(checkbox);
                updateIncludeAllState();
            });
        });
    }

    if (includeAllCheckbox && auditForm) {
        includeAllCheckbox.addEventListener('change', () => {
            auditForm.querySelectorAll('.audit-item-select').forEach(
                (checkbox) => {
                    checkbox.checked = includeAllCheckbox.checked;
                    setRowEnabled(checkbox);
                }
            );

            includeAllCheckbox.indeterminate = false;
        });
    }

    if (auditForm) {
        auditForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const selectedItems = auditForm.querySelectorAll(
                '.audit-item-select:checked'
            );

            if (selectedItems.length === 0) {
                await showMessage(
                    'warning',
                    'No items selected',
                    'Select at least one item to include in this audit.'
                );
                return;
            }

            const confirmed = await confirmAction(
                'Record physical count?',
                'This will save the physical quantities and variances as an audit record. Material stock balances will not be changed.',
                'Record Count'
            );

            if (!confirmed) {
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const formData = new FormData(auditForm);

                const response = await fetch(
                    apiUrl('physical_stock_audit_create.php'),
                    {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );

                const result = await readJsonResponse(response);

                await showMessage(
                    'success',
                    'Count recorded',
                    result.message || 'The physical count was recorded successfully.'
                );

                window.location.reload();
            } catch (error) {
                await showMessage(
                    'error',
                    'Unable to record count',
                    error.message
                );
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }

    const renderAuditSummary = (audit) => {
        detailsSummary.replaceChildren();

        const fields = [
            ['Department', audit.department_name],
            ['Audit Type', audit.audit_type],
            ['Status', audit.audit_status],
            ['Audit Date', formatDate(audit.audit_date)],
            [
                'Created By',
                [
                    audit.creator_first_name,
                    audit.creator_last_name
                ].filter(Boolean).join(' ') || audit.creator_username
            ],
            ['Submitted At', formatDate(audit.submitted_at)],
            [
                'Reviewed By',
                [
                    audit.reviewer_first_name,
                    audit.reviewer_last_name
                ].filter(Boolean).join(' ') || audit.reviewer_username
            ],
            ['Reviewed At', formatDate(audit.reviewed_at)],
            ['Audit Notes', audit.audit_notes],
            ['Review Notes', audit.review_notes]
        ];

        fields.forEach(([label, value]) => {
            const column = document.createElement('div');
            column.className = 'col-md-6';

            const card = document.createElement('div');
            card.className = 'border rounded p-3 h-100';

            const heading = document.createElement('div');
            heading.className = 'small text-muted mb-1';
            heading.textContent = label;

            const content = document.createElement('div');
            content.className = 'fw-semibold';
            content.textContent = value || '—';

            card.append(heading, content);
            column.appendChild(card);
            detailsSummary.appendChild(column);
        });
    };

    const renderConsumableItems = (items) => {
        detailsHead.replaceChildren();
        detailsBody.replaceChildren();

        const headerRow = document.createElement('tr');

        [
            'Material',
            'System Quantity',
            'Physical Quantity',
            'Variance',
            'Notes'
        ].forEach((heading) => {
            const cell = document.createElement('th');
            cell.textContent = heading;
            headerRow.appendChild(cell);
        });

        detailsHead.appendChild(headerRow);

        if (!items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-center text-muted py-4';
            cell.textContent = 'No audited items found.';
            row.appendChild(cell);
            detailsBody.appendChild(row);
            return;
        }

        items.forEach((item) => {
            const row = document.createElement('tr');

            appendCell(
                row,
                `${item.material_code || ''} — ${item.material_name || ''} (${item.material_unit || ''})`
            );
            appendCell(row, formatQuantity(item.system_quantity));
            appendCell(row, formatQuantity(item.physical_quantity));
            appendCell(row, formatQuantity(item.variance));
            appendCell(row, item.item_notes);

            detailsBody.appendChild(row);
        });
    };

    const renderReturnableItems = (items) => {
        detailsHead.replaceChildren();
        detailsBody.replaceChildren();

        const headerRow = document.createElement('tr');

        [
            'Material',
            'Borrower',
            'Borrowed At',
            'Issued',
            'System Remaining',
            'Physical Count',
            'Variance',
            'Notes'
        ].forEach((heading) => {
            const cell = document.createElement('th');
            cell.textContent = heading;
            headerRow.appendChild(cell);
        });

        detailsHead.appendChild(headerRow);

        if (!items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 8;
            cell.className = 'text-center text-muted py-4';
            cell.textContent = 'No audited items found.';
            row.appendChild(cell);
            detailsBody.appendChild(row);
            return;
        }

        items.forEach((item) => {
            const row = document.createElement('tr');

            appendCell(
                row,
                `${item.material_code || ''} — ${item.material_name || ''} (${item.material_unit || ''})`
            );
            appendCell(
                row,
                item.issued_to_name || (
                    item.issued_to_user_id
                        ? `User ID: ${item.issued_to_user_id}`
                        : '—'
                )
            );
            appendCell(row, formatDate(item.borrowed_at));
            appendCell(row, formatQuantity(item.issued_quantity));
            appendCell(row, formatQuantity(item.system_remaining_quantity));
            appendCell(row, formatQuantity(item.physical_quantity));
            appendCell(row, formatQuantity(item.variance));
            appendCell(row, item.item_notes);

            detailsBody.appendChild(row);
        });
    };

    const renderReturnHistory = (history) => {
        returnHistoryBody.replaceChildren();

        if (!history.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.className = 'text-center text-muted py-3';
            cell.textContent = 'No return transactions found for these audited items.';
            row.appendChild(cell);
            returnHistoryBody.appendChild(row);
            return;
        }

        history.forEach((entry) => {
            const row = document.createElement('tr');

            appendCell(row, formatQuantity(entry.returned_quantity));
            appendCell(row, formatDate(entry.returned_at));
            appendCell(
                row,
                entry.received_by_name || (
                    entry.received_by
                        ? `User ID: ${entry.received_by}`
                        : '—'
                )
            );
            appendCell(row, entry.return_notes);

            returnHistoryBody.appendChild(row);
        });
    };

    const openAuditDetails = async (auditId) => {
        if (!detailsModal) {
            await showMessage(
                'error',
                'Modal unavailable',
                'The audit details window could not be opened. Please check that Bootstrap JavaScript is loaded.'
            );
            return;
        }

        detailsLoading.classList.remove('d-none');
        detailsError.classList.add('d-none');
        detailsContent.classList.add('d-none');
        detailsReference.textContent = '';

        detailsModal.show();

        try {
            const url = new URL(
                apiUrl('physical_stock_audit_details.php')
            );
            url.searchParams.set('audit_id', auditId);

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await readJsonResponse(response);
            const audit = result.data;

            if (!audit) {
                throw new Error('Audit details were not returned by the server.');
            }

            detailsReference.textContent = audit.audit_reference || '';

            renderAuditSummary(audit);

            const items = Array.isArray(audit.items) ? audit.items : [];

            if (audit.audit_type === 'returnable') {
                renderReturnableItems(items);

                returnHistorySection.classList.remove('d-none');

                renderReturnHistory(
                    Array.isArray(audit.return_history)
                        ? audit.return_history
                        : []
                );
            } else {
                renderConsumableItems(items);
                returnHistorySection.classList.add('d-none');
                returnHistoryBody.replaceChildren();
            }

            detailsContent.classList.remove('d-none');
        } catch (error) {
            detailsError.textContent = error.message;
            detailsError.classList.remove('d-none');
        } finally {
            detailsLoading.classList.add('d-none');
        }
    };

    document.querySelectorAll('.view-audit-button').forEach((button) => {
        button.addEventListener('click', () => {
            const auditId = button.dataset.auditId;

            if (auditId) {
                openAuditDetails(auditId);
            }
        });
    });
});