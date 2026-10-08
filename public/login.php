<?php

declare(strict_types=1);

use App\Core\Session;
use App\Helpers\Csrf;
use App\Core\Auth;
use App\Services\RememberMeService;

$pageTitle = 'Login';
$pageStylesheet = 'css/login.css';
$pageScript = 'js/login.js';

require_once __DIR__ . '/includes/guest_header.php';

if (!Auth::check()) {
    $rememberMeService = new RememberMeService();

    $rememberMeService->attemptAutoLogin();
}

if (Auth::check()) {
    header(
        'Location: ' . APP_URL . '/public/dashboard/'
    );
    exit;
}
?>

<div class="login-container">

    <div class="container">

        <div class="row justify-content-center align-items-center min-vh-100">

            <div class="col-lg-10">

                <div class="card login-card shadow-lg border-0">

                    <div class="row g-0">

                        <!-- LEFT SIDE -->

                        <div class="col-lg-6 login-left">

                            <div class="login-left-content">

                                <img src="<?= e(asset('images/mmgcci-logo-white.png')) ?>" class="login-logo"
                                    alt="Company Logo">

                                <p class="text-white fw-bold fs-5 helpdesk-title">
                                    Asset Management System
                                </p>

                                <p>
                                    Manage company assets, assignments,
                                    maintenance, repairs, transfers,
                                    and asset history in one centralized platform.
                                </p>

                            </div>

                        </div>

                        <!-- RIGHT SIDE -->

                        <div class="col-lg-6 login-form-col">

                            <div class="login-form-wrapper">

                                <h3>
                                    Welcome Back
                                </h3>

                                <p class="text-muted">
                                    Sign in to continue.
                                </p>

                                <?php if (Session::has('session_expired')): ?>

                                    <div class="alert alert-warning alert-dismissible fade show">

                                        <i class="fa-solid fa-clock me-2"></i>

                                        Your session has expired due to inactivity.
                                        Please sign in again.

                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                                    </div>

                                    <?php Session::remove('session_expired'); ?>

                                <?php endif; ?>

                                <?php if (Session::has('password_changed_logout')): ?>

                                    <div class="alert alert-info alert-dismissible fade show">

                                        <i class="fa-solid fa-key me-2"></i>

                                        Your password has been changed.
                                        Please sign in again.

                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                                    </div>

                                    <?php Session::remove('password_changed_logout'); ?>

                                <?php endif; ?>

                                <form id="loginForm" method="POST" autocomplete="off">

                                    <?= Csrf::field() ?>

                                    <!-- USERNAME -->

                                    <div class="mb-3">

                                        <label for="username" class="form-label">
                                            Username
                                        </label>

                                        <div class="input-group">

                                            <span class="input-group-text">
                                                <i class="fa-solid fa-user"></i>
                                            </span>

                                            <input type="text" class="form-control" id="username" name="username"
                                                placeholder="Enter your username" autocomplete="username" required>

                                        </div>

                                    </div>

                                    <!-- PASSWORD -->

                                    <div class="mb-3">

                                        <label for="password" class="form-label">
                                            Password
                                        </label>

                                        <div class="input-group">

                                            <span class="input-group-text">
                                                <i class="fa-solid fa-lock"></i>
                                            </span>

                                            <input type="password" class="form-control" id="password" name="password"
                                                placeholder="Enter your password" autocomplete="current-password"
                                                required>

                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                                                aria-label="Show password">
                                                <i class="fa-solid fa-eye" id="toggleIcon"></i>
                                            </button>

                                        </div>

                                    </div>

                                    <!-- REMEMBER / FORGOT -->

                                    <div class="d-flex justify-content-between mb-4">

                                        <div class="form-check">

                                            <input class="form-check-input" type="checkbox" id="remember"
                                                name="remember" value="1">

                                            <label class="form-check-label" for="remember">
                                                Remember Me
                                            </label>

                                        </div>

                                        <a href="forgot_password.php" class="forgot-link">
                                            Forgot Password?
                                        </a>

                                    </div>

                                    <!-- LOGIN BUTTON -->

                                    <button class="btn btn-primary w-100" id="loginBtn" type="submit">

                                        <span id="btnText">
                                            Sign In
                                        </span>

                                        <span id="btnLoader"
                                            class="spinner-border spinner-border-sm ms-2 d-none"></span>

                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/includes/guest_footer.php'; ?>