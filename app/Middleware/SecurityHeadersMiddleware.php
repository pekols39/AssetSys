<?php

declare(strict_types=1);

namespace App\Middleware;

class SecurityHeadersMiddleware
{
    public static function handle(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        if (
            isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        ) {
            header(
                'Strict-Transport-Security: max-age=31536000; includeSubDomains'
            );
        }
    }
}