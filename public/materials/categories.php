<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialCategoryService;

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

$categoryService = new MaterialCategoryService();

$categories = $categoryService->getAll();

$pageTitle = 'Material Categories';

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
            Material Categories
        </h1>

        <p class="text-muted mb-0">
            Manage the categories used for department materials.
        </p>

    </div>

</div>


<div class="row g-4">

    <!-- Add Category -->
    <div class="col-12 col-lg-4">

        <div class="card border-0 shadow-sm">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-0">
                    Add Category
                </h2>

            </div>

            <div class="card-body">


                <form id="addCategoryForm" method="post" action="<?= e(
                    base_url(
                        '/public/api/material_category_create.php'
                    )
                ) ?>">


                    <?= Csrf::field() ?>

                    <div class="mb-3">

                        <label for="categoryName" class="form-label">
                            Name
                        </label>

                        <input type="text" class="form-control" id="categoryName" name="name" maxlength="150" required>

                    </div>


                    <div class="mb-3">

                        <label for="categoryCode" class="form-label">
                            Code
                        </label>

                        <input type="text" class="form-control" id="categoryCode" name="code" maxlength="50">

                        <div class="form-text">
                            Optional unique category code.
                        </div>

                    </div>


                    <div class="mb-3">

                        <label for="categoryDescription" class="form-label">
                            Description
                        </label>

                        <textarea class="form-control" id="categoryDescription" name="description" rows="3"
                            maxlength="255"></textarea>

                    </div>


                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-plus me-1"></i>

                        Add Category

                    </button>

                </form>

            </div>

        </div>

    </div>


    <!-- Categories -->
    <div class="col-12 col-lg-8">

        <div class="card border-0 shadow-sm">

            <div class="card-header bg-white py-3">

                <div class="d-flex
                            justify-content-between
                            align-items-center">

                    <h2 class="h5 mb-0">
                        Categories
                    </h2>

                    <span class="text-muted small">

                        <?= e(
                            (string) count($categories)
                        ) ?>

                        category(s)

                    </span>

                </div>

            </div>


            <div class="card-body p-0">

                <?php if (empty($categories)): ?>

                    <div class="text-center py-5 px-3">

                        <div class="text-muted fs-1 mb-3">

                            <i class="fa-solid fa-layer-group"></i>

                        </div>

                        <h3 class="h5">
                            No material categories
                        </h3>

                        <p class="text-muted mb-0">

                            Add the first material category
                            using the form.

                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-hover
                                      align-middle mb-0">

                            <thead>

                                <tr>

                                    <th>
                                        Name
                                    </th>

                                    <th>
                                        Code
                                    </th>

                                    <th>
                                        Description
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

                                <?php foreach ($categories as $category): ?>

                                    <tr>

                                        <td>

                                            <?= e(
                                                $category['name']
                                            ) ?>

                                        </td>


                                        <td>

                                            <?= e(
                                                $category['code'] ?? ''
                                            ) ?>

                                        </td>


                                        <td>

                                            <?= e(
                                                $category['description'] ?? ''
                                            ) ?>

                                        </td>


                                        <td class="text-center">

                                            <?php if (
                                                (int) $category['is_active'] === 1
                                            ): ?>

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
                                            <div class="category-actions">

                                                <button type="button" class="btn btn-outline-primary btn-sm edit-category-btn"
                                                    data-id="<?= (int) $category['id'] ?>"
                                                    data-name="<?= e($category['name']) ?>"
                                                    data-code="<?= e($category['code'] ?? '') ?>"
                                                    data-description="<?= e($category['description'] ?? '') ?>">
                                                    <i class="fas fa-edit me-1"></i>
                                                    Edit
                                                </button>


                                                <?php if ((int) $category['is_active'] === 1): ?>
                                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                                        onclick="changeCategoryStatus(<?= (int) $category['id'] ?>, 'deactivate')">
                                                        <i class="fas fa-ban me-1"></i>
                                                        Deactivate
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-success btn-sm"
                                                        onclick="changeCategoryStatus(<?= (int) $category['id'] ?>, 'activate')">
                                                        <i class="fas fa-check me-1"></i>
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

    </div>

</div>


<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel"
    aria-hidden="true">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h2 class="modal-title h5" id="editCategoryModalLabel">
                    Edit Material Category
                </h2>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

            </div>


            <form method="post" action="<?= e(
                base_url(
                    '/public/api/material_category_update.php'
                )
            ) ?>" id="editCategoryForm">

                <div class="modal-body">

                    <?= Csrf::field() ?>

                    <input type="hidden" name="id" id="editCategoryId">


                    <div class="mb-3">

                        <label for="editCategoryName" class="form-label">
                            Name
                        </label>

                        <input type="text" class="form-control" name="name" id="editCategoryName" maxlength="150"
                            required>

                    </div>


                    <div class="mb-3">

                        <label for="editCategoryCode" class="form-label">
                            Code
                        </label>

                        <input type="text" class="form-control" name="code" id="editCategoryCode" maxlength="50">

                        <div class="form-text">
                            Optional unique category code.
                        </div>

                    </div>


                    <div class="mb-3">

                        <label for="editCategoryDescription" class="form-label">
                            Description
                        </label>

                        <textarea class="form-control" name="description" id="editCategoryDescription" rows="3"
                            maxlength="255"></textarea>

                    </div>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-floppy-disk me-1"></i>

                        Update Category

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- Category Status Form -->
<form id="categoryStatusForm" method="post" action="<?= e(
    base_url(
        '/public/api/material_category_status.php'
    )
) ?>" class="d-none">

    <?= Csrf::field() ?>

