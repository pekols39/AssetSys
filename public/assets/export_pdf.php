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

/**
 * Render one limited group of assets. Keeping each Dompdf instance small
 * prevents the complete register from exhausting PHP memory.
 */
function renderAssetPdfChunk(
    array $assets,
    string $headerImageUri,
    string $generatedAt,
    string $searchLabel,
    int $partNumber,
    int $totalParts,
    int $totalAssets
): string {
    $chunkPurchaseCost = 0.0;
    $chunkBookValue = 0.0;

    foreach ($assets as $asset) {
        $chunkPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $chunkBookValue += (float) ($asset['current_book_value'] ?? 0);
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { size: A4 landscape; margin: 0 18px 18px 18px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #263238; }
    .company-header { width: 100%; margin: 0 0 12px; padding: 0; line-height: 0; }
    .company-header img { display: block; width: calc(100% + 36px); height: auto; margin: 0 -18px; padding: 0; }
    h1 { font-size: 18px; color: #007472; margin: 0 0 5px; }
    .subtitle { font-size: 9px; margin-bottom: 12px; }
    .summary { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
    .summary td { border: 1px solid #d6e2e2; padding: 7px; background: #f1f8f8; }
    .summary strong { color: #007472; font-size: 10px; }
    table.inventory, table.workbook-inventory { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .inventory th { background: #007472; color: #fff; font-weight: bold; text-align: left; }
    .inventory th, .inventory td { border: 1px solid #d5dddd; padding: 4px; vertical-align: top; word-wrap: break-word; }
    .inventory tr:nth-child(even) td, .workbook-inventory tr:nth-child(even) td { background: #f5f8f8; }
    .money { text-align: right; white-space: nowrap; }
    .workbook-title { margin-top: 14px; margin-bottom: 5px; color: #007472; font-size: 11px; }
    table.workbook-inventory { font-size: 6px; }
    .workbook-inventory th { background: #52636a; color: #fff; font-weight: bold; text-align: left; }
    .workbook-inventory th, .workbook-inventory td { border: 1px solid #d5dddd; padding: 3px; vertical-align: top; word-wrap: break-word; }
    .footer { margin-top: 10px; font-size: 7px; color: #607070; }
</style>
</head>
<body>';

    $html .= '<div class="company-header"><img src="' . $headerImageUri . '" alt="Company Header"></div>';
    $html .= '<h1>Inventory Tracker</h1>';
    $html .= '<div class="subtitle">Asset Register Export — Part '
        . $partNumber . ' of ' . $totalParts . '<br>';
    $html .= 'Generated: ' . pdfEscape($generatedAt) . '<br>';
    $html .= 'Search: ' . pdfEscape($searchLabel) . '<br>';
    $html .= 'Records in this part: ' . number_format(count($assets))
        . ' | Total matching records: ' . number_format($totalAssets) . '</div>';

    $html .= '<table class="summary"><tr>';
    $html .= '<td>Assets in this part<br><strong>' . number_format(count($assets)) . '</strong></td>';
    $html .= '<td>Purchase cost in this part<br><strong>₱' . number_format($chunkPurchaseCost, 2) . '</strong></td>';
    $html .= '<td>Current book value in this part<br><strong>₱' . number_format($chunkBookValue, 2) . '</strong></td>';
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
            $assignedTo = trim((string) ($asset['assigned_to_name'] ?? ''));

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
    $html .= '<div class="footer">Inventory Tracker — Asset Register and Original Workbook Values | Part '
        . $partNumber . ' of ' . $totalParts . ' | Total matching records: '
        . number_format($totalAssets) . '</div>';
    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $output = $dompdf->output();

    unset($dompdf, $options, $html);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    return $output;
}

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(
            'The PHP ZIP extension is not enabled. Enable extension=zip in your XAMPP php.ini, restart Apache, and try again.'
        );
    }

    $assetService = new AssetService();

    // Fetch only one group at a time; never keep the full register in memory.
    $chunkSize = 100;
    $firstPage = $assetService->getPage($filters, null, 1, $chunkSize);
    $totalAssets = (int) $firstPage['total'];
    $totalParts = max(1, (int) ceil($totalAssets / $chunkSize));

    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (!is_file($headerImagePath) || !is_readable($headerImagePath)) {
        throw new RuntimeException('The company PDF header image is unavailable.');
    }

    $headerImageUri = 'data:image/png;base64,' . base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $generatedAt = date('Y-m-d H:i:s');
    $searchLabel = $filters['search'] !== '' ? $filters['search'] : 'All assets';

    $temporaryZip = tempnam(sys_get_temp_dir(), 'assets_pdf_');
    if ($temporaryZip === false) {
        throw new RuntimeException('Unable to create a temporary export file.');
    }

    $zip = new ZipArchive();
    $zipResult = $zip->open($temporaryZip, ZipArchive::OVERWRITE);

    if ($zipResult !== true) {
        @unlink($temporaryZip);
        throw new RuntimeException('Unable to initialize the PDF ZIP archive.');
    }

    try {
        for ($part = 1; $part <= $totalParts; $part++) {
            $result = $part === 1
                ? $firstPage
                : $assetService->getPage($filters, null, $part, $chunkSize);

            $pdfBytes = renderAssetPdfChunk(
                $result['items'],
                $headerImageUri,
                $generatedAt,
                $searchLabel,
                $part,
                $totalParts,
                $totalAssets
            );

            $partFilename = sprintf(
                'asset-register-part-%02d-of-%02d.pdf',
                $part,
                $totalParts
            );

            if (!$zip->addFromString($partFilename, $pdfBytes)) {
                throw new RuntimeException('Unable to add a generated PDF part to the ZIP archive.');
            }

            unset($pdfBytes, $result);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    } finally {
        $zip->close();
    }

    $filename = 'inventory-tracker-assets-' . date('Y-m-d') . '.zip';

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporaryZip));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($temporaryZip);
    @unlink($temporaryZip);
    exit;
} catch (Throwable $exception) {
    error_log('Assets PDF ZIP export failed: ' . $exception->getMessage());

    http_response_code(500);
    exit(
        'Unable to export the asset register as PDF files. '
        . 'Please check the application logs and confirm the PHP ZIP extension is enabled.'
    );
}
