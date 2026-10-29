<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuthorizationService;

$authorization = new AuthorizationService();

Auth::user();

$pageTitle = $pageTitle ?? APP_NAME;
$pageStylesheet = $pageStylesheet ?? null;

$currentUser = Auth::user();

$firstName = $currentUser['first_name']
    ?? $currentUser['username']
    ?? 'User';

$roleName = $currentUser['role_name']
    ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= e($pageTitle) ?> | <?= e(APP_NAME) ?>
    </title>

    <meta name="description" content="<?= e(APP_NAME) ?>">

    <meta name="author" content="IT Department">

    <!-- Company Font -->
    <link rel="stylesheet" href="<?= e(asset('css/font.css')) ?>">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">

    <!-- Responsive CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">

    <!-- Brand Override -->
    <link rel="stylesheet" href="<?= e(asset('css/brand-override.css')) ?>">

    <!-- Application CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/app-layout.css')) ?>">

    <!-- Notifications CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/notifications.css')) ?>">

    <?php if (!empty($pageStylesheet)): ?>

        <link rel="stylesheet" href="<?= e(asset($pageStylesheet)) ?>">

    <?php endif; ?>

    <link rel="icon" href="<?= e(asset('images/favicon.ico')) ?>">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</head>

<body>

<?php require __DIR__ . '/app_sidebar.php'; ?>

<div class="app-mobile-header">

    <button
        type="button"
        class="app-mobile-menu-button"
        id="sidebarToggle"
        aria-label="Open navigation menu"
        aria-controls="appSidebar"
        aria-expanded="false"
    >
        <i class="fa-solid fa-bars"></i>
    </button>

    <a
        href="<?= e(base_url('/public/dashboard/')) ?>"
        class="app-mobile-brand"
    >
        <span class="app-mobile-brand-icon">
            <i class="fa-solid fa-boxes-stacked"></i>
        </span>

        <span class="app-mobile-brand-text">
            Asset System
        </span>
    </a>


    <div
        class="notification-area"
        id="mobileNotificationArea"
        data-notifications-url="<?= e(base_url('/public/api/notifications.php')) ?>"
        data-notification-read-url="<?= e(base_url('/public/api/notification_read.php')) ?>"
        data-csrf-token="<?= e(\App\Helpers\Csrf::generate()) ?>"
        data-csrf-name="<?= e(CSRF_TOKEN_NAME) ?>"
    >

        <button
            type="button"
            class="notification-bell"
            id="mobileNotificationToggle"
            aria-label="Notifications"
            aria-expanded="false"
            aria-controls="mobileNotificationPanel"
        >
            <i class="fa-regular fa-bell" aria-hidden="true"></i>
            <span
                class="notification-badge"
                id="mobileNotificationBadge"
                hidden
            >0</span>
        </button>

        <section
            class="notification-panel"
            id="mobileNotificationPanel"
            aria-label="Notifications"
            hidden
        >
            <div class="notification-panel-header">
                <h2 class="notification-panel-title">Notifications</h2>
                <span
                    class="notification-unread-label"
                    id="mobileNotificationUnreadLabel"
                >0 unread</span>
            </div>

            <div
                class="notification-list"
                id="mobileNotificationList"
                aria-live="polite"
            >
                <div class="notification-loading">
                    Notifications will load here.
                </div>
            </div>

            <div class="notification-panel-footer">
                Asset transfers and disposals
            </div>
        </section>
    </div>
</div>

<div
    class="app-sidebar-overlay"
    id="sidebarOverlay"
    aria-hidden="true"
></div>

<header class="app-topbar" id="appTopbar">
    <div class="app-topbar-welcome">
        Welcome back, <?= e($firstName) ?>
    </div>

    <div class="app-topbar-actions">
        <div class="app-topbar-datetime">
            <span id="appTopbarDate"></span>
            <span id="appTopbarTime"></span>
        </div>

        <div
            class="notification-area app-topbar-notifications"
            id="desktopNotificationArea"
            data-notifications-url="<?= e(base_url('/public/api/notifications.php')) ?>"
            data-notification-read-url="<?= e(base_url('/public/api/notification_read.php')) ?>"
            data-csrf-token="<?= e(\App\Helpers\Csrf::generate()) ?>"
            data-csrf-name="<?= e(CSRF_TOKEN_NAME) ?>"
        >
            <button
                type="button"
                class="notification-bell"
                id="desktopNotificationToggle"
                aria-label="Notifications"
                aria-expanded="false"
                aria-controls="desktopNotificationPanel"
            >
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <span
                    class="notification-badge"
                    id="desktopNotificationBadge"
                    hidden
                >0</span>
            </button>

            <section
                class="notification-panel"
                id="desktopNotificationPanel"
                aria-label="Notifications"
                hidden
            >
                <div class="notification-panel-header">
                    <h2 class="notification-panel-title">Notifications</h2>
                    <span
                        class="notification-unread-label"
                        id="desktopNotificationUnreadLabel"
                    >0 unread</span>
                </div>

                <div
                    class="notification-list"
                    id="desktopNotificationList"
                    aria-live="polite"
                >
                    <div class="notification-loading">
                        Notifications will load here.
                    </div>
                </div>

                <div class="notification-panel-footer">
                    Asset transfers and disposals
                </div>
            </section>
        </div>

        <div class="dropdown app-topbar-user">
            <button
                type="button"
                class="btn dropdown-toggle app-topbar-user-toggle"
                id="appTopbarUserToggle"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
                <span><?= e($firstName) ?></span>
            </button>

            <ul
                class="dropdown-menu dropdown-menu-end"
                aria-labelledby="appTopbarUserToggle"
            >
                <li>
                    <span class="dropdown-item-text">
                        <strong><?= e($firstName) ?></strong><br>
                        <small class="text-muted"><?= e($roleName) ?></small>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= e(base_url('/public/auth/logout.php')) ?>" id="topbarLogoutLink">
                        <i class="fa-solid fa-right-from-bracket me-2 text-danger" aria-hidden="true"></i>
                        Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>

<main class="app-main">