</form>


<script>

    (function () {
        'use strict';

        const form = document.getElementById('addCategoryForm');

        if (!form) {
            return;
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');

            if (!submitButton) {
                return;
            }

            const originalText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>Adding...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                let data;

                try {
                    data = await response.json();
                } catch (jsonError) {
                    throw new Error('The server returned an unexpected response.');
                }

                if (!response.ok || !data.success) {
                    throw new Error(
                        data.message || 'Unable to create the material category.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Category Created',
                    text: data.message || 'Material category created successfully.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();
            } catch (error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Creation Failed',
                    text: error.message || 'Unable to create the material category.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
        });
    })();


    function editCategory(id, name, code, description) {
        document.getElementById('editCategoryId').value = id;
        document.getElementById('editCategoryName').value = name;
        document.getElementById('editCategoryCode').value = code;
        document.getElementById('editCategoryDescription').value =
            description || '';

        const modalElement = document.getElementById('editCategoryModal');

        if (!modalElement) {
            Swal.fire({
                icon: 'error',
                title: 'Unable to Edit',
                text: 'The edit category dialog could not be found.',
                confirmButtonColor: '#007472'
            });
            return;
        }

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.edit-category-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                editCategory(
                    button.dataset.id,
                    button.dataset.name || '',
                    button.dataset.code || '',
                    button.dataset.description || ''
                );
            });
        });
    });



    (function () {
        'use strict';

        const form = document.getElementById('editCategoryForm');

        if (!form) {
            return;
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.classList.add('was-validated');

                await Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Form',
                    text: 'Please complete all required fields before updating.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                return;
            }

            const submitButton = form.querySelector(
                'button[type="submit"]'
            );

            if (!submitButton) {
                return;
            }

            const originalText = submitButton.innerHTML;

            submitButton.disabled = true;

            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>' +
                'Updating...';

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

                let result;

                try {
                    result = await response.json();
                } catch (jsonError) {
                    throw new Error(
                        'The server returned an unexpected response.'
                    );
                }

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message
                        || 'Unable to update the material category.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Category Updated',
                    text: result.message
                        || 'Material category updated successfully.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                window.location.reload();

            } catch (error) {

                await Swal.fire({
                    icon: 'error',
                    title: 'Update Failed',
                    text: error.message
                        || 'Unable to update the material category.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#007472'
                });

                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
        });
    })();


    async function changeCategoryStatus(id, action, categoryName) {
        const isDeactivate = action === 'deactivate';

        if (
            action !== 'activate'
            && action !== 'deactivate'
        ) {
            await Swal.fire({
                icon: 'error',
                title: 'Invalid Action',
                text: 'The requested category status action is invalid.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            return;
        }

        const result = await Swal.fire({
            icon: isDeactivate ? 'warning' : 'question',
            title: isDeactivate
                ? 'Deactivate Category?'
                : 'Activate Category?',
            text: isDeactivate
                ? `Are you sure you want to deactivate "${categoryName}"?`
                : `Are you sure you want to activate "${categoryName}"?`,
            showCancelButton: true,
            confirmButtonText: isDeactivate
                ? 'Yes, Deactivate'
                : 'Yes, Activate',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#007472',
            reverseButtons: true
        });

        if (!result.isConfirmed) {
            return;
        }

        const form = document.getElementById(
            'categoryStatusForm'
        );

        if (!form) {
            await Swal.fire({
                icon: 'error',
                title: 'Security Error',
                text: 'Security form could not be found. Please refresh the page and try again.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            return;
        }

        const formData = new FormData(form);

        formData.append(
            'id',
            String(id)
        );

        formData.append(
            'action',
            action
        );

        try {
            const response = await fetch(
                form.action,
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            let data;

            try {
                data = await response.json();
            } catch (jsonError) {
                throw new Error(
                    'The server returned an invalid response.'
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message
                    || 'Unable to update material category status.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: isDeactivate
                    ? 'Category Deactivated'
                    : 'Category Activated',
                text: data.message
                    || (
                        isDeactivate
                            ? 'Material category deactivated successfully.'
                            : 'Material category activated successfully.'
                    ),
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });

            window.location.reload();

        } catch (error) {

            await Swal.fire({
                icon: 'error',
                title: 'Status Update Failed',
                text: error.message
                    || 'Unable to update material category status.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#007472'
            });
        }
    }
</script>


<?php

require_once dirname(__DIR__)
    . '/includes/app_footer.php';

?>