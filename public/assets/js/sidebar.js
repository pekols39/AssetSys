document.addEventListener('DOMContentLoaded', () => {

    const sidebar =
        document.getElementById('appSidebar');

    const toggleButton =
        document.getElementById('sidebarToggle');

    const closeButton =
        document.getElementById('sidebarClose');

    const overlay =
        document.getElementById('sidebarOverlay');

    if (
        !sidebar
        || !toggleButton
        || !closeButton
        || !overlay
    ) {
        return;
    }

    const openSidebar = () => {

        sidebar.classList.add('show');
        overlay.classList.add('show');
        document.body.classList.add('sidebar-open');

        toggleButton.setAttribute(
            'aria-expanded',
            'true'
        );

        overlay.setAttribute(
            'aria-hidden',
            'false'
        );
    };

    const closeSidebar = () => {

        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');

        toggleButton.setAttribute(
            'aria-expanded',
            'false'
        );

        overlay.setAttribute(
            'aria-hidden',
            'true'
        );
    };

    toggleButton.addEventListener(
        'click',
        openSidebar
    );

    closeButton.addEventListener(
        'click',
        closeSidebar
    );

    overlay.addEventListener(
        'click',
        closeSidebar
    );

    sidebar
        .querySelectorAll('a:not(.disabled)')
        .forEach((link) => {

            link.addEventListener(
                'click',
                closeSidebar
            );

        });

    document.addEventListener(
        'keydown',
        (event) => {

            if (event.key === 'Escape') {
                closeSidebar();
            }

        }
    );

    window.addEventListener(
        'resize',
        () => {

            if (window.innerWidth > 991.98) {
                closeSidebar();
            }

        }
    );

});