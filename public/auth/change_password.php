<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

AuthMiddleware::handle();

$user = Auth::user();

if (!$user) {
    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if ((int) ($user['must_change_password'] ?? 0) !== 1) {
    header(
        'Location: ' . base_url('/public/dashboard/')
    );
    exit;
}

$pageTitle = 'Change Password';
$pageStylesheet = 'css/login.css';

require_once dirname(__DIR__) . '/includes/guest_header.php';
?>

<div class="login-container">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-lg-6">

                <div class="card login-card shadow-lg border-0">
                    <div class="login-form-wrapper">

                        <div class="text-center mb-4">
                            <i class="fa-solid fa-key fa-3x text-teal mb-3"></i>

                            <h3>Change Password</h3>

                            <p class="text-muted mb-0">
                                You must change your password before continuing.
                            </p>
                        </div>

                        <form method="POST" action="<?= e(base_url('/public/auth/change_password_process.php')) ?>">

                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="current_password" class="form-label">
                                    Current Password
                                </label>

                                <input type="password" class="form-control" id="current_password"
                                    name="current_password" autocomplete="current-password" required>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">
                                    New Password
                                </label>

                                <input type="password" class="form-control" id="new_password" name="new_password"
                                    autocomplete="new-password" required>
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">
                                    Confirm New Password
                                </label>

                                <input type="password" class="form-control" id="confirm_password"
                                    name="confirm_password" autocomplete="new-password" required>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa-solid fa-key me-2"></i>
                                Change Password
                            </button>

                        </form>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/guest_footer.php'; ?>