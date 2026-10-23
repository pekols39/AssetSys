<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrCode;
use App\Services\AssetQrCodeService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to manage asset QR codes.');
}

$assetId = filter_var(
    $_GET['asset_id'] ?? $_POST['asset_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($assetId === false || $assetId === null || $assetId <= 0) {
    http_response_code(400);
    exit('A valid asset ID is required.');
}

if (!$authorization->canAccessAsset((int) $assetId)) {
    http_response_code(403);
    exit('You do not have permission to access this asset.');
}

$assetModel = new Asset();
$asset = $assetModel->findById((int) $assetId);

if (!is_array($asset)) {
    http_response_code(404);
    exit('Asset not found.');
}

$qrModel = new AssetQrCode();
$errorMessage = null;
$successMessage = null;

if (($_GET['printed'] ?? '') === '1') {
    $successMessage = 'The NIIMBOT print command completed. Check the label to confirm it printed correctly.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);
        $errorMessage = 'Your security token is invalid or expired. Refresh the page and try again.';
    } else {
        try {
            $service = new AssetQrCodeService();
            $service->createForAsset((int) $asset['id']);

            $successMessage = 'The asset QR identity is ready.';
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            $errorMessage = $e->getMessage();
        } catch (\RuntimeException $e) {
            http_response_code(403);
            $errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            error_log(
                'Asset QR management failed for asset ID '
                . (int) $asset['id']
                . ': '
                . get_class($e)
            );

            http_response_code(500);
            $errorMessage = 'Unable to create the QR identity. Please try again or contact the administrator.';
        }
    }
}

$qrRecord = $qrModel->findByAssetId((int) $asset['id']);
$hasActiveQr = is_array($qrRecord)
    && (int) ($qrRecord['is_active'] ?? 0) === 1;

$pageTitle = 'Asset QR Identity';
$pageStylesheet = 'css/view.css';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<style>
    .asset-qr-preview {
        display: block;
        width: 220px;
        max-width: 100%;
        height: auto;
        image-rendering: pixelated;
    }

    .asset-qr-label {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 2mm;
        width: 50mm;
        height: 30mm;
        padding: 2mm;
        box-sizing: border-box;
        overflow: hidden;
        background: #fff;
        color: #000;
        font-family: Arial, Helvetica, sans-serif;
    }

    .asset-qr-label .label-qr {
        display: block;
        flex: 0 0 24mm;
        width: 24mm;
        height: 24mm;
        object-fit: contain;
    }

    .asset-qr-label .label-details {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        overflow-wrap: anywhere;
    }

    .asset-qr-label .label-title {
        margin: 0 0 2mm;
        font-size: 8pt;
        line-height: 1.15;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .asset-qr-label .label-code {
        margin: 0 0 1mm;
        font-size: 6.5pt;
        line-height: 1.15;
        overflow-wrap: anywhere;
    }

    .asset-qr-label .label-tag {
        margin: 0;
        font-size: 6pt;
        line-height: 1.1;
        overflow-wrap: anywhere;
    }

    @media screen {
        .asset-qr-label {
            margin: 16px auto;
            border: 1px dashed #aaa;
        }
    }

    @media print {
        @page {
            size: 50mm 30mm;
            margin: 0;
        }

        html,
        body {
            width: 50mm !important;
            height: 30mm !important;
            min-width: 0 !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            background: #fff !important;
        }

        body * {
            visibility: hidden !important;
        }

        #assetQrPrintLabel,
        #assetQrPrintLabel * {
            visibility: visible !important;
        }

        #assetQrPrintLabel {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 50mm !important;
            height: 30mm !important;
            margin: 0 !important;
            padding: 2mm !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-sizing: border-box !important;
            gap: 2mm !important;
            overflow: hidden !important;
            break-inside: avoid !important;
        }

        #assetQrPrintLabel .label-qr {
            flex: 0 0 24mm !important;
            width: 24mm !important;
            height: 24mm !important;
        }

        #assetQrPrintLabel .label-details {
            min-width: 0 !important;
            overflow: hidden !important;
        }

        #assetQrPrintLabel .label-title {
            font-size: 8pt !important;
        }

        #assetQrPrintLabel .label-code {
            font-size: 6.5pt !important;
        }

        #assetQrPrintLabel .label-tag {
            font-size: 6pt !important;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
    <div>
        <div class="text-muted small mb-1">Asset Management</div>
        <h1 class="h3 mb-1">Asset QR Identity</h1>
        <p class="text-muted mb-0">
            Manage and print the permanent QR identity for this asset.
        </p>
    </div>

    <a href="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>
        Back to Asset
    </a>
</div>

<div class="card border-0 shadow-sm no-print">
    <div class="card-body">
        <h2 class="h5 mb-3">Asset Details</h2>

        <dl class="row mb-0">
            <dt class="col-sm-3">Asset Code</dt>
            <dd class="col-sm-9"><?= e($asset['asset_code'] ?? '') ?></dd>

            <dt class="col-sm-3">Asset Name</dt>
            <dd class="col-sm-9"><?= e($asset['asset_name'] ?? '') ?></dd>

            <dt class="col-sm-3">Asset Tag</dt>
            <dd class="col-sm-9"><?= e($asset['asset_tag'] ?? '—') ?></dd>
        </dl>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4 no-print">
    <div class="card-body">
        <h2 class="h5 mb-3">QR Identity</h2>

        <?php if ($successMessage !== null): ?>
            <div class="alert alert-success" role="status">
                <?= e($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <div class="alert alert-danger" role="alert">
                <?= e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($qrRecord)): ?>
            <p class="mb-2">
                A QR identity already exists for this asset.
            </p>

            <p class="text-muted mb-3">
                Status:
                <strong><?= $hasActiveQr ? 'Active' : 'Inactive' ?></strong>
            </p>

            <?php if ($hasActiveQr): ?>
                <div class="text-center">
                    <img src="<?= e(base_url('/public/assets/qr-image.php?asset_id=' . (int) $asset['id'])) ?>"
                        alt="QR code for asset <?= e($asset['asset_code'] ?? '') ?>" class="asset-qr-preview" width="320"
                        height="320">

                    <div class="mt-3">
                        <form method="POST" action="<?= e(base_url('/public/assets/print-qr-label.php')) ?>" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-print me-1"></i>
                                Print Label on NIIMBOT
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0" role="alert">
                    This QR identity is inactive and cannot be printed.
                    Reactivation must be handled through an authorized workflow.
                </div>
            <?php endif; ?>

        <?php else: ?>
            <p class="text-muted">
                This asset does not have a QR identity yet.
                Create one to prepare it for QR label generation.
            </p>

            <form method="POST" action="<?= e(base_url('/public/assets/qr.php?asset_id=' . (int) $asset['id'])) ?>">
                <?= Csrf::field() ?>

                <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-qrcode me-1"></i>
                    Create QR Identity
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasActiveQr): ?>
    <section id="assetQrPrintLabel" class="asset-qr-label" aria-label="Printable NIIMBOT asset QR label">
        <img src="<?= e(base_url('/public/assets/qr-image.php?asset_id=' . (int) $asset['id'])) ?>" alt="Asset QR code"
            class="label-qr" width="320" height="320">

        <div class="label-details">
            <p class="label-title">
                <?= e($asset['asset_name'] ?? 'Unnamed Asset') ?>
            </p>

            <p class="label-code">
                Code: <?= e($asset['asset_code'] ?? '—') ?>
            </p>

            <?php if (!empty($asset['asset_tag'])): ?>
                <p class="label-tag">
                    Tag: <?= e($asset['asset_tag']) ?>
                </p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>