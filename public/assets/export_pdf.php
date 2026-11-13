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

function renderAssetPdf(
    array $assets,
    string $headerImageUri,
    string $generatedAt,
    string $searchLabel,
    int $partNumber,
    int $totalParts,
    int $totalAssets
): string {
    $partPurchaseCost = 0.0;
    $partBookValue = 0.0;

    foreach ($assets as $asset) {
        $partPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $partBookValue += (float) ($asset['current_book_value'] ?? 0);
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
    $html .= '<td>Purchase cost in this part<br><strong>₱' . number_format($partPurchaseCost, 2) . '</strong></td>';
    $html .= '<td>Current book value in this part<br><strong>₱' . number_format($partBookValue, 2) . '</strong></td>';
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
    $html .= '<th style="width:9%">Assigned Employee</th>';
    $html .= '<th style="width:6%">Purchase Date (AssetSys)</th>';
    $html .= '<th style="width:7%">Purchase Cost (AssetSys)</th>';
    $html .= '<th style="width:7%">Book Value (AssetSys)</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($assets as $asset) {
        $assignedTo = trim((string) ($asset['assigned_to_name'] ?? ''));

        if ($assignedTo === '') {
            $assignedTo = trim(
                (string) ($asset['assigned_first_name'] ?? '') . ' ' .
                (string) ($asset['assigned_last_name'] ?? '')
            );
        }
        if ($assignedTo === '') {
            $assignedTo = strtolower((string) ($asset['status_name'] ?? '')) === 'in use'
                ? 'Not applicable (location-based)'
                : 'Unassigned';
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

    if ($assets === []) {
        $html .= '<tr><td colspan="13" style="text-align:center;">No assets found for the selected filters.</td></tr>';
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
    ] as $header) {
        $html .= '<th>' . pdfEscape($header) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($assets as $asset) {
        $values = [
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
        foreach ($values as $value) {
            $html .= '<td>' . pdfEscape(pdfWorkbookValue($value)) . '</td>';
        }
        $html .= '</tr>';
    }

    if ($assets === []) {
        $html .= '<tr><td colspan="13" style="text-align:center;">No original workbook values found.</td></tr>';
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
    $assetService = new AssetService();

    // Keep each HTTP request limited to one small PDF.
    $chunkSize = 50;
    $summary = $assetService->getPage($filters, null, 1, $chunkSize);
    $totalAssets = (int) $summary['total'];
    $totalParts = max(1, (int) ceil($totalAssets / $chunkSize));

    $part = filter_var(
        $_GET['part'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($part === false || $part === null) {
        $query = $_GET;
        unset($query['part']);
        $baseUrl = base_url('/public/assets/export_pdf.php');
        $queryString = http_build_query($query);
        $partBaseUrl = $baseUrl . ($queryString !== '' ? '?' . $queryString . '&' : '?');

        $title = 'Export Asset Register PDF';
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . pdfEscape($title) . '</title>'
            . '<style>body{font-family:Arial,sans-serif;margin:32px;color:#263238}'
            . 'h2{color:#007472}.parts{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}'
            . 'a{display:block;padding:12px;border:1px solid #ccd8d8;border-radius:6px;color:#007472;text-decoration:none}'
            . 'a:hover{background:#f1f8f8}.muted{color:#607070}</style></head><body>'
            . '<h2>Asset Register PDF Export</h2>'
            . '<p>Total matching assets: <strong>' . number_format($totalAssets) . '</strong></p>'
            . '<p class="muted">Each PDF contains up to ' . $chunkSize
            . ' assets and includes both AssetSys records and Original Workbook Values.</p>'
            . '<div class="parts">';

        for ($number = 1; $number <= $totalParts; $number++) {
            $start = (($number - 1) * $chunkSize) + 1;
            $end = min($number * $chunkSize, $totalAssets);
            $html .= '<a href="' . pdfEscape($partBaseUrl . 'part=' . $number) . '">'
                . 'Download PDF Part ' . $number . '<br><small>Assets '
                . number_format($start) . '–' . number_format($end)
                . '</small></a>';
        }

        $html .= '</div></body></html>';

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    if ($part > $totalParts) {
        http_response_code(404);
        exit('The requested PDF part does not exist.');
    }

    $result = $assetService->getPage($filters, null, $part, $chunkSize);

    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (!is_file($headerImagePath) || !is_readable($headerImagePath)) {
        throw new RuntimeException('The company PDF header image is unavailable.');
    }

    $headerImageUri = 'data:image/png;base64,' . base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $pdfBytes = renderAssetPdf(
        $result['items'],
        $headerImageUri,
        date('Y-m-d H:i:s'),
        $filters['search'] !== '' ? $filters['search'] : 'All assets',
        $part,
        $totalParts,
        $totalAssets
    );

    $filename = sprintf(
        'inventory-tracker-assets-part-%02d-of-%02d-%s.pdf',
        $part,
        $totalParts,
        date('Y-m-d')
    );

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo $pdfBytes;
    exit;
} catch (Throwable $exception) {
    error_log('Assets PDF export failed: ' . $exception->getMessage());

    http_response_code(500);
    exit('Unable to export this PDF part. Please check the application logs.');
}
