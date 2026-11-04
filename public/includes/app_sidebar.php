<?php

declare(strict_types=1);

$currentPath = $_SERVER['PHP_SELF'] ?? '';

$isDashboard = str_contains(
    $currentPath,
    '/dashboard/'
);

$isDepartmentTransfers = str_contains(
    $currentPath,
    '/assets/department_transfer'
);

$isDisposalRequests = str_ends_with(
    $currentPath,
    '/assets/disposal_requests.php'
);

$isAssets =
    str_contains($currentPath, '/assets/')
    && !$isDepartmentTransfers
    && !$isDisposalRequests;

$isInventoryReport =
    str_ends_with($currentPath, '/reports/index.php')
    || str_ends_with($currentPath, '/reports/');

$isDepreciationReport =
    str_ends_with($currentPath, '/reports/depreciation.php');

$isDepreciationSchedules =
    str_ends_with($currentPath, '/reports/depreciation_schedules.php');

$isQrScanMonitoring =
    str_ends_with($currentPath, '/reports/qr_scan_monitoring.php');

$isMaterialBorrow = str_ends_with($currentPath, '/materials/borrow.php');

$isMaterialBorrowings = str_ends_with($currentPath, '/materials/borrowings.php');

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
                Inventory Tracker
            </span>

        </a>

    </div>


    <?php if (
        isset($authorization)
        && $authorization->canAccessAssetOperations()
    ): ?>

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

                <a href="<?= e(base_url('/public/assets/department_transfers.php')) ?>"
                    class="app-sidebar-link <?= $isDepartmentTransfers ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-building-circle-arrow-right"></i>
                    </span>
                    <span class="app-sidebar-link-text">Asset Transfers</span>
                </a>

                <?php if ($authorization->canAccessAssetOperations()): ?>
                    <a href="<?= e(base_url('/public/assets/disposal_requests.php')) ?>"
                        class="app-sidebar-link <?= $isDisposalRequests ? 'active' : '' ?>">
                        <span class="app-sidebar-link-icon">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </span>
                        <span class="app-sidebar-link-text">Asset Disposal</span>
                    </a>
                <?php endif; ?>

                <a href="<?= e(base_url('/public/materials/categories.php')) ?>" class="app-sidebar-link <?= str_contains(
                      $currentPath,
                      '/materials/categories.php'
                  ) ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-layer-group"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        Material Categories
                    </span>
                </a>

                <a href="<?= base_url('/public/materials/index.php') ?>" class="app-sidebar-link <?= (
                      str_contains($currentPath, '/materials/index.php') ||
                      str_contains($currentPath, '/materials/create.php') ||
                      str_contains($currentPath, '/materials/edit.php')
                  ) ? 'active' : '' ?>">
                    <i class="fas fa-boxes"></i>
                    <span>Materials</span>
                </a>

                <a href="<?= e(base_url('/public/materials/physical_stock_audits.php')) ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/physical_stock_audits.php') ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        Physical Stock Audit
                    </span>
                </a>

                <?php if ($authorization->isPropertyCustodian()): ?>

                    <a href="<?= e(base_url('/public/materials/borrow.php')) ?>"
                        class="app-sidebar-link <?= $isMaterialBorrow ? 'active' : '' ?>">
                        <span class="app-sidebar-link-icon">
                            <i class="fa-solid fa-hand-holding"></i>
                        </span>
                        <span class="app-sidebar-link-text">Borrow Materials</span>
                    </a>

                    <a href="<?= e(base_url('/public/materials/borrowings.php')) ?>"
                        class="app-sidebar-link <?= $isMaterialBorrowings ? 'active' : '' ?>">
                        <span class="app-sidebar-link-icon">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </span>
                        <span class="app-sidebar-link-text">Borrowing Records</span>
                    </a>

                <?php endif; ?>

                <!-- Stock navigation temporarily hidden for Fixed Asset Management.
                <a href="<?= base_url('/public/materials/stock.php') ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/stock.php') ? 'active' : '' ?>">
                    <i class="fas fa-warehouse"></i>
                    <span>Stock</span>
                </a>

                <a href="<?= base_url('/public/materials/stock_in.php') ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/stock_in.php') ? 'active' : '' ?>">
                    <i class="fas fa-arrow-down"></i>
                    <span>Stock In</span>
                </a>

                <a href="<?= base_url('/public/materials/stock_out.php') ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/stock_out.php') ? 'active' : '' ?>">
                    <i class="fas fa-arrow-up"></i>
                    <span>Stock Out</span>
                </a>

                <a href="<?= base_url('/public/materials/stock_return.php') ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/stock_return.php') ? 'active' : '' ?>">
                    <i class="fas fa-undo"></i>
                    <span>Stock Returns</span>
                </a>

                <a href="<?= e(base_url('/public/materials/movements.php')) ?>"
                    class="app-sidebar-link <?= str_contains($currentPath, '/materials/movements.php') ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Stock Movement History</span>
                </a>
                -->
            </nav>
        </div>

    <?php endif; ?>

    <?php if (
        isset($authorization)
        && $authorization->canAccessAssetMaterialAreas()
    ): ?>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-title">
                REPORTS
            </div>

            <nav class="app-sidebar-nav">
                <a href="<?= e(base_url('/public/reports/')) ?>"
                    class="app-sidebar-link <?= $isInventoryReport ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        Asset Inventory Report
                    </span>
                </a>

                <a href="<?= e(base_url('/public/reports/depreciation.php')) ?>"
                    class="app-sidebar-link <?= $isDepreciationReport ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-chart-line"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        Depreciation Report
                    </span>
                </a>

                <a href="<?= e(base_url('/public/reports/depreciation_schedules.php')) ?>"
                    class="app-sidebar-link <?= $isDepreciationSchedules ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-calendar-check"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        Schedule Monitoring
                    </span>
                </a>

                <a href="<?= e(base_url('/public/reports/qr_scan_monitoring.php')) ?>"
                    class="app-sidebar-link <?= $isQrScanMonitoring ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-qrcode"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        QR Scan Monitoring
                    </span>
                </a>
            </nav>
        </div>

    <?php endif; ?>

    <?php if (
        isset($authorization)
        && $authorization->isPropertyCustodian()
    ): ?>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-title">
                MONITORING
            </div>

            <nav class="app-sidebar-nav">
                <a href="<?= e(base_url('/public/reports/qr_scan_monitoring.php')) ?>"
                    class="app-sidebar-link <?= $isQrScanMonitoring ? 'active' : '' ?>">
                    <span class="app-sidebar-link-icon">
                        <i class="fa-solid fa-qrcode"></i>
                    </span>
                    <span class="app-sidebar-link-text">
                        QR Scan Monitoring
                    </span>
                </a>
            </nav>
        </div>

    <?php endif; ?>

    <?php if (
        isset($authorization)
        && $authorization->isAdministrator()
    ): ?>

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