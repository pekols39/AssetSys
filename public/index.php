<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Location: ' . base_url('/public/login.php'));
exit;