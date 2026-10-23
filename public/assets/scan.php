<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrScanLog;
use App\Services\AssetQrCodeService;
use App\Services\AuthorizationService;
use App\Core\Auth;

// Require a valid authenticated session.
AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

// Preserve AssetSys's existing restricted-area permission.
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You are not authorized to view asset details.');
}

// Validate the opaque QR token.
$token = $_GET['token'] ?? '';

if (
    !is_string($token)
    || strlen($token) !== 64
    || !ctype_xdigit($token)
) {
    http_response_code(404);
    exit('QR code not found or inactive.');
}

try {
    $qrService = new AssetQrCodeService();
    $qrRecord = $qrService->findActiveByToken($token);

    if (!is_array($qrRecord)) {
        http_response_code(404);
        exit('QR code not found or inactive.');
    }

    $assetId = (int) ($qrRecord['asset_id'] ?? 0);

    // A valid token alone must never grant asset access.
    if (
        $assetId <= 0
        || !$authorization->canAccessAsset($assetId)
    ) {
        http_response_code(403);
        exit('You are not authorized to view this asset.');
    }

    $assetModel = new Asset();
    $asset = $assetModel->findById($assetId);

    if (!is_array($asset)) {
        http_response_code(404);
        exit('Asset not found.');
    }

    // Log this successful, authorized scan.
    $qrCodeId = (int) ($qrRecord['id'] ?? 0);
    $scannedBy = Auth::id() ?? 0;

    if ($qrCodeId <= 0 || $scannedBy <= 0) {
        throw new \RuntimeException(
            'Unable to identify the QR code or scanning user.'
        );
    }

    $scanLog = new AssetQrScanLog();

    if (!$scanLog->create($qrCodeId, $assetId, $scannedBy)) {
        throw new \RuntimeException(
            'Unable to record the QR scan.'
        );
    }

} catch (\Throwable $e) {
    error_log(
        'Asset QR scan failed: '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load this asset. Please try again.');
}


// Escape all database values before rendering HTML.
$escape = static fn(mixed $value): string =>
    htmlspecialchars(
        (string) ($value ?? 'Not specified'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

$pageTitle = 'Scanned Asset Details';

$fields = [
    'Asset Code' => $asset['asset_code'] ?? null,
    'Asset Tag' => $asset['asset_tag'] ?? null,
    'Asset Name' => $asset['asset_name'] ?? null,
    'Category' => $asset['category_name'] ?? null,
    'Serial Number' => $asset['serial_number'] ?? null,
    'Manufacturer' => $asset['manufacturer'] ?? null,
    'Model' => $asset['model'] ?? null,
    'Department' => $asset['department_name'] ?? null,
    'Location' => $asset['location_name'] ?? null,
    'Assigned To' => $asset['assigned_to_name'] ?? null,
    'Condition' => $asset['condition_name'] ?? null,
    'Status' => $asset['status_name'] ?? null,
    'Purchase Date' => $asset['purchase_date'] ?? null,
    'Description' => $asset['description'] ?? null,
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?></title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px 14px;
            background: #f4f7f7;
            color: #263238;
            font-family: Arial, Helvetica, sans-serif;
        }

        .container {
            max-width: 760px;
            margin: 0 auto;
        }

        .header {
            padding: 22px;
            background: #007472;
            color: #fff;
            border-radius: 12px 12px 0 0;
        }

        .header h1 {
            margin: 0 0 7px;
            font-size: 23px;
        }

        .header p {
            margin: 0;
            font-size: 14px;
            opacity: .95;
        }

        .content {
            padding: 22px;
            background: #fff;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
        }

        .asset-code {
            margin: 0 0 18px;
            color: #007472;
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            min-width: 0;
            padding-bottom: 12px;
            border-bottom: 1px solid #e8eeee;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .label {
            display: block;
            margin-bottom: 5px;
            color: #687777;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .value {
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            font-size: 15px;
        }

        .footer {
            margin-top: 20px;
            color: #687777;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 520px) {
            body {
                padding: 12px 8px;
            }

            .header,
            .content {
                padding: 17px;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>
    <main class="container">
        <header class="header">
            <h1>Asset Details</h1>
            <p>QR inventory identification</p>
        </header>

        <section class="content">
            <p class="asset-code">
                <?= $escape($asset['asset_code'] ?? 'Asset') ?>
            </p>

            <div class="details">
                <?php foreach ($fields as $label => $value): ?>
                    <div class="field<?= $label === 'Description' ? ' full' : '' ?>">
                        <span class="label"><?= $escape($label) ?></span>
                        <div class="value"><?= $escape($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="footer">
                Asset information is available only to authorized users.
                Scanning this QR code does not modify the asset.
            </p>
        </section>
    </main>
</body>

</html>