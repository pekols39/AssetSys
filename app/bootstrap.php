<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Helpers/functions.php';

use App\Core\Session;
use App\Middleware\SecurityHeadersMiddleware;

SecurityHeadersMiddleware::handle();
Session::start();