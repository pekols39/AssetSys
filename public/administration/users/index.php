<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AdministrationUserService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
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

$userService = new AdministrationUserService();
$users = $userService->getAll();

$pageTitle = 'User Accounts';

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
                User Accounts
            </h1>

            <p class="text-muted mb-0">
                Manage system user accounts and access status.
            </p>

        </div>

    </div>

    <div class="card shadow-sm border-0">

        <div class="card-header bg-white
                   d-flex justify-content-between
                   align-items-center py-3">

            <div>

                <h2 class="h5 mb-1">
                    User Accounts
                </h2>

                <p class="text-muted small mb-0">
                    <?= number_format(count($users)) ?>
                    account(s)
                </p>

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover
                           align-middle mb-0">

                    <thead class="table-light">

                        <tr>

                            <th scope="col">
                                Employee No.
                            </th>

                            <th scope="col">
                                Name
                            </th>

                            <th scope="col">
                                Username
                            </th>

                            <th scope="col">
                                Email
                            </th>

                            <th scope="col">
                                Role
                            </th>

                            <th scope="col">
                                Department
                            </th>

                            <th scope="col">
                                Position
                            </th>

                            <th scope="col">
                                Status
                            </th>

                            <th scope="col">
                                Password
                            </th>

                            <th scope="col">
                                Last Login
                            </th>

                            <th scope="col" class="text-end">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($users)): ?>

                            <tr>

                                <td colspan="11" class="text-center
                                           text-muted py-5">
                                    No user accounts found.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($users as $account): ?>

                                <?php

                                $firstName = trim(
                                    (string) (
                                        $account['first_name']
                                        ?? ''
                                    )
                                );

                                $middleName = trim(
                                    (string) (
                                        $account['middle_name']
                                        ?? ''
                                    )
                                );

                                $lastName = trim(
                                    (string) (
                                        $account['last_name']
                                        ?? ''
                                    )
                                );

                                $fullName = trim(
                                    $firstName
                                    . ' '
                                    . $middleName
                                    . ' '
                                    . $lastName
                                );

                                $accountStatus =
                                    strtolower(
                                        trim(
                                            (string) (
                                                $account[
                                                    'account_status'
                                                ] ?? ''
                                            )
                                        )
                                    );

                                $isActive =
                                    (int) (
                                        $account['is_active']
                                        ?? 0
                                    ) === 1;

                                $mustChangePassword =
                                    (int) (
                                        $account[
                                            'must_change_password'
                                        ] ?? 0
                                    ) === 1;

                                ?>

                                <tr>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account[
                                                    'employee_number'
                                                ] ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <strong>
                                            <?= e(
                                                $fullName !== ''
                                                ? $fullName
                                                : '—'
                                            ) ?>
                                        </strong>

                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account['username']
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account['email']
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account['role_name']
                                                ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account[
                                                    'department_name'
                                                ] ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $account[
                                                    'position'
                                                ] ?? '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <?php if (
                                            $isActive
                                            && $accountStatus
                                            === 'active'
                                        ): ?>

                                            <span class="badge
                                                       text-bg-success">
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

                                    </td>

                                    <td>

                                        <?php if (
                                            $mustChangePassword
                                        ): ?>

                                            <span class="badge
                                                       text-bg-warning">
                                                Change Required
                                            </span>

                                        <?php else: ?>

                                            <span class="badge
                                                       text-bg-light
                                                       text-dark">
                                                Current
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php
                                        $lastLogin = $account[
                                            'last_login_at'
                                        ] ?? null;
                                        ?>

                                        <?php if (
                                            is_string($lastLogin)
                                            && $lastLogin !== ''
                                        ): ?>

                                            <?= e(
                                                date(
                                                    'M d, Y h:i A',
                                                    strtotime(
                                                        $lastLogin
                                                    )
                                                )
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                Never
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end">

                                        <a href="<?= e(
                                            base_url(
                                                '/public/administration/users/view.php?id='
                                                . (int) $account['user_id']
                                            )
                                        ) ?>" class="btn btn-sm btn-outline-secondary" title="View Account">
                                            <i class="fa-solid fa-eye me-1"></i>
                                            View
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<?php
require_once dirname(__DIR__, 2)
    . '/includes/app_footer.php';
?>