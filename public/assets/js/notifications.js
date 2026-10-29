document.addEventListener('DOMContentLoaded', () => {
    const areas = [
        document.getElementById('mobileNotificationArea'),
        document.getElementById('desktopNotificationArea'),
    ].filter(Boolean);

    if (!areas.length) return;

    const controls = areas.map((area) => ({
        area,
        toggle: area.querySelector('.notification-bell'),
        panel: area.querySelector('.notification-panel'),
        badge: area.querySelector('.notification-badge'),
        unreadLabel: area.querySelector('.notification-unread-label'),
        list: area.querySelector('.notification-list'),
        notificationsUrl: area.dataset.notificationsUrl,
        notificationReadUrl: area.dataset.notificationReadUrl,
        csrfToken: area.dataset.csrfToken,
        csrfName: area.dataset.csrfName,
    }));

    if (controls.some((c) =>
        !c.toggle || !c.panel || !c.badge || !c.unreadLabel ||
        !c.list || !c.notificationsUrl || !c.notificationReadUrl ||
        !c.csrfToken || !c.csrfName
    )) {
        console.error('Notification interface configuration is incomplete.');
        return;
    }

    let notifications = [];
    let unreadCount = 0;
    let refreshInProgress = false;
    let refreshTimer = null;

    function updateUnreadCount(count) {
        unreadCount = Math.max(0, Number(count) || 0);

        controls.forEach((c) => {
            c.badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            c.badge.hidden = unreadCount === 0;
            c.unreadLabel.textContent = `${unreadCount} unread`;
        });
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function showMessage(control, message, className = 'notification-empty') {
        control.list.replaceChildren();
        const element = document.createElement('div');
        element.className = className;
        element.textContent = message;
        control.list.appendChild(element);
    }

    function destinationFor(notification) {
        const relatedType = String(notification.related_type || '');
        const notificationType = String(notification.notification_type || '');
        const relatedId = Number(notification.related_id);
        const assetId = Number(notification.asset_id);

        if (!Number.isInteger(relatedId) || relatedId <= 0) {
            return null;
        }

        // Derive the application base path from the notifications API URL.
        // This supports installations such as /AssetSys as well as /.
        const apiUrl = new URL(
            controls[0].notificationsUrl,
            window.location.href
        );

        const apiMarker = '/public/api/notifications.php';
        const markerIndex = apiUrl.pathname.lastIndexOf(apiMarker);

        if (markerIndex < 0) {
            console.error('Unable to determine the application base path.');
            return null;
        }

        const appBasePath = apiUrl.pathname.slice(0, markerIndex);

        function buildAppUrl(path, query = {}) {
            const url = new URL(window.location.origin);

            url.pathname = `${appBasePath}${path}`;
            url.search = '';
            url.hash = '';

            Object.entries(query).forEach(([key, value]) => {
                url.searchParams.set(key, String(value));
            });

            return url.toString();
        }

        // A new transfer request needs the Asset Manager's review list.
        if (
            relatedType === 'department_transfer' &&
            notificationType === 'department_transfer_requested'
        ) {
            return buildAppUrl('/public/assets/department_transfers.php');
        }

        // A new disposal request needs the Asset Manager's review list.
        if (
            relatedType === 'disposal' &&
            notificationType === 'disposal_requested'
        ) {
            return `${buildAppUrl('/public/assets/disposal_requests.php')}#pending-disposal-requests`;
        }

        // Approval/rejection notifications should lead to the associated asset.
        if (
            (relatedType === 'disposal' ||
                relatedType === 'department_transfer') &&
            Number.isInteger(assetId) &&
            assetId > 0
        ) {
            return buildAppUrl('/public/assets/view.php', {
                id: assetId,
            });
        }

        // If a transfer notification has no asset ID, at least open its list.
        if (relatedType === 'department_transfer') {
            return buildAppUrl('/public/assets/department_transfers.php');
        }

        return null;
    }

    async function markAsRead(notification) {
        if (Number(notification.is_read) !== 0) return true;

        const formData = new FormData();
        formData.append('notification_id', String(notification.id));
        formData.append(controls[0].csrfName, controls[0].csrfToken);

        try {
            const response = await fetch(controls[0].notificationReadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: formData,
                cache: 'no-store',
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to mark notification as read.');
            }

            notification.is_read = 1;
            updateUnreadCount(result.unread_count);
            renderAllNotifications();
            return true;
        } catch (error) {
            console.error('Notification could not be marked as read:', error);
            return false;
        }
    }

    function renderNotifications(control) {
        control.list.replaceChildren();

        if (!notifications.length) {
            showMessage(control, 'You have no notifications.');
            return;
        }

        notifications.forEach((notification) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'notification-item';

            const isUnread = Number(notification.is_read) === 0;
            if (isUnread) item.classList.add('is-unread');

            const title = document.createElement('span');
            title.className = 'notification-item-title';
            title.textContent = notification.title || 'Notification';

            const message = document.createElement('span');
            message.className = 'notification-item-message';
            message.textContent = notification.message || '';

            const time = document.createElement('span');
            time.className = 'notification-item-time';
            time.textContent = formatDate(notification.created_at);

            const destination = destinationFor(notification);
            if (destination) {
                const action = document.createElement('span');
                action.className = 'notification-item-action';
                action.textContent = 'View details →';
                item.append(title, message, time, action);
            } else {
                item.append(title, message, time);
            }

            item.setAttribute('aria-label', `${notification.title || 'Notification'}. Open notification.`);

            item.addEventListener('click', async () => {
                item.disabled = true;
                const success = await markAsRead(notification);

                if (!success) {
                    item.disabled = false;
                    return;
                }

                const url = destinationFor(notification);
                if (url) {
                    window.location.href = url;
                } else {
                    item.disabled = false;
                }
            });

            control.list.appendChild(item);
        });
    }

    function renderAllNotifications() {
        controls.forEach(renderNotifications);
    }

    async function refreshNotifications({ showLoading = false } = {}) {
        if (refreshInProgress || document.hidden) return;

        refreshInProgress = true;

        if (showLoading) {
            controls.forEach((c) =>
                showMessage(c, 'Loading notifications...', 'notification-loading')
            );
        }

        try {
            const response = await fetch(controls[0].notificationsUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            const result = await response.json();

            if (!response.ok || !result.success || !result.data) {
                throw new Error(result.message || `Request failed: ${response.status}`);
            }

            notifications = Array.isArray(result.data.notifications)
                ? result.data.notifications
                : [];

            updateUnreadCount(result.data.unread_count);
            renderAllNotifications();
        } catch (error) {
            console.error('Notifications could not be loaded:', error);

            if (showLoading) {
                controls.forEach((c) =>
                    showMessage(c, 'Unable to load notifications. Please try again.', 'notification-error')
                );
            }
        } finally {
            refreshInProgress = false;
        }
    }

    controls.forEach((control) => {
        control.toggle.addEventListener('click', async () => {
            const opening = control.panel.hidden;

            controls.forEach((other) => {
                other.panel.hidden = true;
                other.toggle.setAttribute('aria-expanded', 'false');
            });

            control.panel.hidden = !opening;
            control.toggle.setAttribute('aria-expanded', String(opening));

            if (opening) {
                await refreshNotifications({ showLoading: true });
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!controls.some((c) => c.area.contains(event.target))) {
            controls.forEach((c) => {
                c.panel.hidden = true;
                c.toggle.setAttribute('aria-expanded', 'false');
            });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            controls.forEach((c) => {
                if (!c.panel.hidden) {
                    c.panel.hidden = true;
                    c.toggle.setAttribute('aria-expanded', 'false');
                    c.toggle.focus();
                }
            });
        }
    });

    function startPolling() {
        if (refreshTimer !== null) clearInterval(refreshTimer);
        refreshTimer = setInterval(() => refreshNotifications(), 5000);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (refreshTimer !== null) clearInterval(refreshTimer);
            refreshTimer = null;
        } else {
            refreshNotifications();
            startPolling();
        }
    });

    refreshNotifications();
    startPolling();
});
