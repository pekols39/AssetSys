<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
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
            header(
                'Location: ' . APP_URL . '/public/login.php'
            );
            exit;
        }

        SessionTimeoutMiddleware::handle();

        if (!Auth::check()) {
            header(
                'Location: ' . APP_URL . '/public/login.php'
            );
            exit;
        }

        $user = (new AuthorizationService())->currentUser();

        if (!$user) {
            Auth::logout();

            header(
                'Location: ' . APP_URL . '/public/login.php'
            );
            exit;
        }

        $currentPath = parse_url(
            $_SERVER['REQUEST_URI'] ?? '',
            PHP_URL_PATH
        );

        $changePasswordPath = parse_url(
            APP_URL . '/public/auth/change_password.php',
            PHP_URL_PATH
        );

        $changePasswordProcessPath = parse_url(
            APP_URL . '/public/auth/change_password_process.php',
            PHP_URL_PATH
        );

        if (
            (int) ($user['must_change_password'] ?? 0) === 1
            && $currentPath !== $changePasswordPath
            && $currentPath !== $changePasswordProcessPath
        ) {
            header(
                'Location: ' . APP_URL . '/public/auth/change_password.php'
            );
            exit;
        }
    }
}