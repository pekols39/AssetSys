<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Session;
use App\Services\AuthorizationService;
use App\Services\RememberMeService;

class AuthMiddleware
{
    public static function handle(): void
    {
        if (!Auth::check()) {
            $rememberMeService = new RememberMeService();

            $rememberMeService->attemptAutoLogin();
        }

        if (!Auth::check()) {
            self::rememberRequestedPage();
            header(
                'Location: ' . base_url('/public/login.php')
            );
            exit;
        }

        SessionTimeoutMiddleware::handle();

        if (!Auth::check()) {
            self::rememberRequestedPage();
            header(
                'Location: ' . base_url('/public/login.php')
            );
            exit;
        }

        $user = (new AuthorizationService())->currentUser();

        if (!$user) {
            Auth::logout();

            header(
                'Location: ' . base_url('/public/login.php')
            );
            exit;
        }

        // Reject sessions created before a password reset or other
        // event that increments the account's session version.
        $sessionUser = \App\Core\Session::get('user', []);

        $sessionVersion = is_array($sessionUser)
            ? ($sessionUser['session_version'] ?? null)
            : null;

        if (
            !is_numeric($sessionVersion)
            || (int) $sessionVersion !== (int) ($user['session_version'] ?? -1)
        ) {
            Auth::logout();

            header(
                'Location: ' . base_url('/public/login.php')
            );
            exit;
        }

        $currentPath = parse_url(
            $_SERVER['REQUEST_URI'] ?? '',
            PHP_URL_PATH
        );

        $changePasswordPath = parse_url(
            base_url('/public/auth/change_password.php'),
            PHP_URL_PATH
        );

        $changePasswordProcessPath = parse_url(
            base_url('/public/auth/change_password_process.php'),
            PHP_URL_PATH
        );

        if (
            (int) ($user['must_change_password'] ?? 0) === 1
            && $currentPath !== $changePasswordPath
            && $currentPath !== $changePasswordProcessPath
        ) {
            header(
                'Location: ' . base_url('/public/auth/change_password.php')
            );
            exit;
        }
    }

    /**
     * Preserve a same-site application page so the user can return after login.
     */
    private static function rememberRequestedPage(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($requestUri) || $requestUri === '') {
            return;
        }

        $parts = parse_url($requestUri);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $publicPrefix = rtrim((string) parse_url(base_url('/public/'), PHP_URL_PATH), '/') . '/';
        if (
            !is_string($path)
            || $publicPrefix === '/'
            || !str_starts_with($path, $publicPrefix)
        ) {
            return;
        }

        $query = is_array($parts) && isset($parts['query']) ? '?' . $parts['query'] : '';
        Session::set('intended_url', $path . $query);
    }
}