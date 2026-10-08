<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AdministrationUserService;
use App\Services\AuthorizationService;
use App\Services\ReferenceDataService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    Auth::logout();

    header(
        'Location: '
        . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('Access denied. Administrator access required.');
}

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $userId === false
    || $userId === null
    || $userId <= 0
) {
    http_response_code(400);
    exit('Invalid user account.');
}

$userService = new AdministrationUserService();
$account = $userService->findById($userId);

if ($account === null) {
    http_response_code(404);
    exit('User account not found.');
}

$referenceData = new ReferenceDataService();

$roles = $referenceData->getRoles();
$departments = $referenceData->getDepartments();

$pageTitle = 'Edit User Account';

require_once dirname(__DIR__, 2)
    . '/includes/app_header.php';

?>

<div class="container-fluid py-4">

    <div class="d-flex flex-column flex-md-row
               justify-content-between
               align-items-md-center
               gap-3 mb-4">

        <div>

            <h1 class="h3 mb-1">
                Edit User Account
            </h1>

            <p class="text-muted mb-0">
                Update account information and access settings.
            </p>

        </div>

        <div>

            <a href="<?= e(
                base_url(
                    '/public/administration/users/view.php?id='
                    . (int) $account['user_id']
                )
            ) ?>" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i>
                Back to Account
            </a>

        </div>

    </div>

    <form method="post" action="<?= e(
        base_url(
            '/public/administration/users/update_process.php'
        )
    ) ?>">

        <?= Csrf::field() ?>

        <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">

        <div class="row g-4">

            <div class="col-12 col-xl-8">

                <div class="card shadow-sm border-0">

                    <div class="card-header bg-white py-3">

                        <h2 class="h5 mb-0">
                            Account Information
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label for="employee_number" class="form-label">
                                    Employee Number
                                </label>

                                <input type="text" class="form-control" id="employee_number" name="employee_number"
                                    value="<?= e(
                                        (string) (
                                            $account[
                                                'employee_number'
                                            ] ?? ''
                                        )
                                    ) ?>" maxlength="100" required>

                            </div>

                            <div class="col-md-6">

                                <label for="username" class="form-label">
                                    Username
                                </label>

                                <input type="text" class="form-control" id="username" name="username" value="<?= e(
                                    (string) (
                                        $account['username']
                                        ?? ''
                                    )
                                ) ?>" maxlength="100" required>

                            </div>

                            <div class="col-md-4">

                                <label for="first_name" class="form-label">
                                    First Name
                                </label>

                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e(
                                    (string) (
                                        $account['first_name']
                                        ?? ''
                                    )
                                ) ?>" maxlength="100" required>

                            </div>

                            <div class="col-md-4">

                                <label for="middle_name" class="form-label">
                                    Middle Name
                                </label>

                                <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?= e(
                                    (string) (
                                        $account['middle_name']
                                        ?? ''
                                    )
                                ) ?>" maxlength="100">

                            </div>

                            <div class="col-md-4">

                                <label for="last_name" class="form-label">
                                    Last Name
                                </label>

                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e(
                                    (string) (
                                        $account['last_name']
                                        ?? ''
                                    )
                                ) ?>" maxlength="100" required>

                            </div>

                            <div class="col-12">

                                <label for="email" class="form-label">
                                    Email
                                </label>

                                <input type="email" class="form-control" id="email" name="email" value="<?= e(
                                    (string) (
                                        $account['email']
                                        ?? ''
                                    )
                                ) ?>" maxlength="255" required>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-12 col-xl-4">

                <div class="card shadow-sm border-0">

                    <div class="card-header bg-white py-3">

                        <h2 class="h5 mb-0">
                            Access Settings
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <label class="form-label" for="role_id">
                                Role
                            </label>

                            <select class="form-select" id="role_id" name="role_id" required>

                                <?php foreach ($roles as $role): ?>

                                    <option value="<?= (int) $role['id'] ?>" <?= (
                                           (int) $account['role_id']
                                           === (int) $role['id']
                                       )
                                           ? 'selected'
                                           : '' ?>>
                                        <?= e(
                                            (string) $role['name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="mb-3">

                            <label class="form-label" for="department_id">
                                Department
                            </label>

                            <select class="form-select" id="department_id" name="department_id" required>

                                <?php foreach ($departments as $department): ?>

                                    <option value="<?= (int) $department['id'] ?>" <?= (
                                           (int) $account['department_id']
                                           === (int) $department['id']
                                       )
                                           ? 'selected'
                                           : '' ?>>
                                        <?= e(
                                            (string) $department['name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="mb-3">

                            <label class="form-label" for="position">
                                Position
                            </label>

                            <input type="text" class="form-control" id="position" name="position" value="<?= e(
                                (string) (
                                    $account['position']
                                    ?? ''
                                )
                            ) ?>" maxlength="150" required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label" for="account_status">
                                Account Status
                            </label>

                            <select class="form-select" id="account_status" name="account_status" required>

                                <option value="active" <?= (
                                    strtolower(
                                        (string) (
                                            $account[
                                                'account_status'
                                            ] ?? ''
                                        )
                                    ) === 'active'
                                )
                                    ? 'selected'
                                    : '' ?>>
                                    Active
                                </option>

                                <option value="inactive" <?= (
                                    strtolower(
                                        (string) (
                                            $account[
                                                'account_status'
                                            ] ?? ''
                                        )
                                    ) === 'inactive'
                                )
                                    ? 'selected'
                                    : '' ?>>
                                    Inactive
                                </option>

                            </select>

                        </div>

                        <div class="form-check mb-3">

                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
             <?= (
                 (int) (
                     $account['is_active']
                     ?? 0
                 ) === 1
             )
                 ? 'checked'
                 : '' ?>>

                            <label class="form-check-label" for="is_active">
                                Account is active
                            </label>

                        </div>

                        <div class="form-check">

                            <input class="form-check-input" type="checkbox" id="must_change_password"
                                name="must_change_password" value="1" <?= (
                                    (int) (
                                        $account[
                                            'must_change_password'
                                        ] ?? 0
                                    ) === 1
                                )
                                    ? 'checked'
                                    : '' ?>>

                            <label class="form-check-label" for="must_change_password">
                                Require password change
                            </label>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="d-flex justify-content-end
                   gap-2 mt-4">

            <a href="<?= e(
                base_url(
                    '/public/administration/users/view.php?id='
                    . (int) $account['user_id']
                )
            ) ?>" class="btn btn-outline-secondary">
                Cancel
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-save me-1"></i>
                Save Changes
            </button>

        </div>

    </form>

</div>

<?php
require_once dirname(__DIR__, 2)
    . '/includes/app_footer.php';
?>