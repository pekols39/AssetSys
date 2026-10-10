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

date_default_timezone_set('Asia/Manila');

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

define('MAIL_HOST', trim($_ENV['MAIL_HOST'] ?? ''));
define('MAIL_PORT', (int) ($_ENV['MAIL_PORT'] ?? 587));
define('MAIL_ENCRYPTION', strtolower(trim($_ENV['MAIL_ENCRYPTION'] ?? 'tls')));
define('MAIL_USERNAME', trim($_ENV['MAIL_USERNAME'] ?? ''));
define('MAIL_PASSWORD', $_ENV['MAIL_PASSWORD'] ?? '');
define('MAIL_FROM_ADDRESS', trim($_ENV['MAIL_FROM_ADDRESS'] ?? ''));
define(
    'MAIL_FROM_NAME',
    trim($_ENV['MAIL_FROM_NAME'] ?? APP_NAME)
);

define(
    'PASSWORD_RESET_TTL_MINUTES',
    max(5, min(60, (int) ($_ENV['PASSWORD_RESET_TTL_MINUTES'] ?? 30)))
);
