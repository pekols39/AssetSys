<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Helpers\Csrf;

$pageTitle = 'Forgot Password';
$pageStylesheet = 'css/login.css';

require_once __DIR__ . '/includes/guest_header.php';

$message = Session::get('password_reset_request_message');

if (!is_string($message)) {
    $message = '';
}

Session::remove('password_reset_request_message');
?>

<div class="login-container">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-lg-6">
                <div class="card login-card shadow-lg border-0">
                    <div class="login-form-wrapper">

                        <h3>Forgot Password?</h3>

                        <p class="text-muted">
                            Enter the email address associated with your account.
                            If an eligible account exists, you will receive
                            instructions to reset your password.
                        </p>

                        <?php if ($message !== ''): ?>
                            <div class="alert alert-info" role="status">
                                <?= e($message) ?>
                            </div>
                        <?php endif; ?>

                        <form
                            method="POST"
                            action="<?= e(APP_URL) ?>/public/auth/forgot_password_process.php"
                            autocomplete="on"
                        >
                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    Email Address
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="email"
                                    name="email"
                                    placeholder="Enter your email address"
                                    autocomplete="email"
                                    maxlength="150"
                                    required
                                >
                            </div>

                            <button
                                class="btn btn-primary w-100"
                                type="submit"
                            >
                                Send Reset Instructions
                            </button>
                        </form>

                        <div class="text-center mt-4">
                            <a href="<?= e(APP_URL) ?>/public/login.php">
                                Back to Sign In
                            </a>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/guest_footer.php'; ?>