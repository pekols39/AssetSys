<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\DashboardService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();
    header('Location: ' . base_url('/public/login.php'));
    exit;
}


$roleName = $user['role_name'] ?? '';
$departmentId = (int) ($user['department_id'] ?? 0);

if (
    ($user['username'] ?? '') !== 'manager'
    || ($user['role_name'] ?? '') !== 'Asset Manager'
) {
    http_response_code(403);
    exit('Access denied. The dashboard is unavailable for your account.');
}

if ($departmentId <= 0) {
    http_response_code(403);
    exit('Access denied. Your account has no assigned department.');
}

$dashboardService = new DashboardService($departmentId);
$summary = $dashboardService->getSummary();

$assetsByCategory =
    $dashboardService->getAssetsByCategory();

$assetsByLocation =
    $dashboardService->getAssetsByLocation();

$depreciationByYear =
    $dashboardService->getDepreciationByYear();

$maintenanceCosts =
    $dashboardService->getMaintenanceCosts();

$repairCosts =
    $dashboardService->getRepairCosts();

$assetsByCondition =
    $dashboardService->getAssetsByCondition();

$upcomingMaintenance =
    $dashboardService->getUpcomingMaintenance();

$pageTitle = 'Dashboard';

$pageStylesheet = 'css/dashboard.css';

