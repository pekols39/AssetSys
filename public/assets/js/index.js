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