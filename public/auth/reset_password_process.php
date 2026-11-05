<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Session;
use App\Helpers\Csrf;
use App\Models\User;
use App\Services\RememberMeService;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

/**
 * Redirect with a one-time SweetAlert2 message.
 */
function resetPasswordRedirect(
    string $icon,
    string $title,
    string $message,
    string $destination = '/public/login.php'
): never {
    Session::set('swal_alert', [
        'icon' => $icon,
        'title' => $title,
        'text' => $message,
    ]);

    header('Location: ' . base_url($destination));
    exit;
}

/**
 * Only POST requests are allowed.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    resetPasswordRedirect(
        'error',
        'Invalid Request',
        'Please submit the password reset form again.',
        '/public/reset_password.php'
    );
}

/**
 * Validate CSRF token.
 */
$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (
    !Csrf::validate(
        is_string($csrfToken) ? $csrfToken : null
    )
) {
    http_response_code(419);

    resetPasswordRedirect(
        'error',
        'Session Expired',
        'Your session has expired. Please request a new password-reset link.',
        '/public/reset_password.php'
    );
}

/**
 * Validate submitted values.
 */
$token = $_POST['token'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$token = is_string($token) ? $token : '';
$newPassword = is_string($newPassword) ? $newPassword : '';
$confirmPassword = is_string($confirmPassword) ? $confirmPassword : '';

if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    resetPasswordRedirect(
        'error',
        'Reset Link Unavailable',
        'This reset link is invalid, expired, or has already been used. Request a new link.',
        '/public/reset_password.php'
    );
}

if ($newPassword === '' || $confirmPassword === '') {
    resetPasswordRedirect(
        'warning',
        'Required Fields',
        'Please enter and confirm your new password.',
        '/public/reset_password.php?token=' . rawurlencode($token)
    );
}

if (strlen($newPassword) < 8) {
    resetPasswordRedirect(
        'warning',
        'Password Too Short',
        'Your password must be at least 8 characters long.',
        '/public/reset_password.php?token=' . rawurlencode($token)
    );
}

if (strlen($newPassword) > 72) {
    resetPasswordRedirect(
        'warning',
        'Password Too Long',
        'Your password exceeds the maximum allowed length.',
        '/public/reset_password.php?token=' . rawurlencode($token)
    );
}

if ($newPassword !== $confirmPassword) {
    if ($newPassword !== $confirmPassword) {
        resetPasswordRedirect(
            'error',
            'Passwords Do Not Match',
            'Please make sure both password fields match.',
            '/public/reset_password.php?token=' . rawurlencode($token)
        );
    }
}

$db = null;

try {
    $db = Database::connection();
    $db->beginTransaction();

    /**
     * Lock the token row so the same reset link cannot be used
     * by concurrent requests.
     */
    $stmt = $db->prepare(
        'SELECT id, user_id
         FROM password_reset_tokens
         WHERE token_hash = :token_hash
           AND expires_at > NOW()
           AND used_at IS NULL
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->execute([
        'token_hash' => hash('sha256', $token),
    ]);

    $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRecord) {
        $db->rollBack();

        resetPasswordRedirect(
            'error',
            'Reset Link Unavailable',
            'This reset link is invalid, expired, or has already been used. Request a new link.',
            '/public/reset_password.php'
        );
    }

    $userId = (int) $resetRecord['user_id'];
    $tokenId = (int) $resetRecord['id'];

    if ($userId <= 0 || $tokenId <= 0) {
        throw new RuntimeException('Invalid password reset record.');
    }

    /**
     * Hash the new password securely.
     */
    $passwordHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    if (!is_string($passwordHash)) {
        throw new RuntimeException('Password hashing failed.');
    }

    /**
     * Update the account password using the existing User model.
     */
    $userModel = new User();

    if (!$userModel->updatePassword($userId, $passwordHash)) {
        throw new RuntimeException('Password update failed.');
    }

    /**
     * Mark this reset token as used.
     */
    $stmt = $db->prepare(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE id = :id
           AND used_at IS NULL'
    );

    $stmt->execute([
        'id' => $tokenId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Reset token could not be consumed.');
    }

    /**
     * Invalidate all other reset tokens for this account.
     */
    $stmt = $db->prepare(
        'DELETE FROM password_reset_tokens
         WHERE user_id = :user_id
           AND id <> :token_id'
    );

    $stmt->execute([
        'user_id' => $userId,
        'token_id' => $tokenId,
    ]);

    /**
     * Remove Remember Me tokens so existing persistent sessions
     * cannot continue using the old credentials.
     */

    $rememberMeService = new RememberMeService();
    $rememberMeService->clearAllTokensForUser($userId);

    // Invalidate existing authenticated sessions for this account.
    $stmt = $db->prepare(
        'UPDATE users
     SET session_version = session_version + 1
     WHERE id = :user_id
     LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Could not invalidate existing user sessions.'
        );
    }

    $db->commit();

    /**
     * Show the success alert after redirecting to Sign In.
     */
    resetPasswordRedirect(
        'success',
        'Password Updated',
        'Your password has been reset successfully. You can now sign in with your new password.',
        '/public/login.php'
    );

} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'AssetSys reset-password processing error: '
            . $e->getMessage()
        );
    }

    resetPasswordRedirect(
        'error',
        'Unable to Reset Password',
        'We could not reset your password right now. Please try again or request a new reset link.'
    );
}
