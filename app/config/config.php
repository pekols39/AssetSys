<?php

declare(strict_types=1);

use Dotenv\Dotenv;

if (!defined('ASSETSYS_ROOT')) {
    define('ASSETSYS_ROOT', dirname(__DIR__, 2));
}

if (!isset($_ENV['APP_NAME'])) {
    $dotenv = Dotenv::createImmutable(ASSETSYS_ROOT);
    $dotenv->safeLoad();
}

define('APP_NAME', $_ENV['APP_NAME'] ?? 'Company Asset Management System');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'local');
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('APP_URL', rtrim($_ENV['APP_URL'] ?? 'http://localhost/AssetSys', '/'));

define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_DATABASE', $_ENV['DB_DATABASE'] ?? 'assetsys');
define('DB_USERNAME', $_ENV['DB_USERNAME'] ?? 'root');
define('DB_PASSWORD', $_ENV['DB_PASSWORD'] ?? '');

define(
    'SESSION_NAME',
    $_ENV['SESSION_NAME'] ?? 'ASSETSYS_SESSION'
);

define(
    'CSRF_TOKEN_NAME',
    $_ENV['CSRF_TOKEN_NAME'] ?? 'assetsys_csrf'
);

define(
    'UPLOAD_MAX_SIZE',
    (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760)
);