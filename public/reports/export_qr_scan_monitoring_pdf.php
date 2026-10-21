<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Models\AssetQrScanLog;
use App\Services\AuthorizationService;
use Dompdf\Dompdf;
use Dompdf\Options;

AuthMiddleware::handle();

try {
    $user = Auth::user();

    if (!$user) {
        Auth::logout();
        header('Location: ' . base_url('/public/login.php'));
        exit;
    }

    $authorization = new AuthorizationService();

    if (!$authorization->canAccessAssetMaterialAreas()) {
        http_response_code(403);
        exit('You do not have permission to export QR scan monitoring records.');
    }

    $assetTag = trim((string) ($_GET['asset_tag'] ?? ''));
    $scanner = trim((string) ($_GET['scanner'] ?? ''));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));

    $isValidDate = static function (string $date): bool {
        if ($date === '') {
            return true;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $date)
        );

        return checkdate($month, $day, $year);
    };

    if (!$isValidDate($dateFrom) || !$isValidDate($dateTo)) {
        http_response_code(400);
        exit('Invalid date filter.');
    }

    if (
        $dateFrom !== ''
        && $dateTo !== ''
        && $dateFrom > $dateTo
    ) {
        http_response_code(400);
        exit('The start date cannot be later than the end date.');
    }

    $filters = [
        'asset_tag' => $assetTag,
        'scanner' => $scanner,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];

    // Fetch every matching record, not just the current page.
    $scanLog = new AssetQrScanLog();
    $scans = $scanLog->getAllScans($filters);

    $escape = static fn(mixed $value): string => htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (!is_file($headerImagePath) || !is_readable($headerImagePath)) {
        throw new RuntimeException('The company header image is unavailable.');
    }

    $headerImageUri = 'data:image/png;base64,'
        . base64_encode((string) file_get_contents($headerImagePath));

    $generatedAt = date('F j, Y g:i A');

    $filterDescriptions = [];

    if ($assetTag !== '') {
        $filterDescriptions[] = 'Asset Tag: ' . $assetTag;
    }

    if ($scanner !== '') {
        $filterDescriptions[] = 'Scanner: ' . $scanner;
    }

    if ($dateFrom !== '') {
        $filterDescriptions[] = 'From: ' . $dateFrom;
    }

    if ($dateTo !== '') {
        $filterDescriptions[] = 'To: ' . $dateTo;
    }

    $filterSummary = $filterDescriptions !== []
        ? implode(' | ', array_map($escape, $filterDescriptions))
        : 'All scan records';

    $html = '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>QR Scan Monitoring Report</title>
        <style>
            @page {
                size: A4 landscape;
                margin: 0 24px 24px 24px;
            }

            html,
            body {
                margin: 0;
                padding: 0;
            }

            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 9px;
                color: #202b35;
                padding: 0 18px 18px;
            }

            .company-header {
                width: calc(100% + 36px);
                margin: 0 -18px 12px;
                padding: 0;
                line-height: 0;
            }

            .company-header img {
                display: block;
                width: 100%;
                height: auto;
                margin: 0;
                padding: 0;
            }

            h1 {
                font-size: 19px;
                margin: 0 0 6px;
                color: #147d70;
            }

            .subtitle {
                color: #64748b;
                margin-bottom: 12px;
            }

            .summary {
                margin-bottom: 12px;
                padding: 9px;
                background: #f1f7f6;
                border: 1px solid #d7e7e4;
            }

            .summary strong {
                color: #147d70;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }

            th {
                background: #147d70;
                color: #ffffff;
                font-weight: bold;
                text-align: left;
            }

            th,
            td {
                border: 1px solid #d9e1e7;
                padding: 6px;
                vertical-align: top;
                overflow-wrap: break-word;
            }

            tbody tr:nth-child(even) {
                background: #f7f9fa;
            }

            .col-date {
                width: 22%;
            }

            .col-tag {
                width: 17%;
            }

            .col-asset {
                width: 27%;
            }

            .col-qr {
                width: 12%;
            }

            .col-user {
                width: 22%;
            }

            .empty {
                text-align: center;
                color: #64748b;
                padding: 14px;
            }

            .footer {
                margin-top: 12px;
                color: #64748b;
                font-size: 8px;
            }
        </style>
    </head>
    <body>
        <div class="company-header">
            <img src="' . $headerImageUri . '" alt="Company Header">
        </div>

        <h1>QR Scan Monitoring Report</h1>

        <div class="subtitle">
            Generated: ' . $escape($generatedAt) . '
            &nbsp; | &nbsp;
            Filters: ' . $filterSummary . '
        </div>

        <div class="summary">
            <strong>Total Recorded Scans:</strong>
            ' . number_format(count($scans)) . '
        </div>

        <table>
            <thead>
                <tr>
                    <th class="col-date">Scanned At</th>
                    <th class="col-tag">Asset Tag</th>
                    <th class="col-asset">Asset Name</th>
                    <th class="col-qr">QR Code ID</th>
                    <th class="col-user">Scanned By</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($scans as $scan) {
        $firstName = trim((string) ($scan['first_name'] ?? ''));
        $lastName = trim((string) ($scan['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $username = trim((string) ($scan['username'] ?? ''));

        if ($fullName !== '') {
            $scannedBy = $fullName;
        } elseif ($username !== '') {
            $scannedBy = $username;
        } elseif (($scan['scanned_by'] ?? null) === null) {
            $scannedBy = 'User unavailable';
        } else {
            $scannedBy = 'Unknown user';
        }

        $html .= '<tr>
            <td>' . $escape($scan['scanned_at'] ?? '') . '</td>
            <td>' . $escape($scan['asset_tag'] ?? '—') . '</td>
            <td>' . $escape($scan['asset_name'] ?? '—') . '</td>
            <td>' . $escape($scan['asset_qr_code_id'] ?? '') . '</td>
            <td>' . $escape($scannedBy) . '</td>
        </tr>';
    }

    if ($scans === []) {
        $html .= '<tr>
            <td colspan="5" class="empty">No matching scan records found.</td>
        </tr>';
    }

    $html .= '
            </tbody>
        </table>

        <div class="footer">
            AssetSys — Inventory Tracker
        </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);

    $pdf = new Dompdf($options);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();

    $filename = 'qr-scan-monitoring-' . date('Y-m-d') . '.pdf';

    $pdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;
} catch (Throwable $exception) {
    error_log(
        'QR scan monitoring PDF export failed: '
        . $exception->getMessage()
    );

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');

    exit(
        'Unable to generate the QR scan monitoring PDF. '
        . 'Check the application error log.'
    );
}
