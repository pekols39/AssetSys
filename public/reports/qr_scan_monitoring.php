<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Models\AssetQrScanLog;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();

    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit('Access denied. QR scan monitoring is available to Asset Managers only.');
}


$requestedPage = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

if ($requestedPage === false || $requestedPage < 1) {
    $requestedPage = 1;
}

$perPage = 10;

$assetTag = trim((string) ($_GET['asset_tag'] ?? ''));
$scanner = trim((string) ($_GET['scanner'] ?? ''));

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

/**
 * Accept only real calendar dates in YYYY-MM-DD format.
 */
$validateDate = static function (string $value): bool {
    if ($value === '') {
        return true;
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = \DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || (
            $errors['warning_count'] === 0
            && $errors['error_count'] === 0
        ))
        && $date->format('Y-m-d') === $value;
};

if (!$validateDate($dateFrom) || !$validateDate($dateTo)) {
    http_response_code(400);
    exit('Invalid date filter. Please use valid dates.');
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    http_response_code(400);
    exit('The start date cannot be after the end date.');
}

$filters = [
    'asset_tag' => $assetTag,
    'scanner' => $scanner,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

try {
    $scanLog = new AssetQrScanLog();

    $totalScans = $scanLog->countScans($filters);
    $totalPages = max(1, (int) ceil($totalScans / $perPage));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $perPage;

    $scans = $scanLog->getRecentScans($perPage, $offset, $filters);
} catch (\Throwable $e) {
    error_log(
        'QR scan monitoring failed: '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load QR scan monitoring. Please try again.');
}

$firstScan = $totalScans > 0 ? $offset + 1 : 0;
$lastScan = min($offset + $perPage, $totalScans);

$pageStart = max(1, min($page - 2, $totalPages - 4));
$pageEnd = min($totalPages, $pageStart + 4);

$pageUrl = static fn(int $targetPage): string =>
    base_url(
        '/public/reports/qr_scan_monitoring.php?'
        . http_build_query([
            'page' => $targetPage,
            'asset_tag' => $assetTag,
            'scanner' => $scanner,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ])
    );

$pageTitle = 'QR Scan Monitoring';
$pageStylesheet = 'css/reports.css';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">
            Reports &amp; Analytics
        </div>

        <h1 class="h3 mb-1">QR Scan Monitoring</h1>

        <p class="text-muted mb-0">
            Review recorded QR scans, the associated assets, and who scanned them.
        </p>
    </div>
</div>


<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Filter Scan History</h2>
    </div>

    <div class="card-body">
        <form method="GET" action="<?= e(base_url('/public/reports/qr_scan_monitoring.php')) ?>">
            <div class="row g-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="asset_tag" class="form-label">Asset Tag</label>
                    <input
                        type="text"
                        class="form-control"
                        id="asset_tag"
                        name="asset_tag"
                        value="<?= e($assetTag) ?>"
                        maxlength="100"
                        placeholder="Search asset tag"
                    >
                </div>

                <div class="col-12 col-md-6 col-xl-3">
                    <label for="scanner" class="form-label">Scanned By</label>
                    <input
                        type="text"
                        class="form-control"
                        id="scanner"
                        name="scanner"
                        value="<?= e($scanner) ?>"
                        maxlength="150"
                        placeholder="Name or username"
                    >
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input
                        type="date"
                        class="form-control"
                        id="date_from"
                        name="date_from"
                        value="<?= e($dateFrom) ?>"
                    >
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input
                        type="date"
                        class="form-control"
                        id="date_to"
                        name="date_to"
                        value="<?= e($dateTo) ?>"
                    >
                </div>

                <div class="col-12 col-xl-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-filter me-1"></i>
                        Filter
                    </button>

                    <a
                        href="<?= e(base_url('/public/reports/qr_scan_monitoring.php')) ?>"
                        class="btn btn-outline-secondary"
                    >
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Total Recorded Scans
                </div>

                <div class="h3 mb-0">
                    <?= e(number_format($totalScans)) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0">Scan History</h2>

        <span class="text-muted small">
            Showing <?= e(number_format($firstScan)) ?>
            – <?= e(number_format($lastScan)) ?>
            of <?= e(number_format($totalScans)) ?>
        </span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Scanned At</th>
                        <th scope="col">Asset Tag</th>
                        <th scope="col">Asset Name</th>
                        <th scope="col">QR Code ID</th>
                        <th scope="col">Scanned By</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($scans === []): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <i class="fa-solid fa-qrcode fa-2x text-muted mb-3"></i>

                                <p class="mb-1">No QR scans recorded yet.</p>

                                <p class="text-muted small mb-0">
                                    Authorized scans will appear here.
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($scans as $scan): ?>
                            <?php
                            $scannerName = trim(
                                (string) ($scan['first_name'] ?? '')
                                . ' '
                                . (string) ($scan['last_name'] ?? '')
                            );

                            if ($scannerName === '') {
                                $scannerName = (string) (
                                    $scan['username'] ?? 'Unknown user'
                                );
                            }
                            ?>
                            <tr>
                                <td>
                                    <?= e((string) ($scan['scanned_at'] ?? '')) ?>
                                </td>

                                <td>
                                    <?= e((string) ($scan['asset_tag'] ?? '')) ?>
                                </td>

                                <td>
                                    <?= e((string) ($scan['asset_name'] ?? '')) ?>
                                </td>

                                <td>
                                    <?= e((string) ($scan['asset_qr_code_id'] ?? '')) ?>
                                </td>

                                <td>
                                    <?php if ($scan['scanned_by'] === null): ?>
                                        <span class="text-muted">User unavailable</span>
                                    <?php else: ?>
                                        <?= e($scannerName) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav aria-label="QR scan history pages">
                <ul class="pagination pagination-sm flex-wrap mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <?php if ($page > 1): ?>
                            <a class="page-link" href="<?= e($pageUrl($page - 1)) ?>">
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="page-link">Previous</span>
                        <?php endif; ?>
                    </li>

                    <?php for ($number = $pageStart; $number <= $pageEnd; $number++): ?>
                        <li class="page-item <?= $number === $page ? 'active' : '' ?>">
                            <?php if ($number === $page): ?>
                                <span class="page-link" aria-current="page">
                                    <?= e((string) $number) ?>
                                </span>
                            <?php else: ?>
                                <a class="page-link" href="<?= e($pageUrl($number)) ?>">
                                    <?= e((string) $number) ?>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <?php if ($page < $totalPages): ?>
                            <a class="page-link" href="<?= e($pageUrl($page + 1)) ?>">
                                Next
                            </a>
                        <?php else: ?>
                            <span class="page-link">Next</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>
