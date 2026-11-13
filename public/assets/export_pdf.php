<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\AuthorizationService;

use Dompdf\Dompdf;
use Dompdf\Options;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit('You do not have permission to export the asset register.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => (string) ($_GET['category_id'] ?? ''),
    'status_id' => (string) ($_GET['status_id'] ?? ''),
    'condition_id' => (string) ($_GET['condition_id'] ?? ''),
    'location_id' => (string) ($_GET['location_id'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'created_at'),
    'direction' => (string) ($_GET['direction'] ?? 'desc'),
];

function pdfEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function pdfWorkbookValue(mixed $value): string
{
    $text = trim((string) ($value ?? ''));
    return strtolower($text) === 'null' ? '' : $text;
}

try {
    $assetService = new AssetService();

    // Retrieve every matching asset, not just the current register page.
    $assets = [];
    $page = 1;
    $totalPages = 1;

    do {
        $result = $assetService->getPage(
            $filters,
            null,
            $page,
            100
        );

        $assets = array_merge($assets, $result['items']);
        $totalPages = (int) $result['total_pages'];
        $page++;
    } while ($page <= $totalPages);


    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (
        !is_file($headerImagePath)
        || !is_readable($headerImagePath)
    ) {
        throw new RuntimeException(
            'The company PDF header image is unavailable.'
        );
    }

    $headerImageData = base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $headerImageUri = 'data:image/png;base64,' . $headerImageData;


    $generatedAt = date('Y-m-d H:i:s');
    $totalAssets = count($assets);

    $totalPurchaseCost = 0.0;
    $totalBookValue = 0.0;

    foreach ($assets as $asset) {
        $totalPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $totalBookValue += (float) ($asset['current_book_value'] ?? 0);
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: A4 landscape;
        margin: 0 18px 18px 18px;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8px;
        color: #263238;
    }

    .company-header {
        width: 100%;
        margin: 0 0 12px;
        padding: 0;
        line-height: 0;
    }

    .company-header img {
        display: block;
        width: calc(100% + 36px);
        height: auto;
        margin: 0 -18px;
        padding: 0;
    }

    h1 {
        font-size: 18px;
        color: #007472;
        margin: 0 0 5px;
    }

    .subtitle {
        font-size: 9px;
        margin-bottom: 12px;
    }

    .summary {
        width: 100%;
        margin-bottom: 12px;
        border-collapse: collapse;
    }

    .summary td {
        border: 1px solid #d6e2e2;
        padding: 7px;
        background: #f1f8f8;
    }

    .summary strong {
        color: #007472;
        font-size: 10px;
    }

    table.inventory {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .inventory th {
        background: #007472;
        color: #ffffff;
        font-weight: bold;
        text-align: left;
    }

    .inventory th,
    .inventory td {
        border: 1px solid #d5dddd;
        padding: 4px;
        vertical-align: top;
        word-wrap: break-word;
    }

    .inventory tr:nth-child(even) td {
        background: #f5f8f8;
    }

    .money {
        text-align: right;
        white-space: nowrap;
    }

    .workbook-title {
        margin-top: 14px;
        margin-bottom: 5px;
        color: #007472;
        font-size: 11px;
    }

    table.workbook-inventory {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 6px;
    }

    .workbook-inventory th {
        background: #52636a;
        color: #ffffff;
        font-weight: bold;
        text-align: left;
    }

    .workbook-inventory th,
    .workbook-inventory td {
        border: 1px solid #d5dddd;
        padding: 3px;
        vertical-align: top;
        word-wrap: break-word;
    }

    .workbook-inventory tr:nth-child(even) td {
        background: #f5f8f8;
    }

    .footer {
        margin-top: 10px;
        font-size: 7px;
        color: #607070;
    }
</style>
</head>
<body>';

    $html .= '
        <div class="company-header">
            <img
                src="' . $headerImageUri . '"
                alt="Company Header"
            >
        </div>
    ';

    $html .= '<h1>Inventory Tracker</h1>';
    $html .= '<div class="subtitle">Asset Register Export<br>';
    $html .= 'Generated: ' . pdfEscape($generatedAt) . '<br>';
    $html .= 'Search: ' . pdfEscape($filters['search'] !== '' ? $filters['search'] : 'All assets');
    $html .= '</div>';

    $html .= '<table class="summary"><tr>';
    $html .= '<td>Total assets<br><strong>' . number_format($totalAssets) . '</strong></td>';
    $html .= '<td>Total purchase cost<br><strong>₱' . number_format($totalPurchaseCost, 2) . '</strong></td>';
    $html .= '<td>Total current book value<br><strong>₱' . number_format($totalBookValue, 2) . '</strong></td>';
    $html .= '</tr></table>';

    $html .= '<table class="inventory"><thead><tr>';
    $html .= '<th style="width:9%">Asset Code</th>';
    $html .= '<th style="width:9%">Asset Tag</th>';
    $html .= '<th style="width:9%">Serial Number</th>';
    $html .= '<th style="width:13%">Asset Name</th>';
    $html .= '<th style="width:9%">Category</th>';
    $html .= '<th style="width:7%">Status (AssetSys)</th>';
    $html .= '<th style="width:7%">Condition</th>';
    $html .= '<th style="width:9%">Department (AssetSys)</th>';
    $html .= '<th style="width:8%">Location (AssetSys)</th>';
    $html .= '<th style="width:9%">Assigned To</th>';
    $html .= '<th style="width:6%">Purchase Date (AssetSys)</th>';
    $html .= '<th style="width:7%">Purchase Cost (AssetSys)</th>';
    $html .= '<th style="width:7%">Book Value (AssetSys)</th>';
    $html .= '</tr></thead><tbody>';

    if ($assets === []) {
        $html .= '<tr><td colspan="13" style="text-align:center;">No assets found for the selected filters.</td></tr>';
    } else {
        foreach ($assets as $asset) {
            $assignedTo = trim(
                (string) ($asset['assigned_to_name'] ?? '')
            );

            if ($assignedTo === '') {
                $assignedTo = trim(
                    (string) ($asset['assigned_first_name'] ?? '') . ' ' .
                    (string) ($asset['assigned_last_name'] ?? '')
                );
            }

            $html .= '<tr>';
            $html .= '<td>' . pdfEscape($asset['asset_code'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['asset_tag'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['serial_number'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['asset_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['category_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['status_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['condition_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['department_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($asset['location_name'] ?? '') . '</td>';
            $html .= '<td>' . pdfEscape($assignedTo) . '</td>';
            $html .= '<td>' . pdfEscape($asset['purchase_date'] ?? '') . '</td>';
            $html .= '<td class="money">₱' . number_format((float) ($asset['purchase_cost'] ?? 0), 2) . '</td>';
            $html .= '<td class="money">₱' . number_format((float) ($asset['current_book_value'] ?? 0), 2) . '</td>';
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table>';

    $html .= '<h2 class="workbook-title">Original Workbook Values</h2>';
    $html .= '<table class="workbook-inventory"><thead><tr>';
    foreach ([
        'Asset Code',
        'LOCATION (Main)',
        'OWNER / DEPARTMENT',
        'Item ID',
        'Item name',
        'FFE NUMBER',
        'CODE',
        'Status',
        'SUPPLIER',
        'PO NUMBER',
        'Price',
        'DATE PURCHASE',
        'LOCATION',
    ] as $workbookHeader) {
        $html .= '<th>' . pdfEscape($workbookHeader) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    if ($assets === []) {
        $html .= '<tr><td colspan="13" style="text-align:center;">No original workbook values found.</td></tr>';
    } else {
        foreach ($assets as $asset) {
            $workbookValues = [
                $asset['asset_code'] ?? '',
                $asset['finance_main_location'] ?? '',
                $asset['finance_owner_department'] ?? '',
                $asset['finance_item_id'] ?? '',
                $asset['finance_item_name'] ?? '',
                $asset['finance_ffe_number'] ?? '',
                $asset['finance_code'] ?? '',
                $asset['finance_status'] ?? '',
                $asset['finance_supplier'] ?? '',
                $asset['finance_po_number'] ?? '',
                $asset['finance_price_text'] ?? '',
                $asset['finance_date_purchase_text'] ?? '',
                $asset['finance_location'] ?? '',
            ];

            $html .= '<tr>';
            foreach ($workbookValues as $workbookValue) {
                $html .= '<td>' . pdfEscape(pdfWorkbookValue($workbookValue)) . '</td>';
            }
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table>';
    $html .= '<div class="footer">Inventory Tracker — Asset Register and Original Workbook Values | Total records: ';
    $html .= number_format($totalAssets) . '</div>';
    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'inventory-tracker-assets-' . date('Y-m-d') . '.pdf';

    $dompdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;
} catch (Throwable $exception) {
    error_log(
        'Assets PDF export failed: ' . $exception->getMessage()
    );

    http_response_code(500);
    exit('Unable to export the asset register. Please check the application logs.');
}
