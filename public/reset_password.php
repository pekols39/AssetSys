<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Session;
use App\Helpers\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Retrieve and clear the one-time SweetAlert2 message.
$swalAlert = Session::get('swal_alert');
Session::remove('swal_alert');

if (!is_array($swalAlert)) {
    $swalAlert = null;
}

header('Referrer-Policy: no-referrer');

$pageTitle = 'Reset Password';
$pageStylesheet = 'css/login.css';

$token = $_GET['token'] ?? '';
$token = is_string($token) ? $token : '';

$tokenIsValidFormat = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$resetIsValid = false;
$serverError = false;

if ($tokenIsValidFormat) {
    try {
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT id
             FROM password_reset_tokens
             WHERE token_hash = :token_hash
               AND expires_at > NOW()
               AND used_at IS NULL
             LIMIT 1'
        );

        $stmt->execute([
            'token_hash' => hash('sha256', $token),
        ]);

        $resetIsValid = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (\Throwable $e) {
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            error_log(
                'AssetSys reset-password page error: '
                . $e->getMessage()
            );
        }

        http_response_code(500);
        $serverError = true;
        $resetIsValid = false;
    }
}

if (!$resetIsValid && !$serverError) {
    http_response_code(400);
}

require_once __DIR__ . '/includes/guest_header.php';
?>

<div class="login-container">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-lg-6">
                <div class="card login-card shadow-lg border-0">
                    <div class="login-form-wrapper">

                        <?php if ($resetIsValid): ?>

                            <h3>Reset Password</h3>

                            <p class="text-muted">
                                Choose a new password for your account.
                            </p>

                            <form
                                method="POST"
                                action="<?= e(APP_URL) ?>/public/auth/reset_password_process.php"
                                autocomplete="off"
                            >
                                <?= Csrf::field() ?>

                                <input
                                    type="hidden"
                                    name="token"
                                    value="<?= e($token) ?>"
                                >

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">
                                        New Password
                                    </label>

                                    <div class="password-field">
                                        <input
                                            type="password"
                                            class="form-control"
                                            id="new_password"
                                            name="new_password"
                                            minlength="8"
                                            maxlength="72"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-target="new_password"
                                            aria-label="Show new password"
                                            aria-pressed="false"
                                            title="Show password"
                                        >
                                            <svg
                                                class="eye-icon"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                aria-hidden="true"
                                            >
                                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                                                <circle cx="12" cy="12" r="3"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">
                                        Confirm New Password
                                    </label>

                                    <div class="password-field">
                                        <input
                                            type="password"
                                            class="form-control"
                                            id="confirm_password"
                                            name="confirm_password"
                                            minlength="8"
                                            maxlength="72"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-target="confirm_password"
                                            aria-label="Show confirmation password"
                                            aria-pressed="false"
                                            title="Show password"
                                        >
                                            <svg
                                                class="eye-icon"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                aria-hidden="true"
                                            >
                                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                                                <circle cx="12" cy="12" r="3"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <button class="btn btn-primary w-100" type="submit">
                                    Update Password
                                </button>
                            </form>

                        <?php else: ?>

                            <h3>
                                <?= $serverError
                                    ? 'Unable to Verify Reset Link'
                                    : 'Reset Link Unavailable'
                                ?>
                            </h3>

                            <p class="text-muted">
                                <?php if ($serverError): ?>
                                    We could not verify your reset link right now.
                                    Please try again later.
                                <?php else: ?>
                                    This reset link is invalid, expired,
                                    or has already been used. Request a new
                                    password-reset link to continue.
                                <?php endif; ?>
                            </p>

                            <?php if (!$serverError): ?>
                                <a
                                    class="btn btn-primary w-100"
                                    href="<?= e(APP_URL) ?>/public/forgot_password.php"
                                >
                                    Request a New Link
                                </a>
                            <?php endif; ?>

                        <?php endif; ?>

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

<style>
.password-field {
    position: relative;
}

.password-field .form-control {
    padding-right: 3rem;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 0.75rem;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    border: 0;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    border-radius: 0.375rem;
}

.password-toggle:hover {
    color: #007472;
}

.password-toggle:focus-visible {
    outline: 2px solid #007472;
    outline-offset: 2px;
}

.password-toggle .eye-icon {
    display: block;
    width: 1.25rem;
    height: 1.25rem;
    pointer-events: none;
}
</style>

<script>
document.querySelectorAll('.password-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target');
        const input = document.getElementById(targetId);

        if (!input) {
            return;
        }

        const shouldShow = input.type === 'password';

        input.type = shouldShow ? 'text' : 'password';

        button.setAttribute('aria-pressed', String(shouldShow));
        button.setAttribute(
            'aria-label',
            shouldShow
                ? 'Hide ' + (targetId === 'new_password'
                    ? 'new password'
                    : 'confirmation password')
                : 'Show ' + (targetId === 'new_password'
                    ? 'new password'
                    : 'confirmation password')
        );
        button.setAttribute(
            'title',
            shouldShow ? 'Hide password' : 'Show password'
        );

        button.innerHTML = shouldShow
            ? `<svg class="eye-icon" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <path d="M3 3l18 18"/>
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                    <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5
                        c6.4 0 10 7 10 7a16.6 16.6 0 0 1-4 4.8"/>
                    <path d="M6.2 6.2C3.5 8.1 2 12 2 12
                        s3.6 7 10 7a10 10 0 0 0 4-.8"/>
                </svg>`
            : `<svg class="eye-icon" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7
                        -3.6 7-10 7S2 12 2 12Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>`;
    });
});
</script>

<?php if ($swalAlert !== null): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        Swal.fire({
            icon: <?= json_encode(
                $swalAlert['icon'] ?? 'info',
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>,
            title: <?= json_encode(
                $swalAlert['title'] ?? 'Notice',
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>,
            text: <?= json_encode(
                $swalAlert['text'] ?? '',
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>,
            confirmButtonColor: '#007472'
        });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/guest_footer.php'; ?>
