<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\RememberMeService;

$rememberMeService = new RememberMeService();

$rememberMeService->clearToken();

Auth::logout();

header(
    'Location: ' . APP_URL . '/public/login.php'
);

exit;