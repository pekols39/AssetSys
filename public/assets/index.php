<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();


$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

$assetService = new AssetService();

if ($authorization->isAssetManager()) {
    $departmentId = $authorization->departmentId();

    $assets = $departmentId === null
        ? []
        : $assetService->getAll($departmentId);
} elseif ($authorization->isAssetCustodian()) {
    $departmentId = $authorization->departmentId();

    $assets = $departmentId === null
        ? []
        : $assetService->getAll($departmentId);
} else {
    http_response_code(403);
    exit('You do not have permission to view the asset register.');
}

$pageTitle = 'Assets';
$pageStylesheet = 'css/index.css';
$pageScript = 'js/index.js';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>

        <div class="text-muted small mb-1">
            Asset Management
        </div>

        <h1 class="h3 mb-1">
            Assets
        </h1>

        <p class="text-muted mb-0">
            Manage and monitor company assets.
        </p>

    </div>

    <a href="<?= e(base_url('/public/assets/create.php')) ?>" class="btn btn-primary">
        <i class="fa-solid fa-plus me-1"></i>
        Add Asset
    </a>

</div>

<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

            <div>
                <h2 class="h5 mb-0">
                    Asset Register
                </h2>

                <div class="text-muted small">
                    <?= e((string) count($assets)) ?>
                    asset(s)
                </div>
            </div>

        </div>

    </div>

    <div class="card-body">

        <?php if (empty($assets)): ?>

            <div class="text-center py-5">

                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-box-open"></i>
                </div>

                <h3 class="h5">
                    No assets found
                </h3>

                <p class="text-muted mb-4">
                    No assets have been registered yet.
                </p>

                <a href="<?= e(base_url('/public/assets/create.php')) ?>" class="btn btn-primary">
                    <i class="fa-solid fa-plus me-1"></i>
                    Add First Asset
                </a>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table id="assetsTable" class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Asset Code
                            </th>

                            <th>
                                Asset
                            </th>

                            <th>
                                Category
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Condition
                            </th>

                            <th>
                                Location
                            </th>

                            <th>
                                Assigned To
                            </th>

                            <th class="text-end">
                                Purchase Cost
                            </th>

                            <th class="text-end">
                                Book Value
                            </th>

                            <th class="text-center">
                                Action
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($assets as $asset): ?>

                            <?php
                            $assignedName = trim(
                                ($asset['assigned_first_name'] ?? '')
                                . ' '
                                . ($asset['assigned_last_name'] ?? '')
                            );

                            $assignedName = $assignedName !== ''
                                ? $assignedName
                                : 'Unassigned';

                            $statusName =
                                $asset['status_name'] ?? 'Unknown';

                            $statusClass = match (
                            strtolower($statusName)
                            ) {
                                'available' => 'status-available',
                                'assigned' => 'status-assigned',
                                'under maintenance' =>
                                    'status-maintenance',
                                'under repair' =>
                                    'status-repair',
                                'lost' => 'status-lost',
                                'damaged' => 'status-damaged',
                                'for disposal' =>
                                    'status-disposal',
                                'retired' => 'status-retired',
                                'disposed' => 'status-disposed',
                                default => 'status-default',
                            };
                            ?>

                            <tr>

                                <td>

                                    <span class="asset-code">
                                        <?= e(
                                            $asset['asset_code']
                                        ) ?>
                                    </span>

                                    <?php if (!empty($asset['asset_tag'])): ?>

                                        <div class="text-muted small">
                                            Tag:
                                            <?= e($asset['asset_tag']) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="fw-semibold">
                                        <?= e(
                                            $asset['asset_name']
                                        ) ?>
                                    </div>

                                    <?php if (!empty($asset['serial_number'])): ?>

                                        <div class="text-muted small">
                                            S/N:
                                            <?= e(
                                                $asset['serial_number']
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= e(
                                        $asset['category_name']
                                        ?? '—'
                                    ) ?>
                                </td>

                                <td>

                                    <span class="badge asset-status <?= e($statusClass) ?>">
                                        <?= e($statusName) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= e(
                                        $asset['condition_name']
                                        ?? '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $asset['location_name']
                                        ?? '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($assignedName) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    PHP
                                    <?= e(
                                        number_format(
                                            (float) (
                                                $asset['purchase_cost']
                                                ?? 0
                                            ),
                                            2
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    PHP
                                    <?= e(
                                        number_format(
                                            (float) (
                                                $asset['current_book_value']
                                                ?? 0
                                            ),
                                            2
                                        )
                                    ) ?>
                                </td>

                                <td class="text-center">

                                    <a href="<?= e(base_url('/public/assets/view.php?id=' . $asset['id'])) ?>"
                                        class="btn btn-sm asset-view-button" title="View asset">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';