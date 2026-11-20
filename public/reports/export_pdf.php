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

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => trim((string) ($_GET['category_id'] ?? '')),
    'status_id' => trim((string) ($_GET['status_id'] ?? '')),
    'condition_id' => trim((string) ($_GET['condition_id'] ?? '')),
    'location_id' => trim((string) ($_GET['location_id'] ?? '')),
    'finance_owner_department' => trim((string) ($_GET['finance_owner_department'] ?? '')),
    'finance_main_location' => trim((string) ($_GET['finance_main_location'] ?? '')),
    'finance_status' => trim((string) ($_GET['finance_status'] ?? '')),
    'finance_supplier' => trim((string) ($_GET['finance_supplier'] ?? '')),
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
    $reportService = new AssetReportService();
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
                font-size: 6.5px;
                color: #202b35;
            }

            .company-header {
                width: 100%;
                margin: 0 0 8px 0;
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
                padding: 3px;
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
            <span><strong>AssetSys purchase cost:</strong> PHP '
        . number_format($totalPurchaseCost, 2) . '</span>
            <span><strong>AssetSys current book value:</strong> PHP '
        . number_format($totalBookValue, 2) . '</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Location (Main)</th>
                    <th>Owner / Department</th>
                    <th>Category</th>
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th>FFE Number</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Supplier</th>
                    <th>PO Number</th>
                    <th>Price</th>
                    <th>Date Purchase</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($assets as $asset) {
        $html .= '<tr>
            <td>' . $escape($asset['finance_main_location'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_owner_department'] ?? '—') . '</td>
            <td>' . $escape($asset['category_name'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_item_id'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_item_name'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_ffe_number'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_code'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_status'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_supplier'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_po_number'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_price_text'] ?? '—') . '</td>
            <td>' . $escape($asset['finance_date_purchase_text'] ?? '—') . '</td>
        </tr>';
    }

    if ($assets === []) {
        $html .= '<tr><td colspan="12">No matching assets found.</td></tr>';
    }

    $html .= '
            </tbody>
        </table>
        <div class="footer">
            The table contains the 12 official inventory fields. Purchase Cost and Current Book Value in the summary are separate AssetSys accounting/depreciation metrics.
        </div>

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