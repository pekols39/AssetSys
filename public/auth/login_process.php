<?php

declare(strict_types=1);

use App\Helpers\Csrf;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\RememberMeService;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

/*
 * ------------------------------------------------------------
 * CSRF validation
 * ------------------------------------------------------------
 */

$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (
    !Csrf::validate(
        is_string($csrfToken) ? $csrfToken : null
    )
) {
    http_response_code(419);

    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please refresh the page and try again.',
    ]);

    exit;
}

/*
 * ------------------------------------------------------------
 * Input
 * ------------------------------------------------------------
 */

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

$username = is_string($username)
    ? trim($username)
    : '';

$password = is_string($password)
    ? $password
    : '';

/*
 * ------------------------------------------------------------
 * Basic validation
 * ------------------------------------------------------------
 */

if ($username === '' || $password === '') {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Please enter your username and password.',
    ]);

    exit;
}

/*
 * ------------------------------------------------------------
 * Authentication
 * ------------------------------------------------------------
 */

try {

    $authService = new AuthService();

    $user = $authService->login(
        $username,
        $password
    );

    $rememberMe = $_POST['remember'] ?? null;

    if (
        $rememberMe === '1'
    ) {
        $rememberMeService = new RememberMeService();

        $rememberMeService->createToken(
            (int) $user['user_id']
        );
    }

    $roleName = $user['role_name'] ?? '';

    $redirect = match (true) {
        $roleName === 'Administrator' =>
            base_url('/public/administration/'),

        ($user['username'] ?? '') === 'manager'
        && $roleName === 'Asset Manager' =>
            base_url('/public/dashboard/'),

        default => base_url('/public/login.php'),
    };

    if (
        (int) ($user['must_change_password'] ?? 0) === 1
    ) {
        $redirect = base_url('/public/auth/change_password.php');
    }

    $intendedUrl = $_POST['intended_url'] ?? Session::get('intended_url', '');
    if (
        (int) ($user['must_change_password'] ?? 0) !== 1
        && is_string($intendedUrl)
        && $intendedUrl !== ''
    ) {
        $parts = parse_url($intendedUrl);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (
            is_string($path)
            && str_starts_with(
                $path,
                rtrim((string) parse_url(base_url('/public/'), PHP_URL_PATH), '/') . '/'
            )
            && !str_starts_with($path, '//')
            && !str_contains($path, '\\')
            && !isset($parts['scheme'])
            && !isset($parts['host'])
        ) {
            $redirect = $intendedUrl;
        }
    }
    Session::remove('intended_url');

    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'redirect' => $redirect,
    ]);

    exit;

} catch (\RuntimeException $e) {

    /*
     * Expected authentication failures.
     *
     * AuthService uses RuntimeException for:
     * - invalid credentials
     * - inactive accounts
     * - locked accounts
     * - failed-login lockout
     */

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

    exit;

} catch (\Throwable $e) {

    /*
     * Unexpected application/database errors.
     * Never expose internal details to the browser.
     */

    if (
        defined('APP_DEBUG')
        && APP_DEBUG === true
    ) {
        error_log(
            'AssetSys login error: '
            . $e->getMessage()
        );
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to process your login request. Please try again later.',
    ]);

    exit;
}