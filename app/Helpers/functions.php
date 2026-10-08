<?php

declare(strict_types=1);

/**
 * ------------------------------------------------------------
 * Global Helper Functions
 * ------------------------------------------------------------
 */

if (!function_exists('e')) {
    /**
     * Escape HTML output.
     */
    function e(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('old')) {
    /**
     * Repopulate form fields.
     */
    function old(
        string $key,
        string $default = ''
    ): string {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to another page.
     */
    function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('asset')) {
    /**
     * Generate an application asset URL with
     * automatic cache busting based on file modification time.
     */
    function asset(string $path): string
    {
        $relativePath = ltrim($path, '/');

        $fullPath = ASSETSYS_ROOT
            . '/public/assets/'
            . $relativePath;

        $version = is_file($fullPath)
            ? filemtime($fullPath)
            : time();

        return APP_URL
            . '/public/assets/'
            . $relativePath
            . '?v='
            . $version;
    }
}

if (!function_exists('base_url')) {
    /**
     * Generate an application URL.
     */
    function base_url(string $path = ''): string
    {
        return APP_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('is_post')) {
    /**
     * Check request method.
     */
    function is_post(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('is_get')) {
    /**
     * Check request method.
     */
    function is_get(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
    }
}