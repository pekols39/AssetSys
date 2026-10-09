<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);

    exit('Authentication required.');
}

if (
    !$authorization->isAssetManager()
    && !$authorization->isAssetCustodian()
) {
    http_response_code(403);

    exit(
        '403 Forbidden: Asset Manager or Asset Custodian access required.'
    );
}

$materialService = new MaterialService();

$materials = $materialService->getAll();

$pageTitle = 'Materials';

require_once dirname(__DIR__)
    . '/includes/app_header.php';

?>

<div class="d-flex flex-column flex-md-row
            justify-content-between
            align-items-md-center
            gap-3 mb-4">

    <div>

        <div class="text-muted small mb-1">
            Materials & Stock
        </div>

        <h1 class="h3 mb-1">
            Materials
        </h1>

        <p class="text-muted mb-0">
            Manage the materials used for department inventory.
        </p>

    </div>


    <div>

        <a href="<?= e(
            base_url('/public/materials/create.php')
        ) ?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i>
            Add Material
        </a>

    </div>

</div>


<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <div class="d-flex
                    justify-content-between
                    align-items-center">

            <h2 class="h5 mb-0">
                Materials
            </h2>

            <span class="text-muted small">

                <?= e(
                    (string) count($materials)
                ) ?>

                material(s)

            </span>

        </div>

    </div>


    <div class="card-body p-0">

        <?php if (empty($materials)): ?>

            <div class="text-center py-5 px-3">

                <div class="text-muted fs-1 mb-3">

                    <i class="fa-solid fa-boxes-stacked"></i>

                </div>

                <h3 class="h5">
                    No materials
                </h3>

                <p class="text-muted mb-3">
                    No materials have been registered yet.
                </p>

                <a href="<?= e(
                    base_url('/public/materials/create.php')
                ) ?>" class="btn btn-primary">
                    <i class="fa-solid fa-plus me-1"></i>
                    Add Material
                </a>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-hover
                           align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Code
                            </th>

                            <th>
                                Material
                            </th>

                            <th>
                                Category
                            </th>

                            <th>
                                Unit
                            </th>

                            <th class="text-end">
                                Minimum Stock
                            </th>

                            <th class="text-end">
                                Reorder Level
                            </th>

                            <th class="text-center">
                                Returnable
                            </th>

                            <th class="text-center">
                                Status
                            </th>

                            <th class="text-center" style="width: 250px">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($materials as $material): ?>

                            <?php
                            $materialId = (int) $material['id'];

                            $isActive =
                                (int) $material['is_active'] === 1;

                            $isReturnable =
                                (int) $material['is_returnable'] === 1;

                            $materialNameJson = htmlspecialchars(
                                json_encode(
                                    (string) $material['name'],
                                    JSON_HEX_TAG
                                    | JSON_HEX_APOS
                                    | JSON_HEX_QUOT
                                    | JSON_HEX_AMP
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>

                            <tr>

                                <td>

                                    <span class="fw-semibold">
                                        <?= e(
                                            (string) $material['code']
                                        ) ?>
                                    </span>

                                </td>


                                <td>

                                    <div class="fw-semibold">

                                        <?= e(
                                            (string) $material['name']
                                        ) ?>

                                    </div>

                                    <?php if (
                                        !empty(
                                        $material['description']
                                    )
                                    ): ?>

                                        <div class="text-muted small">

                                            <?= e(
                                                (string) $material[
                                                    'description'
                                                ]
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= e(
                                        (string) $material[
                                            'category_name'
                                        ]
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        (string) $material['unit']
                                    ) ?>

                                </td>


                                <td class="text-end">

                                    <?= e(
                                        number_format(
                                            (float) $material[
                                                'minimum_stock_level'
                                            ],
                                            3
                                        )
                                    ) ?>

                                </td>


                                <td class="text-end">

                                    <?= e(
                                        number_format(
                                            (float) $material[
                                                'reorder_level'
                                            ],
                                            3
                                        )
                                    ) ?>

                                </td>


                                <td class="text-center">

                                    <?php if ($isReturnable): ?>

                                        <span class="badge bg-success">
                                            Yes
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            No
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="text-center">

                                    <?php if ($isActive): ?>

                                        <span class="badge bg-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="text-center">

                                    <div class="material-actions">

                                        <a href="<?= e(
                                            base_url(
                                                '/public/materials/edit.php?id='
                                                . $materialId
                                            )
                                        ) ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>
                                            Edit
                                        </a>


                                        <?php if ($isActive): ?>

                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="changeMaterialStatus(
                                                <?= $materialId ?>,
                                                'deactivate',
                                                <?= $materialNameJson ?>
                                            )">
                                                <i class="fa-solid fa-ban me-1"></i>
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button type="button" class="btn btn-outline-success btn-sm" onclick="changeMaterialStatus(
                                                <?= $materialId ?>,
                                                'activate',
                                                <?= $materialNameJson ?>
                                            )">
                                                <i class="fa-solid fa-check me-1"></i>
                                                Activate
                                            </button>
                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<style>
    .material-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .material-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    .material-actions .btn-outline-danger,
    .material-actions .btn-outline-success {
        min-width: 105px;
    }
</style>


<form id="materialStatusForm" method="post" action="<?= e(
    base_url('/public/api/material_status.php')
) ?>" class="d-none">
    <?= Csrf::field() ?>

    <input type="hidden" name="id" id="materialStatusId">

    <input type="hidden" name="action" id="materialStatusAction">
</form>


<script>

    async function changeMaterialStatus(
        materialId,
        action,
        materialName
    ) {
        const isActivating = action === 'activate';

        const actionText = isActivating
            ? 'activate'
            : 'deactivate';

        const result = await Swal.fire({
            icon: isActivating ? 'question' : 'warning',
            title: isActivating
                ? 'Activate Material?'
                : 'Deactivate Material?',
            text:
                'Are you sure you want to '
                + actionText
                + ' "' + materialName + '"?',
            showCancelButton: true,
            confirmButtonText: isActivating
                ? 'Yes, Activate'
                : 'Yes, Deactivate',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#007472'
        });

        if (!result.isConfirmed) {
            return;
        }

        const form = document.getElementById(
            'materialStatusForm'
        );

        const materialIdInput = document.getElementById(
            'materialStatusId'
        );

        const actionInput = document.getElementById(
            'materialStatusAction'
        );

        materialIdInput.value = materialId;
        actionInput.value = action;

        try {
            const response = await fetch(
                <?= json_encode(
                    base_url('/public/api/material_status.php'),
                    JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_HEX_AMP
                ) ?>,
                {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message
                    || 'Unable to update material status.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message,
                confirmButtonColor: '#007472'
            });

            window.location.reload();

        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'Unable to Update Status',
                text:
                    error.message
                    || 'Unable to update material status.',
                confirmButtonColor: '#007472'
            });
        }
    }

</script>


<?php

require_once dirname(__DIR__)
    . '/includes/app_footer.php';

?>