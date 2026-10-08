<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Models\Setting;
use App\Middleware\AuthMiddleware;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

AuthMiddleware::handle();

/*
|--------------------------------------------------------------------------
| Administrator authorization
|--------------------------------------------------------------------------
*/

$user = Auth::user();

if (
    !$user
    || ($user['role_name'] ?? '') !== 'Administrator'
) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

/*
|--------------------------------------------------------------------------
| Require POST
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

/*
|--------------------------------------------------------------------------
| Validate CSRF token
|--------------------------------------------------------------------------
*/

$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}

/*
|--------------------------------------------------------------------------
| Validate submitted settings
|--------------------------------------------------------------------------
*/

$submittedSettings = $_POST['settings'] ?? null;

if (!is_array($submittedSettings)) {
    header('Location: ' . APP_URL . '/public/administration/index.php?error=1');
    exit;
}

$settingModel = new Setting();
$existingSettings = $settingModel->getAll();

$settingsByKey = [];

foreach ($existingSettings as $setting) {
    $settingsByKey[(string) $setting['setting_key']] = $setting;
}

$validatedSettings = [];

foreach ($submittedSettings as $key => $rawValue) {
    if (
        !is_string($key)
        || !isset($settingsByKey[$key])
        || !is_string($rawValue)
    ) {
        http_response_code(400);
        exit('Invalid setting submitted.');
    }

    $setting = $settingsByKey[$key];

    if ((int) $setting['is_editable'] !== 1) {
        http_response_code(403);
        exit('One or more submitted settings cannot be edited.');
    }

    $type = strtolower((string) $setting['setting_type']);
    $value = trim($rawValue);

    switch ($type) {
        case 'boolean':
            if (!in_array($value, ['0', '1'], true)) {
                http_response_code(422);
                exit('Invalid boolean setting value.');
            }
            break;

        case 'integer':
            if (
                !preg_match('/^-?\d+$/', $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false
            ) {
                http_response_code(422);
                exit('Invalid integer setting value.');
            }

            $integerValue = (int) $value;

            if ($integerValue < 1 || $integerValue > 100000) {
                http_response_code(422);
                exit('Integer setting is outside the allowed range.');
            }

            $value = (string) $integerValue;
            break;

        case 'decimal':
            if (
                !preg_match('/^\d+(?:\.\d{1,4})?$/', $value)
                || !is_numeric($value)
            ) {
                http_response_code(422);
                exit('Invalid decimal setting value.');
            }

            $decimalValue = (float) $value;

            if ($decimalValue <= 0 || $decimalValue > 1000000) {
                http_response_code(422);
                exit('Decimal setting is outside the allowed range.');
            }

            $value = rtrim(rtrim(number_format($decimalValue, 4, '.', ''), '0'), '.');
            break;

        case 'string':
            if ($value === '' || strlen($value) > 100) {
                http_response_code(422);
                exit('Invalid text setting value.');
            }

            /*
             * Validate known enumerated settings against an allowlist.
             */
            if ($key === 'currency' && !in_array($value, ['PHP', 'USD'], true)) {
                http_response_code(422);
                exit('Unsupported currency.');
            }

            if (
                $key === 'date_format'
                && !in_array($value, ['Y-m-d', 'd/m/Y', 'm/d/Y'], true)
            ) {
                http_response_code(422);
                exit('Unsupported date format.');
            }

            if (
                $key === 'default_depreciation_method'
                && !in_array($value, ['straight_line', 'declining_balance'], true)
            ) {
                http_response_code(422);
                exit('Unsupported depreciation method.');
            }
            break;

        default:
            http_response_code(422);
            exit('Unsupported setting type.');
    }

    $validatedSettings[$key] = $value;
}

/*
|--------------------------------------------------------------------------
| Save validated settings
|--------------------------------------------------------------------------
*/

try {
    $userId = Auth::id();

    if ($userId === null) {
        Auth::logout();
        header('Location: ' . APP_URL . '/public/login.php');
        exit;
    }

    foreach ($validatedSettings as $key => $value) {
        $settingModel->updateEditable(
            $key,
            $value,
            $userId
        );
    }
} catch (Throwable $exception) {
    error_log(
        'AssetSys settings update failed: '
        . $exception->getMessage()
    );

    header(
        'Location: '
        . APP_URL
        . '/public/administration/index.php?error=1'
    );
    exit;
}

header(
    'Location: '
    . APP_URL
    . '/public/administration/index.php?success=1'
);
exit;