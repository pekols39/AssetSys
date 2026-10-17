<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialCategoryService;
use App\Services\MaterialService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);

    exit('Authentication required.');
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit('You do not have permission to access this area.');
}

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $materialId === false
    || $materialId === null
    || $materialId <= 0
) {
    http_response_code(400);

    exit('Invalid material ID.');
}

$materialService = new MaterialService();
$categoryService = new MaterialCategoryService();

$material = $materialService->get($materialId);

if (!is_array($material)) {
    http_response_code(404);

    exit('Material not found.');
}

$categories = $categoryService->getActive();

$pageTitle = 'Edit Material';

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
            Edit Material
        </h1>

        <p class="text-muted mb-0">
            Update the information for this material.
        </p>

    </div>

    <div>

        <a
            href="<?= e(
                base_url('/public/materials/index.php')
            ) ?>"
            class="btn btn-outline-secondary"
        >
            <i class="fa-solid fa-arrow-left me-1"></i>
            Back to Materials
        </a>

    </div>

</div>


<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <h2 class="h5 mb-0">
            Material Information
        </h2>

    </div>


    <div class="card-body">

        <form
            id="materialEditForm"
            method="post"
            action="<?= e(
                base_url('/public/api/material_update.php')
            ) ?>"
        >

            <?= Csrf::field() ?>

            <input
                type="hidden"
                name="id"
                value="<?= e(
                    (string) $material['id']
                ) ?>"
            >


            <div class="row g-3">

                <div class="col-md-6">

                    <label
                        for="category_id"
                        class="form-label"
                    >
                        Category
                        <span class="text-danger">*</span>
                    </label>

                    <select
                        id="category_id"
                        name="category_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select category
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?= e(
                                    (string) $category['id']
                                ) ?>"
                                <?= (int) $category['id']
                                    === (int) $material['category_id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e(
                                    (string) $category['name']
                                ) ?>

                                <?php if (
                                    !empty($category['code'])
                                ): ?>

                                    (<?= e(
                                        (string) $category['code']
                                    ) ?>)

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-6">

                    <label
                        for="code"
                        class="form-label"
                    >
                        Material Code
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        id="code"
                        name="code"
                        class="form-control"
                        maxlength="50"
                        value="<?= e(
                            (string) $material['code']
                        ) ?>"
                        required
                    >

                    <div class="form-text">
                        Material code must remain unique.
                    </div>

                </div>


                <div class="col-md-8">

                    <label
                        for="name"
                        class="form-label"
                    >
                        Material Name
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="form-control"
                        maxlength="150"
                        value="<?= e(
                            (string) $material['name']
                        ) ?>"
                        required
                    >

                </div>


                <div class="col-md-4">

                    <label
                        for="unit"
                        class="form-label"
                    >
                        Unit of Measure
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        id="unit"
                        name="unit"
                        class="form-control"
                        maxlength="50"
                        value="<?= e(
                            (string) $material['unit']
                        ) ?>"
                        required
                    >

                </div>


                <div class="col-12">

                    <label
                        for="description"
                        class="form-label"
                    >
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        class="form-control"
                        rows="3"
                        maxlength="255"
                    ><?= e(
                        (string) (
                            $material['description'] ?? ''
                        )
                    ) ?></textarea>

                </div>


                <div class="col-md-4">

                    <label
                        for="minimum_stock_level"
                        class="form-label"
                    >
                        Minimum Stock Level
                    </label>

                    <input
                        type="number"
                        id="minimum_stock_level"
                        name="minimum_stock_level"
                        class="form-control"
                        min="0"
                        step="0.001"
                        value="<?= e(
                            (string) $material[
                                'minimum_stock_level'
                            ]
                        ) ?>"
                    >

                </div>


                <div class="col-md-4">

                    <label
                        for="reorder_level"
                        class="form-label"
                    >
                        Reorder Level
                    </label>

                    <input
                        type="number"
                        id="reorder_level"
                        name="reorder_level"
                        class="form-control"
                        min="0"
                        step="0.001"
                        value="<?= e(
                            (string) $material[
                                'reorder_level'
                            ]
                        ) ?>"
                    >

                </div>


                <div class="col-md-4">

                    <label class="form-label d-block">
                        Returnable Material
                    </label>

                    <div class="form-check mt-2">

                        <input
                            type="checkbox"
                            id="is_returnable"
                            name="is_returnable"
                            value="1"
                            class="form-check-input"
                            <?= (int) $material['is_returnable'] === 1
                                ? 'checked'
                                : '' ?>
                        >

                        <label
                            for="is_returnable"
                            class="form-check-label"
                        >
                            This material can be returned
                        </label>

                    </div>

                </div>

            </div>


            <hr class="my-4">


            <div class="d-flex
                        justify-content-end
                        gap-2">

                <a
                    href="<?= e(
                        base_url('/public/materials/index.php')
                    ) ?>"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>


<script>

document.getElementById('materialEditForm')
    ?.addEventListener('submit', async function (event) {

        event.preventDefault();

        const form = this;

        const submitButton =
            form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        try {

            const response = await fetch(
                form.action,
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
                    || 'Unable to update material.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: 'Material Updated',
                text: data.message
                    || 'Material updated successfully.',
                confirmButtonColor: '#007472'
            });

            window.location.href =
                <?= json_encode(
                    base_url('/public/materials/index.php'),
                    JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_HEX_AMP
                ) ?>;

        } catch (error) {

            await Swal.fire({
                icon: 'error',
                title: 'Unable to Update Material',
                text: error.message
                    || 'Unable to update material.',
                confirmButtonColor: '#007472'
            });

        } finally {

            if (submitButton) {
                submitButton.disabled = false;
            }

        }

    });

</script>


<?php

require_once dirname(__DIR__)
    . '/includes/app_footer.php';

?>