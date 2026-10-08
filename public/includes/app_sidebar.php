<?php

declare(strict_types=1);

$currentPath = $_SERVER['PHP_SELF'] ?? '';

$isDashboard = str_contains(
    $currentPath,
    '/dashboard/'
);

$isAssets = str_contains(
    $currentPath,
    '/assets/'
);

$isAdministrationDashboard = str_ends_with(
    $currentPath,
    '/administration/index.php'
)
    || str_ends_with(
        $currentPath,
        '/administration/'
    );

$isAdministrationSettings = str_ends_with(
    $currentPath,
    '/administration/settings.php'
);
?>

<aside class="app-sidebar" id="appSidebar">

    <button type="button" class="app-sidebar-close" id="sidebarClose" aria-label="Close navigation menu">
        <i class="fa-solid fa-xmark"></i>
    </button>

    <div class="app-sidebar-brand">

        <a href="<?= e(base_url('/public/dashboard/')) ?>" class="app-sidebar-brand-link">

            <span class="app-sidebar-brand-icon">
                <i class="fa-solid fa-boxes-stacked"></i>
            </span>

            <span class="app-sidebar-brand-text">
                Asset System
            </span>

        </a>

    </div>


    <?php if ($roleName === 'Asset Manager'): ?>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-title">
                MAIN
            </div>

            <nav class="app-sidebar-nav">
                <a href="<?= e(base_url('/public/dashboard/')) ?>"
                    class="app-sidebar-link <?= $isDashboard ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-gauge-high"></i>
                    </span>
                    <span class="app-sidebar-link-text">Dashboard</span>
                </a>

                <a href="<?= e(base_url('/public/assets/')) ?>" class="app-sidebar-link <?= $isAssets ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </span>
                    <span class="app-sidebar-link-text">Assets</span>
                </a>
            </nav>
        </div>

    <?php elseif ($roleName === 'Asset Custodian'): ?>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-title">
                DEPARTMENT ASSETS
            </div>

            <nav class="app-sidebar-nav">

                <a href="<?= e(base_url('/public/dashboard/')) ?>"
                    class="app-sidebar-link <?= $isDashboard ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-gauge-high"></i>
                    </span>
                    <span class="app-sidebar-link-text">Dashboard</span>
                </a>

                <a href="<?= e(base_url('/public/assets/')) ?>" class="app-sidebar-link <?= $isAssets ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </span>
                    <span class="app-sidebar-link-text">Department Assets</span>
                </a>

            </nav>
        </div>

    <?php elseif ($roleName === 'Administrator'): ?>

        <div class="app-sidebar-section">

            <div class="app-sidebar-section-title">
                ADMINISTRATION
            </div>

            <nav class="app-sidebar-nav">

                <a href="<?= e(base_url('/public/administration/')) ?>"
                    class="app-sidebar-link <?= $isAdministrationDashboard ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-gauge-high"></i>
                    </span>

                    <span class="app-sidebar-link-text">
                        Dashboard
                    </span>
                </a>

                <a href="<?= e(base_url('/public/administration/users/')) ?>" class="app-sidebar-link <?= str_contains(
                      $currentPath,
                      '/administration/users/'
                  ) ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-users"></i>
                    </span>

                    <span class="app-sidebar-link-text">
                        User Accounts
                    </span>
                </a>

                <a href="<?= e(base_url('/public/administration/settings.php')) ?>"
                    class="app-sidebar-link <?= $isAdministrationSettings ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-gear"></i>
                    </span>

                    <span class="app-sidebar-link-text">
                        Settings
                    </span>
                </a>

            </nav>

        </div>

    <?php endif; ?>

    <div class="app-sidebar-spacer"></div>

    <div class="app-sidebar-user">

        <div class="app-sidebar-user-avatar">
            <i class="fa-solid fa-user"></i>
        </div>

        <div class="app-sidebar-user-info">

            <div class="app-sidebar-user-name">
                <?= e($firstName) ?>
            </div>

            <div class="app-sidebar-user-role">
                <?= e($roleName) ?>
            </div>

        </div>

    </div>

    <div class="app-sidebar-footer">

        <a href="<?= e(base_url('/public/auth/logout.php')) ?>" class="app-sidebar-logout" id="logoutLink">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>