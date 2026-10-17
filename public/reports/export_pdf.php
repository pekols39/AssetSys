<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Core\Auth;
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
    exit('Access denied. Asset reports are available to Asset Managers only.');
}

$departmentId = (int) ($user['department_id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(403);
    exit('Access denied. Your account is not assigned to a department.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => trim((string) ($_GET['category_id'] ?? '')),
    'status_id' => trim((string) ($_GET['status_id'] ?? '')),
    'condition_id' => trim((string) ($_GET['condition_id'] ?? '')),
    'location_id' => trim((string) ($_GET['location_id'] ?? '')),
];

try {

    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (!is_file($headerImagePath) || !is_readable($headerImagePath)) {
        throw new RuntimeException('The company PDF header image is unavailable.');
    }

    $headerImageData = base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $headerImageUri = 'data:image/png;base64,' . $headerImageData;
    $reportService = new AssetReportService($departmentId);
    $assets = $reportService->getAssetInventory($filters);

    $totalPurchaseCost = 0.0;
    $totalBookValue = 0.0;

    foreach ($assets as $asset) {
        $totalPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $totalBookValue += (float) ($asset['current_book_value'] ?? 0);
    }

    $escape = static fn(mixed $value): string =>
        htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Asset Inventory Report</title>
        <style>
            @page {
                margin: 0 24px 24px 24px;
            }

            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 9px;
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
                width: calc(100% + 48px);
                height: auto;
                margin: 0 -24px;
                padding: 0;
            }

            h1 {
                font-size: 19px;
                margin: 0 0 6px;
                color: #147d70;
            }

            .subtitle {
                color: #64748b;
                margin-bottom: 16px;
            }

            .summary {
                margin-bottom: 16px;
                padding: 10px;
                background: #f1f7f6;
                border: 1px solid #d7e7e4;
            }

            .summary span {
                display: inline-block;
                margin-right: 22px;
                margin-bottom: 4px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th {
                background: #147d70;
                color: #ffffff;
                font-weight: bold;
                text-align: left;
            }

            th, td {
                border: 1px solid #d9e1e7;
                padding: 5px;
                vertical-align: top;
            }

            tbody tr:nth-child(even) {
                background: #f7f9fa;
            }

            .number {
                text-align: right;
                white-space: nowrap;
            }

            tfoot th {
                background: #eaf2f1;
                color: #202b35;
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
            <img
                src="' . $headerImageUri . '"
                alt="Company Header"
            >
        </div>

        <h1>Asset Inventory Report</h1>
        <div class="subtitle">
            Generated: ' . $escape(date('F j, Y g:i A')) . '
        </div>

        <div class="summary">
            <span><strong>Total assets:</strong> '
        . number_format(count($assets)) . '</span>
            <span><strong>Purchase cost:</strong> PHP '
        . number_format($totalPurchaseCost, 2) . '</span>
            <span><strong>Current book value:</strong> PHP '
        . number_format($totalBookValue, 2) . '</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Asset Code</th>
                    <th>Asset Name</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Condition</th>
                    <th>Location</th>
                    <th>Assigned To</th>
                    <th>Purchase Date</th>
                    <th class="number">Purchase Cost</th>
                    <th class="number">Book Value</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($assets as $asset) {
        $assignedName = trim(
            (string) ($asset['assigned_first_name'] ?? '')
            . ' '
            . (string) ($asset['assigned_last_name'] ?? '')
        );

        $html .= '<tr>
            <td>' . $escape($asset['asset_code'] ?? '') . '</td>
            <td>' . $escape($asset['asset_name'] ?? '') . '</td>
            <td>' . $escape($asset['category_name'] ?? '—') . '</td>
            <td>' . $escape($asset['status_name'] ?? '—') . '</td>
            <td>' . $escape($asset['condition_name'] ?? '—') . '</td>
            <td>' . $escape($asset['location_name'] ?? '—') . '</td>
            <td>' . $escape($assignedName !== '' ? $assignedName : 'Unassigned') . '</td>
            <td>' . $escape($asset['purchase_date'] ?? '—') . '</td>
            <td class="number">' . number_format((float) ($asset['purchase_cost'] ?? 0), 2) . '</td>
            <td class="number">' . number_format((float) ($asset['current_book_value'] ?? 0), 2) . '</td>
        </tr>';
    }

    if ($assets === []) {
        $html .= '<tr><td colspan="10">No matching assets found.</td></tr>';
    }

    $html .= '
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="8" class="number">Filtered Totals</th>
                    <th class="number">PHP ' . number_format($totalPurchaseCost, 2) . '</th>
                    <th class="number">PHP ' . number_format($totalBookValue, 2) . '</th>
                </tr>
            </tfoot>
        </table>

        <div class="footer">
            AssetSys — Company Asset Management System
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

    $filename = 'asset-inventory-' . date('Y-m-d') . '.pdf';

    $pdf->stream($filename, [
        'Attachment' => true,
    ]);
    exit;
} catch (Throwable $exception) {
    error_log(
        'Asset inventory PDF export failed: '
        . $exception->getMessage()
    );

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');

    exit('Unable to generate the asset inventory PDF. Check the application error log.');
}