$pageScripts = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js',
    'js/dashboard.js',
];

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="dashboard-page">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">

        <div>
            <h1 class="mb-1">
                Dashboard
            </h1>

            <p class="text-muted mb-0">
                Asset overview and operational summary.
            </p>
        </div>

    </div>

    <div class="row g-4">

        <!-- Total Assets -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Total Assets
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['total_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Assigned -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-user-check"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Assigned
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['assigned_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Available -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-box-open"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Available
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['available_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Under Maintenance -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Under Maintenance
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['maintenance_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Under Repair -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-toolbox"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Under Repair
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['repair_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Disposed -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-box-archive"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Disposed
                    </div>

                    <div class="dashboard-stat-value">
                        <?= number_format($summary['disposed_assets']) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Total Purchase Value -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-peso-sign"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Total Purchase Value
                    </div>

                    <div class="dashboard-stat-value dashboard-stat-value-money">
                        ₱<?= number_format(
                            $summary['total_purchase_value'],
                            2
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Current Book Value -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="dashboard-stat-card">

                <div class="dashboard-stat-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>

                <div class="dashboard-stat-content">

                    <div class="dashboard-stat-label">
                        Current Book Value
                    </div>

                    <div class="dashboard-stat-value dashboard-stat-value-money">
                        ₱<?= number_format(
                            $summary['current_book_value'],
                            2
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="row g-4 mt-1">

        <div class="col-12 col-xl-6">

            <div class="dashboard-chart-card">

                <div class="dashboard-chart-header">

                    <div>
                        <h5 class="mb-1">
                            Assets by Category
                        </h5>

                        <p class="text-muted mb-0">
                            Number of assets grouped by category.
                        </p>
                    </div>

                </div>

                <div class="dashboard-chart-container">
                    <canvas id="assetsByCategoryChart"></canvas>
                </div>

            </div>

        </div>

        <div class="col-12 col-xl-6">

            <div class="dashboard-chart-card">

                <div class="dashboard-chart-header">

                    <div>
                        <h5 class="mb-1">
                            Assets by Location
                        </h5>

                        <p class="text-muted mb-0">
                            Number of assets grouped by location.
                        </p>
                    </div>

                </div>

                <div class="dashboard-chart-container">
                    <canvas id="assetsByLocationChart"></canvas>
                </div>

            </div>

        </div>

    </div>

    <!-- Financial and Maintenance Trends -->
    <div class="row g-4 mt-1">

        <div class="col-12">

            <div class="dashboard-chart-card">

                <div class="dashboard-chart-header">
                    <h5>Financial &amp; Maintenance Trends</h5>

                    <p class="text-muted small mb-0">
                        Compare annual depreciation, maintenance, and repair costs.
                    </p>
                </div>

                <div class="dashboard-chart-container">
                    <canvas id="financialMaintenanceTrendsChart"></canvas>
                </div>

            </div>

        </div>

    </div>

    <!-- Upcoming Maintenance and Asset Condition -->
    <div class="dashboard-section-heading">
        <h2>Asset Health &amp; Maintenance</h2>

        <p>
            Review scheduled maintenance and the current physical condition of assets.
        </p>
    </div>

    <div class="row g-4">

        <!-- Upcoming Maintenance -->
        <div class="col-12 col-xl-7">

            <div class="dashboard-chart-card dashboard-maintenance-card">

                <div class="dashboard-chart-header">
                    <h5 class="mb-1">Upcoming Maintenance</h5>

                    <p class="text-muted small mb-0">
                        Overdue and scheduled maintenance for the next 30 days.
                    </p>
                </div>

                <div class="table-responsive">
                    <table class="table dashboard-maintenance-table align-middle mb-0">

                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Maintenance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($upcomingMaintenance)): ?>

                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        No upcoming maintenance found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($upcomingMaintenance as $maintenance): ?>

                                    <?php
                                    $dueDate = $maintenance['next_maintenance_date']
                                        ?? $maintenance['scheduled_date']
                                        ?? null;

                                    $attentionStatus =
                                        $maintenance['attention_status'] ?? 'upcoming';

                                    $statusLabel = match ($attentionStatus) {
                                        'overdue' => 'Overdue',
                                        'due_today' => 'Due Today',
                                        default => 'Upcoming',
                                    };

                                    $statusClass = match ($attentionStatus) {
                                        'overdue' => 'danger',
                                        'due_today' => 'warning',
                                        default => 'success',
                                    };
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="fw-semibold">
                                                <?= e($maintenance['asset_name']) ?>
                                            </div>

                                            <small class="text-muted">
                                                <?= e($maintenance['asset_code']) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= e($maintenance['title']) ?>
                                        </td>

                                        <td>
                                            <?= $dueDate
                                                ? e(date('M d, Y', strtotime($dueDate)))
                                                : '—' ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e($statusClass) ?>">
                                                <?= e($statusLabel) ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>
                        </tbody>

                    </table>
                </div>

            </div>

        </div>

        <!-- Assets by Condition -->
        <div class="col-12 col-xl-5">

            <div class="dashboard-chart-card">

                <div class="dashboard-chart-header">
                    <h5 class="mb-1">Assets by Condition</h5>

                    <p class="text-muted small mb-0">
                        Distribution by recorded physical condition.
                    </p>
                </div>

                <div class="dashboard-chart-container">
                    <canvas id="assetsByConditionChart"></canvas>
                </div>

            </div>

        </div>

    </div>

</div>

</div>

<script>
    window.dashboardData = {
        assetsByCategory: {
            labels: <?= json_encode(
                array_column($assetsByCategory, 'category_name'),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'intval',
                    array_column($assetsByCategory, 'asset_count')
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        },

        assetsByLocation: {
            labels: <?= json_encode(
                array_column($assetsByLocation, 'location_name'),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'intval',
                    array_column($assetsByLocation, 'asset_count')
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        },

        assetsByCondition: {
            labels: <?= json_encode(
                array_column(
                    $assetsByCondition,
                    'condition_name'
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'intval',
                    array_column(
                        $assetsByCondition,
                        'asset_count'
                    )
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        },

        depreciationByYear: {
            labels: <?= json_encode(
                array_column($depreciationByYear, 'depreciation_year'),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'floatval',
                    array_column(
                        $depreciationByYear,
                        'depreciation_amount'
                    )
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        },

        maintenanceCosts: {
            labels: <?= json_encode(
                array_column($maintenanceCosts, 'cost_year'),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'floatval',
                    array_column(
                        $maintenanceCosts,
                        'maintenance_cost'
                    )
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        },

        repairCosts: {
            labels: <?= json_encode(
                array_column($repairCosts, 'cost_year'),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,

            values: <?= json_encode(
                array_map(
                    'floatval',
                    array_column(
                        $repairCosts,
                        'repair_cost'
                    )
                ),
                JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>
        }
    };
</script>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php';