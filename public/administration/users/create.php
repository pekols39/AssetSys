<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    Auth::logout();

    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('Access denied. Administrator access required.');
}

$db = \App\Core\Database::connection();

$financeDepartmentId = 3;

$departmentStmt = $db->prepare(
    'SELECT id, name
     FROM departments
     WHERE id = :department_id
       AND LOWER(TRIM(name)) = \'finance\'
       AND is_active = 1
     LIMIT 1'
);

$departmentStmt->execute([
    'department_id' => $financeDepartmentId,
]);

$financeDepartment = $departmentStmt->fetch();

if (!$financeDepartment) {
    http_response_code(500);
    exit('The active Finance department could not be found.');
}

$roleStmt = $db->query(
    'SELECT id, name
     FROM roles
     WHERE is_active = 1
       AND name IN (
           \'Asset Manager\',
           \'Property Custodian\'
       )
     ORDER BY
         CASE name
             WHEN \'Asset Manager\' THEN 1
             ELSE 2
         END'
);

$roles = $roleStmt->fetchAll();

$pageTitle = 'Add User Account';

require_once dirname(__DIR__, 2)
    . '/includes/app_header.php';

?>

<div class="container-fluid py-4">

    <div class="d-flex flex-column flex-md-row
                justify-content-between align-items-md-center
                gap-3 mb-4">

        <div>
            <h1 class="h3 mb-1">Add User Account</h1>

            <p class="text-muted mb-0">
                Create an account and assign its system role.
            </p>
        </div>

        <div>
            <a href="<?= e(
                base_url('/public/administration/users/index.php')
            ) ?>" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i>
                Back to User Accounts
            </a>
        </div>

    </div>

    <form method="post" action="<?= e(
        base_url('/public/administration/users/create_process.php')
    ) ?>" autocomplete="off">

        <?= Csrf::field() ?>

        <div class="row g-4">

            <div class="col-12 col-xl-8">

                <div class="card shadow-sm border-0">

                    <div class="card-header bg-white py-3">
                        <h2 class="h5 mb-0">Account Information</h2>
                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="employee_number" class="form-label">
                                    Employee Number
                                </label>

                                <input type="text" class="form-control" id="employee_number"
                                    value="Generated automatically" readonly aria-describedby="employee_number_help">

                                <div id="employee_number_help" class="form-text">
                                    The Finance employee number will be assigned automatically
                                    when the account is created.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="username" class="form-label">
                                    Username
                                </label>

                                <input type="text" class="form-control" id="username" name="username" maxlength="100"
                                    pattern="[A-Za-z0-9._-]+" autocomplete="off" required>

                                <div class="form-text">
                                    Letters, numbers, dots, underscores,
                                    and hyphens only.
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label for="first_name" class="form-label">
                                    First Name
                                </label>

                                <input type="text" class="form-control" id="first_name" name="first_name"
                                    maxlength="100" required>
                            </div>

                            <div class="col-md-4">
                                <label for="middle_name" class="form-label">
                                    Middle Name
                                </label>

                                <input type="text" class="form-control" id="middle_name" name="middle_name"
                                    maxlength="100">
                            </div>

                            <div class="col-md-4">
                                <label for="last_name" class="form-label">
                                    Last Name
                                </label>

                                <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100"
                                    required>
                            </div>

                            <div class="col-12">
                                <label for="email" class="form-label">
                                    Email Address
                                </label>

                                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                                    autocomplete="off" required>

                                <div class="form-text">
                                    Notifications for this account will be
                                    sent to this saved email address.
                                </div>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-12 col-xl-4">

                <div class="card shadow-sm border-0">

                    <div class="card-header bg-white py-3">
                        <h2 class="h5 mb-0">Access Settings</h2>
                    </div>

                    <div class="card-body">

                        <div class="mb-3">
                            <label for="role_id" class="form-label">
                                Role
                            </label>

                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Select a role</option>

                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= (int) $role['id'] ?>">
                                        <?= e((string) $role['name']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="department_display" class="form-label">
                                Department
                            </label>

                            <input type="text" class="form-control" id="department_display"
                                value="<?= e((string) $financeDepartment['name']) ?>" readonly>

                            <div class="form-text">
                                All accounts created here are assigned to Finance.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="position" class="form-label">
                                Position
                            </label>

                            <input type="text" class="form-control" id="position" name="position" maxlength="150"
                                required>
                        </div>

                        <div class="mb-3">
                            <label for="initial_password" class="form-label">
                                Initial Password
                            </label>

                            <input type="password" class="form-control" id="initial_password" name="initial_password"
                                minlength="12" maxlength="72" autocomplete="new-password" required>

                            <div class="form-text">
                                Use at least 12 characters. The account
                                holder must change this password at first
                                login.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">
                                Confirm Initial Password
                            </label>

                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                minlength="12" maxlength="72" autocomplete="new-password" required>
                        </div>

                        <div class="alert alert-info mb-0">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            The account will be created as active and
                            require a password change at first login.
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">

            <a href="<?= e(
                base_url('/public/administration/users/index.php')
            ) ?>" class="btn btn-outline-secondary">
                Cancel
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-user-plus me-1"></i>
                Create Account
            </button>

        </div>

    </form>

</div>

<?php
require_once dirname(__DIR__, 2)
    . '/includes/app_footer.php';
?>