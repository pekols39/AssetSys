<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Services\AuthorizationService;
use Dompdf\Dompdf;
use Dompdf\Options;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();

    header(
        'Location: ' . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit(
        'Access denied. Depreciation reports are available to Asset Managers only.'
    );
}

$departmentId = (int) ($user['department_id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(403);
    exit(
        'Access denied. Your account is not assigned to a department.'
    );
}

$year = filter_var(
    $_GET['year'] ?? date('Y'),
    FILTER_VALIDATE_INT
);

$month = filter_var(
    $_GET['month'] ?? date('n'),
    FILTER_VALIDATE_INT
);

if (
    $year === false
    || $year < 1000
    || $year > 9999
    || $month === false
    || $month < 1
    || $month > 12
) {
    http_response_code(400);
    exit('Invalid depreciation report month or year.');
}

try {
    $reportService = new AssetReportService($departmentId);

    $entries = $reportService->getDepreciationReport(
        $year,
        $month
    );

    $totalDepreciation = 0.0;

    foreach ($entries as $entry) {
        $totalDepreciation +=
            (float) ($entry['depreciation_amount'] ?? 0);
    }

    $periodLabel = (
        new \DateTimeImmutable(
            sprintf('%04d-%02d-01', $year, $month)
        )
    )->format('F Y');

    $escape = static fn(mixed $value): string =>
        htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (
        !is_file($headerImagePath)
        || !is_readable($headerImagePath)
    ) {
        throw new \RuntimeException(
            'The company PDF header image is unavailable.'
        );
    }

    $headerImageData = base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $headerImageUri =
        'data:image/png;base64,' . $headerImageData;

    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Depreciation Report</title>
        <style>
            @page {
                margin: 0px 22px 22px 22px;
            }

            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 8px;
                color: #202b35;
            }

            .company-header {
                width: 100%;
                margin: 0 0 12px 0;
                padding: 0;
                line-height: 0;
            }

            .company-header img {
                display: block;
                width: calc(100% + 44px);
                height: auto;
                margin: 0 -22px;
                padding: 0;
            }

            h1 {
                margin: 0 0 5px;
                font-size: 18px;
                color: #147d70;
            }

            .subtitle {
                margin-bottom: 14px;
                color: #64748b;
                font-size: 9px;
            }

            .summary {
                margin-bottom: 14px;
                padding: 9px;
                background: #f1f7f6;
                border: 1px solid #d7e7e4;
            }

            .summary span {
                display: inline-block;
                margin-right: 22px;
                margin-bottom: 3px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }

            th {
                background: #147d70;
                color: #fff;
                font-weight: bold;
                text-align: left;
            }

            th, td {
                border: 1px solid #d9e1e7;
                padding: 4px;
                vertical-align: top;
                overflow-wrap: break-word;
            }

            tbody tr:nth-child(even) {
                background: #f7f9fa;
            }

            .number {
                text-align: right;
                white-space: nowrap;
            }

            .nowrap {
                white-space: nowrap;
            }

            tfoot th {
                background: #eaf2f1;
                color: #202b35;
            }

            .footer {
                margin-top: 12px;
                color: #64748b;
                font-size: 7px;
            }
        </style>
    </head>
    <body>
        <div class="company-header">
            <img
                src="' . $headerImageUri . '"
                alt="Company Header"
            >
        </div>

        <h1>Depreciation Report</h1>

        <div class="subtitle">
            Reporting Period: ' . $escape($periodLabel) . '
            &nbsp;|&nbsp;
            Generated: ' . $escape(date('F j, Y g:i A')) . '
        </div>

        <div class="summary">
            <span>
                <strong>Posted entries:</strong>
                ' . number_format(count($entries)) . '
            </span>

            <span>
                <strong>Total depreciation:</strong>
                PHP ' . number_format($totalDepreciation, 2) . '
            </span>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 7%;">Period Start</th>
                    <th style="width: 7%;">Period End</th>
                    <th style="width: 14%;">Asset</th>
                    <th style="width: 8%;">Category</th>
                    <th style="width: 10%;" class="number">Depreciation</th>
                    <th style="width: 11%;" class="number">Accumulated</th>
                    <th style="width: 11%;" class="number">Opening Book Value</th>
                    <th style="width: 11%;" class="number">Closing Book Value</th>
                    <th style="width: 11%;">Posted By</th>
                    <th style="width: 10%;">Posted At</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($entries as $entry) {
        $assetLabel = trim(
            (string) ($entry['asset_code'] ?? '')
            . ' — '
            . (string) ($entry['asset_name'] ?? '')
        );

        $html .= '<tr>
            <td class="nowrap">'
                . $escape($entry['period_start'] ?? '') .
            '</td>
            <td class="nowrap">'
                . $escape($entry['period_end'] ?? '') .
            '</td>
            <td>'
                . $escape($assetLabel) .
            '</td>
            <td>'
                . $escape($entry['category_name'] ?? '—') .
            '</td>
            <td class="number">'
                . number_format(
                    (float) ($entry['depreciation_amount'] ?? 0),
                    2
                ) .
            '</td>
            <td class="number">'
                . number_format(
                    (float) ($entry['accumulated_depreciation'] ?? 0),
                    2
                ) .
            '</td>
            <td class="number">'
                . number_format(
                    (float) ($entry['book_value_before'] ?? 0),
                    2
                ) .
            '</td>
            <td class="number">'
                . number_format(
                    (float) ($entry['book_value_after'] ?? 0),
                    2
                ) .
            '</td>
            <td>'
                . $escape(
                    trim((string) ($entry['posted_by_name'] ?? ''))
                    ?: 'Unknown'
                ) .
            '</td>
            <td>'
                . $escape($entry['posted_at'] ?? '') .
            '</td>
        </tr>';
    }

    if ($entries === []) {
        $html .= '
            <tr>
                <td colspan="10">
                    No posted depreciation entries found for this period.
                </td>
            </tr>';
    }

    $html .= '
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="number">
                        Total depreciation
                    </th>
                    <th class="number">
                        PHP ' . number_format($totalDepreciation, 2) . '
                    </th>
                    <th colspan="5"></th>
                </tr>
            </tfoot>
        </table>

        <div class="footer">
            AssetSys — Company Asset Management System
            &nbsp;|&nbsp;
            This report includes posted entries whose period-end date
            falls within the selected month.
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

    $filename = sprintf(
        'depreciation-report-%04d-%02d.pdf',
        $year,
        $month
    );

    if (headers_sent()) {
        throw new \RuntimeException(
            'Cannot export PDF because output has already started.'
        );
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;

} catch (\Throwable $exception) {
    error_log(
        'Depreciation PDF export failed: '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the depreciation PDF. '
        . 'Check the application error log.'
    );
}