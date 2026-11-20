(function () {
    'use strict';

    const table = document.getElementById('assetsTable');

    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tbody tr');
    const tableViewport = document.getElementById('assetsTableScroll');
    const fixedScrollbar = document.getElementById('assetsHorizontalScrollbar');
    const fixedScrollbarInner = document.getElementById('assetsHorizontalScrollbarInner');

    if (tableViewport && fixedScrollbar && fixedScrollbarInner) {
        let syncingScroll = false;

        const syncScrollbarSize = function () {
            fixedScrollbarInner.style.width = table.scrollWidth + 'px';
            const rect = tableViewport.getBoundingClientRect();
            fixedScrollbar.style.left = rect.left + 'px';
            fixedScrollbar.style.width = rect.width + 'px';

            const hasHorizontalOverflow = table.scrollWidth > tableViewport.clientWidth + 1;
            const tableIsInView = rect.bottom > 0 && rect.top < window.innerHeight;
            fixedScrollbar.classList.toggle('is-visible', hasHorizontalOverflow && tableIsInView);
        };

        tableViewport.addEventListener('scroll', function () {
            if (syncingScroll) return;
            syncingScroll = true;
            fixedScrollbar.scrollLeft = tableViewport.scrollLeft;
            syncingScroll = false;
        });

        fixedScrollbar.addEventListener('scroll', function () {
            if (syncingScroll) return;
            syncingScroll = true;
            tableViewport.scrollLeft = fixedScrollbar.scrollLeft;
            syncingScroll = false;
        });

        window.addEventListener('scroll', syncScrollbarSize, { passive: true });
        window.addEventListener('resize', syncScrollbarSize);
        if ('ResizeObserver' in window) {
            new ResizeObserver(syncScrollbarSize).observe(table);
            new ResizeObserver(syncScrollbarSize).observe(tableViewport);
        }
        syncScrollbarSize();
    }


    const bulkForm = document.getElementById('bulkQrForm');
    if (bulkForm) {
        const selectAll = document.getElementById('selectAllFilteredAssets');
        const rowChecks = Array.from(table.querySelectorAll('.asset-row-select'));
        const modeInput = document.getElementById('bulkSelectionMode');
        const countLabel = document.getElementById('bulkSelectedCount');
        const summary = document.getElementById('bulkSelectionSummary');
        const generateSubmit = document.getElementById('bulkQrGenerateSubmit');
        const printSubmit = document.getElementById('bulkQrPrintSubmit');
        const totalFiltered = Number(bulkForm.dataset.totalFiltered || 0);
        const refreshSelection = function () {
            const allFiltered = Boolean(selectAll && selectAll.checked);
            const selectedOnPage = rowChecks.filter(function (checkbox) { return checkbox.checked; }).length;
            const selectedCount = allFiltered ? totalFiltered : selectedOnPage;
            modeInput.value = allFiltered ? 'all_filtered' : 'selected';
            countLabel.textContent = selectedCount.toLocaleString() + ' selected';
            summary.textContent = allFiltered
                ? 'All ' + totalFiltered.toLocaleString() + ' assets matching the current filters are selected across every page.'
                : 'Select assets on this page, or use the header checkbox to select every matching asset across all pages. Generate and print are separate actions.';
            generateSubmit.disabled = selectedCount < 1;
            printSubmit.disabled = selectedCount < 1;
        };
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
                refreshSelection();
            });
        }
        rowChecks.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (selectAll && selectAll.checked && !checkbox.checked) {
                    selectAll.checked = false;
                    rowChecks.forEach(function (other) { if (other !== checkbox) other.checked = false; });
                }
                refreshSelection();
            });
        });
        bulkForm.addEventListener('submit', function (event) {
            const allFiltered = Boolean(selectAll && selectAll.checked);
            bulkForm.querySelectorAll('.bulk-generated-selection').forEach(function (input) { input.remove(); });
            if (allFiltered) {
                modeInput.value = 'all_filtered';
            } else {
                const selectedIds = rowChecks.filter(function (checkbox) { return checkbox.checked; }).map(function (checkbox) { return checkbox.value; });
                if (selectedIds.length === 0) { event.preventDefault(); return; }
                modeInput.value = 'selected';
                selectedIds.forEach(function (id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'asset_ids[]';
                    input.value = id;
                    input.className = 'bulk-generated-selection';
                    bulkForm.appendChild(input);
                });
            }
            const count = allFiltered ? totalFiltered : rowChecks.filter(function (checkbox) { return checkbox.checked; }).length;
            const action = event.submitter && event.submitter.value === 'print' ? 'print' : 'generate';
            const message = action === 'print'
                ? 'Queue NIIMBOT printing for ' + count.toLocaleString() + ' selected asset(s) that already have active QR identities? Assets without an active QR identity will be skipped.'
                : 'Generate or reuse QR identities for ' + count.toLocaleString() + ' selected asset(s)? This will not send anything to the printer.';
            if (!window.confirm(message)) event.preventDefault();
        });
        refreshSelection();
    }

    rows.forEach(function (row) {
        row.addEventListener('click', function (event) {
            const action = event.target.closest(
                'a, button, input, select'
            );

            if (action) {
                return;
            }

            const viewLink = row.querySelector(
                'a[title="View asset"]'
            );

            if (viewLink) {
                window.location.href = viewLink.href;
            }
        });
    });
})();