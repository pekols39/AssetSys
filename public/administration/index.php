<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AdministrationDashboardService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();

    header(
        'Location: ' . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->isAdministrator()) {
    http_response_code(403);

    exit(
        '403 Forbidden: Administrator access required.'
    );
}

$dashboardService =
    new AdministrationDashboardService();

$summary =
    $dashboardService->getSummary();

$systemStatus =
    $dashboardService->getSystemStatus();

$pageTitle = 'Administrator Dashboard';

$pageStylesheet =
    'css/administration.css';

require_once dirname(__DIR__)
    . '/includes/app_header.php';

?>

<div class="administration-page">

    <div class="d-flex flex-column flex-md-row
                justify-content-between
                align-items-md-center
                gap-3 mb-4">

        <div>

            <h1 class="mb-1">
                Administrator Dashboard
            </h1>

            <p class="text-muted mb-0">
                System administration and account overview.
            </p>

        </div>

    </div>


    <div class="row g-4">

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="admin-stat-card">

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

                <div>

                    <div class="admin-stat-label">
                        Total Users
                    </div>

                    <div class="admin-stat-value">
                        <?= number_format(
                            $summary['total_users']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="admin-stat-card">

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-user-check"></i>
                </div>

                <div>

                    <div class="admin-stat-label">
                        Active Users
                    </div>

                    <div class="admin-stat-value">
                        <?= number_format(
                            $summary['active_users']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="admin-stat-card">

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-user-slash"></i>
                </div>

                <div>

                    <div class="admin-stat-label">
                        Inactive Users
                    </div>

                    <div class="admin-stat-value">
                        <?= number_format(
                            $summary['inactive_users']
                        ) ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="admin-stat-card">

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-key"></i>
                </div>

                <div>

                    <div class="admin-stat-label">
                        Password Change Required
                    </div>

                    <div class="admin-stat-value">
                        <?= number_format(
                            $summary[
                                'password_change_users'
                            ]
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <div class="admin-section-heading">

        <h2>
            System Status
        </h2>

        <p>
            Basic application infrastructure status.
        </p>

    </div>


    <div class="row g-4">

        <div class="col-12 col-md-3">

            <div class="admin-status-card">

                <div class="admin-status-label">
                    Database
                </div>

                <div class="admin-status-value">

                    <?php if (
                        $systemStatus['database']
                    ): ?>

                        <span class="text-success">
                            <i class="fa-solid fa-circle-check"></i>
                            Connected
                        </span>

                    <?php else: ?>

                        <span class="text-danger">
                            <i class="fa-solid fa-circle-xmark"></i>
                            Unavailable
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-3">

            <div class="admin-status-card">

                <div class="admin-status-label">
                    Storage
                </div>

                <div class="admin-status-value">

                    <?php if (
                        $systemStatus['storage_exists']
                        && $systemStatus['storage_writable']
                    ): ?>

                        <span class="text-success">
                            <i class="fa-solid fa-circle-check"></i>
                            Available
                        </span>

                    <?php elseif (
                        $systemStatus['storage_exists']
                    ): ?>

                        <span class="text-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Not Writable
                        </span>

                    <?php else: ?>

                        <span class="text-danger">
                            <i class="fa-solid fa-circle-xmark"></i>
                            Missing
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-3">

            <div class="admin-status-card">

                <div class="admin-status-label">
                    Application
                </div>

                <div class="admin-status-value">

                    <span class="text-success">
                        <i class="fa-solid fa-circle-check"></i>
                        Operational
                    </span>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-3">

            <div class="admin-status-card">

                <div class="admin-status-label">
                    Debug Mode
                </div>

                <div class="admin-status-value">

                    <?php if (
                        $systemStatus['debug_mode']
                    ): ?>

                        <span class="text-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Enabled
                        </span>

                    <?php else: ?>

                        <span class="text-success">
                            <i class="fa-solid fa-circle-check"></i>
                            Disabled
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
require_once dirname(__DIR__)
    . '/includes/app_footer.php';
?>