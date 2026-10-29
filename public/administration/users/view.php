<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AdministrationUserService;
use App\Services\AuthorizationService;

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

$firstName = trim(
    (string) (
        $account['first_name'] ?? ''
    )
);

$middleName = trim(
    (string) (
        $account['middle_name'] ?? ''
    )
);

$lastName = trim(
    (string) (
        $account['last_name'] ?? ''
    )
);

$fullName = trim(
    $firstName
    . ' '
    . $middleName
    . ' '
    . $lastName
);

$accountStatus = strtolower(
    trim(
        (string) (
            $account['account_status'] ?? ''
        )
    )
);

$isActive =
    (int) (
        $account['is_active'] ?? 0
    ) === 1;

$mustChangePassword =
    (int) (
        $account['must_change_password'] ?? 0
    ) === 1;

$lastLogin = $account['last_login_at'] ?? null;

$pageTitle = 'View User Account';

$pageScript = 'js/administration-users.js';

$accountCreated = (
    isset($_GET['success'])
    && $_GET['success'] === '1'
);

require_once dirname(__DIR__, 2)
    . '/includes/app_header.php';

?>

<div class="container-fluid py-4">

    <?php if ($accountCreated): ?>

        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i>
            User account created successfully. The account is active
            and must change its initial password at first login.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

    <?php endif; ?>

    <div class="d-flex flex-column flex-md-row
               justify-content-between
               align-items-md-center
               gap-3 mb-4">

        <div>

            <h1 class="h3 mb-1">
                User Account
            </h1>

            <p class="text-muted mb-0">
                View account information and access status.
            </p>

        </div>

        <div>

            <div class="d-flex gap-2">

                <a href="<?= e(
                    base_url(
                        '/public/administration/users/'
                    )
                ) ?>" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-1"></i>
                    Back to User Accounts
                </a>

                <a href="<?= e(
                    base_url(
                        '/public/administration/users/edit.php?id='
                        . (int) $account['user_id']
                    )
                ) ?>" class="btn btn-primary">
                    <i class="fa-solid fa-pen-to-square me-1"></i>
                    Edit Account
                </a>

            </div>

        </div>

    </div>

    <div class="row g-4">

        <div class="col-12 col-xl-8">

            <div class="card shadow-sm border-0">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        Account Information
                    </h2>

                </div>

                <div class="card-body">

                    <div class="row g-4">

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Employee Number
                            </label>

                            <div class="fw-semibold">
                                <?= e(
                                    (string) (
                                        $account[
                                            'employee_number'
                                        ] ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Full Name
                            </label>

                            <div class="fw-semibold">
                                <?= e(
                                    $fullName !== ''
                                    ? $fullName
                                    : '—'
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Username
                            </label>

                            <div>
                                <?= e(
                                    (string) (
                                        $account['username']
                                        ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Email
                            </label>

                            <div>
                                <?= e(
                                    (string) (
                                        $account['email']
                                        ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Role
                            </label>

                            <div>
                                <?= e(
                                    (string) (
                                        $account['role_name']
                                        ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Department
                            </label>

                            <div>
                                <?= e(
                                    (string) (
                                        $account[
                                            'department_name'
                                        ] ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Position
                            </label>

                            <div>
                                <?= e(
                                    (string) (
                                        $account[
                                            'position'
                                        ] ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label text-muted small">
                                Account Status
                            </label>

                            <div>

                                <?php if (
                                    $isActive
                                    && $accountStatus === 'active'
                                ): ?>

                                    <span class="badge text-bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge
                                               text-bg-secondary">
                                        <?= e(
                                            $accountStatus !== ''
                                            ? ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $accountStatus
                                                )
                                            )
                                            : 'Inactive'
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-xl-4">

            <div class="card shadow-sm border-0">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        Account Security
                    </h2>

                </div>

                <div class="card-body">

                    <div class="mb-4">

                        <div class="text-muted small mb-1">
                            Password Status
                        </div>

                        <?php if (
                            $mustChangePassword
                        ): ?>

                            <span class="badge text-bg-warning">
                                Change Required
                            </span>

                        <?php else: ?>

                            <span class="badge
                                       text-bg-light
                                       text-dark">
                                Current
                            </span>

                        <?php endif; ?>

                    </div>

                    <div>

                        <div class="text-muted small mb-1">
                            Last Login
                        </div>

                        <?php if (
                            is_string($lastLogin)
                            && $lastLogin !== ''
                        ): ?>

                            <div>
                                <?= e(
                                    date(
                                        'M d, Y h:i A',
                                        strtotime($lastLogin)
                                    )
                                ) ?>
                            </div>

                        <?php else: ?>

                            <div class="text-muted">
                                Never
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
require_once dirname(__DIR__, 2)
    . '/includes/app_footer.php';
?>