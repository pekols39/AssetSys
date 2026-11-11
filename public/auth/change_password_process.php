<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Helpers\Csrf;
use App\Models\User;
use App\Models\Setting;
use App\Middleware\AuthMiddleware;
use App\Services\RememberMeService;
use App\Services\AuditLogService;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
/**
 * Safely record password-change audit events.
 */
function logPasswordChangeEvent(
    string $action,
    string $description,
    string $result = 'success'
): void {
    try {
        (new AuditLogService())->log(
            action: $action,
            module: 'authentication',
            entityType: 'user',
            entityId: Auth::id(),
            description: $description,
            newValues: [
                'password_change_status' => $result,
            ],
            result: $result
        );
    } catch (\Throwable $e) {
        error_log(
            'Password change audit logging failed: '
            . $e->getMessage()
        );
    }
}

AuthMiddleware::handle();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$user = Auth::user();

if (!$user || !isset($user['user_id'])) {
    Auth::logout();

    header(
        'Location: ' . base_url('/public/login.php')
    );

    exit;
}

if ((int) ($user['must_change_password'] ?? 0) !== 1) {
    header(
        'Location: ' . base_url('/public/dashboard/')
    );
    exit;
}

$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (
    !Csrf::validate(
        is_string($csrfToken) ? $csrfToken : null
    )
) {
    http_response_code(419);
    exit('Your session has expired. Please refresh the page and try again.');
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$currentPassword = is_string($currentPassword)
    ? $currentPassword
    : '';

$newPassword = is_string($newPassword)
    ? $newPassword
    : '';

$confirmPassword = is_string($confirmPassword)
    ? $confirmPassword
    : '';

if (
    $currentPassword === ''
    || $newPassword === ''
    || $confirmPassword === ''
) {
    exit('All password fields are required.');
}

if ($newPassword !== $confirmPassword) {
    exit('The new password and confirmation do not match.');
}

if (strlen($newPassword) < 8) {
    exit('The new password must be at least 8 characters long.');
}

if ($newPassword === $currentPassword) {
    exit('The new password must be different from the current password.');
}

try {
    $userModel = new User();

    $dbUser = $userModel->findById(
        (int) $user['user_id']
    );

    if (!$dbUser) {
        Auth::logout();

        header(
            'Location: ' . base_url('/public/login.php')
        );

        exit;
    }

    $passwordHash = $dbUser['password_hash'] ?? null;

    if (
        !is_string($passwordHash)
        || $passwordHash === ''
        || !password_verify(
            $currentPassword,
            $passwordHash
        )
    ) {
        logPasswordChangeEvent(
            action: 'change_password_failed',
            description: 'Password change failed because the current password was incorrect.',
            result: 'failure'
        );

        exit('The current password is incorrect.');
    }

    $newPasswordHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    if (!is_string($newPasswordHash)) {
        throw new \RuntimeException(
            'Unable to secure the new password.'
        );
    }

    if (
        !$userModel->updatePassword(
            (int) $user['user_id'],
            $newPasswordHash
        )
    ) {
        throw new \RuntimeException(
            'Unable to update your password.'
        );
    }

    // Record the successful password change.
    // No password or password hash is included.
    logPasswordChangeEvent(
        action: 'change_password',
        description: 'User changed their password successfully.'
    );

    $rememberMeService = new RememberMeService();

    $rememberMeService->clearAllTokensForUser(
        (int) $user['user_id']
    );

    $settingModel = new Setting();

    $forceLogout = $settingModel->getInt(
        'force_logout_password_change',
        1
    );

    if ($forceLogout === 1) {
        Auth::logout();

        Session::start();

        Session::regenerate();

        Session::set(
            'password_changed_logout',
            true
        );

        header(
            'Location: ' . base_url('/public/login.php')
        );

        exit;
    }

    Session::set(
        'user.must_change_password',
        0
    );

    header(
        'Location: ' . base_url('/public/dashboard/')
    );

    exit;

} catch (\Throwable $e) {

    if (
        defined('APP_DEBUG')
        && APP_DEBUG === true
    ) {
        error_log(
            'AssetSys password change error: '
            . $e->getMessage()
        );
    }

    http_response_code(500);

    exit(
        'Unable to change your password. Please try again later.'
    );
}