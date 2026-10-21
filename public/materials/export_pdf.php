<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialService;
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
    exit('You do not have permission to export materials.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => (string) ($_GET['category_id'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'name'),
    'direction' => (string) ($_GET['direction'] ?? 'asc'),
];

function materialPdfEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

try {
    $materialService = new MaterialService();

    // Retrieve every matching material, not just the current page.
    $materials = [];
    $page = 1;
    $totalPages = 1;

    do {
        $result = $materialService->getPage(
            $filters,
            $page,
            100
        );

        if ($page === 1) {
            $totalPages = (int) $result['total_pages'];
        }

        foreach ($result['items'] as $material) {
            $materials[] = $material;
        }

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
    $totalMaterials = count($materials);

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
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
        font-size: 8px;
        color: #263238;
        padding: 0 18px 18px;
    }

    .company-header {
        width: calc(100% + 36px);
        margin: 0 -18px 18px;
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
        font-size: 18px;
        color: #007472;
        margin: 0 0 5px;
    }

    .subtitle {
        font-size: 9px;
        margin-bottom: 12px;
        line-height: 1.6;
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

    .center {
        text-align: center;
    }

    .footer {
        margin-top: 10px;
        font-size: 7px;
        color: #607070;
    }

    thead {
        display: table-header-group;
    }

    tr {
        page-break-inside: avoid;
    }
</style>
</head>
<body>';

    $html .= '<div class="company-header">';
    $html .= '<img src="' . $headerImageUri . '" alt="Company Header">';
    $html .= '</div>';
    $html .= '<h1>Inventory Tracker</h1>';
    $html .= '<div class="subtitle">';
    $html .= 'Materials &amp; Stock Export<br>';
    $html .= 'Generated: ' . materialPdfEscape($generatedAt) . '<br>';
    $html .= 'Search: ' . materialPdfEscape(
        $filters['search'] !== '' ? $filters['search'] : 'All materials'
    ) . '<br>';
    $html .= 'Category: ' . materialPdfEscape(
        $filters['category_id'] !== '' ? $filters['category_id'] : 'All categories'
    ) . '<br>';
    $html .= 'Status: ' . materialPdfEscape(
        $filters['status'] !== '' ? ucfirst($filters['status']) : 'All statuses'
    );
    $html .= '</div>';

    $html .= '<table class="summary"><tr>';
    $html .= '<td>Total matching materials<br><strong>'
        . number_format($totalMaterials)
        . '</strong></td>';
    $html .= '<td>Report generated<br><strong>'
        . materialPdfEscape($generatedAt)
        . '</strong></td>';
    $html .= '</tr></table>';

    $html .= '<table class="inventory"><thead><tr>';
    $html .= '<th style="width:9%">Material Code</th>';
    $html .= '<th style="width:12%">Material Name</th>';
    $html .= '<th style="width:9%">Category</th>';
    $html .= '<th style="width:8%">Category Code</th>';
    $html .= '<th style="width:18%">Description</th>';
    $html .= '<th style="width:7%">Unit</th>';
    $html .= '<th style="width:8%">Minimum Stock</th>';
    $html .= '<th style="width:8%">Reorder Level</th>';
    $html .= '<th style="width:8%">Returnable</th>';
    $html .= '<th style="width:8%">Status</th>';
    $html .= '</tr></thead><tbody>';

    if ($materials === []) {
        $html .= '<tr><td colspan="10" class="center">'
            . 'No materials found for the selected filters.'
            . '</td></tr>';
    } else {
        foreach ($materials as $material) {
            $html .= '<tr>';

            $html .= '<td>'
                . materialPdfEscape($material['code'] ?? '')
                . '</td>';

            $html .= '<td>'
                . materialPdfEscape($material['name'] ?? '')
                . '</td>';

            $html .= '<td>'
                . materialPdfEscape($material['category_name'] ?? '')
                . '</td>';

            $html .= '<td>'
                . materialPdfEscape($material['category_code'] ?? '')
                . '</td>';

            $html .= '<td>'
                . materialPdfEscape($material['description'] ?? '')
                . '</td>';

            $html .= '<td>'
                . materialPdfEscape($material['unit'] ?? '')
                . '</td>';

            $html .= '<td class="center">'
                . materialPdfEscape(
                    $material['minimum_stock_level'] ?? 0
                )
                . '</td>';

            $html .= '<td class="center">'
                . materialPdfEscape(
                    $material['reorder_level'] ?? 0
                )
                . '</td>';

            $html .= '<td class="center">'
                . ((int) ($material['is_returnable'] ?? 0) === 1
                    ? 'Yes'
                    : 'No')
                . '</td>';

            $html .= '<td class="center">'
                . ((int) ($material['is_active'] ?? 0) === 1
                    ? 'Active'
                    : 'Inactive')
                . '</td>';

            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table>';

    $html .= '<div class="footer">';
    $html .= 'Inventory Tracker — Materials &amp; Stock | Total records: ';
    $html .= number_format($totalMaterials);
    $html .= '</div>';

    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'inventory-tracker-materials-'
        . date('Y-m-d')
        . '.pdf';

    $dompdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;
} catch (Throwable $exception) {
    error_log(
        'Materials PDF export failed: ' . $exception->getMessage()
    );

    http_response_code(500);
    exit('Unable to export materials. Please check the application logs.');
}
