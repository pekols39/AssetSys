<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Core\Auth;
use App\Services\AuthorizationService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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

$spreadsheet = null;

try {
    $reportService = new AssetReportService();
    $assets = $reportService->getAssetInventory($filters);

    $totalPurchaseCost = 0.0;
    $totalBookValue = 0.0;

    foreach ($assets as $asset) {
        $totalPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $totalBookValue += (float) ($asset['current_book_value'] ?? 0);
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Asset Inventory');

    /*
     * Report title and generation date.
     * No company header image is included in this Excel export.
     */
    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', 'ASSET INVENTORY REPORT');

    $sheet->mergeCells('A2:L2');
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('F j, Y g:i A')
    );

    $sheet->getStyle('A1:L1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '147D70'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle('A2:L2')->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
            'color' => ['rgb' => '64748B'],
        ],
    ]);

    // Summary.
    $sheet->mergeCells('A4:D4');
    $sheet->setCellValue('A4', 'Total Assets: ' . count($assets));

    $sheet->mergeCells('E4:H4');
    $sheet->setCellValue('E4', 'AssetSys Purchase Cost: PHP ' . number_format($totalPurchaseCost, 2));

    $sheet->mergeCells('I4:L4');
    $sheet->setCellValue('I4', 'AssetSys Current Book Value: PHP ' . number_format($totalBookValue, 2));

    $sheet->getStyle('A4:L4')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 10,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'EAF2F1'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Table headings.
    $headers = [
        'Location (Main)',
        'Owner / Department',
        'Category',
        'Item ID',
        'Item Name',
        'FFE Number',
        'Code',
        'Status',
        'Supplier',
        'PO Number',
        'Price',
        'Date Purchase',
    ];

    $headerRow = 6;

    foreach ($headers as $index => $heading) {
        $column = chr(ord('A') + $index);
        $sheet->setCellValue($column . $headerRow, $heading);
    }

    $sheet->getStyle("A{$headerRow}:L{$headerRow}")
        ->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '147D70'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

    // Asset records.
    $row = $headerRow + 1;

    foreach ($assets as $asset) {
        $textValues = [
            'A' => $asset['finance_main_location'] ?? '',
            'B' => $asset['finance_owner_department'] ?? '',
            'C' => $asset['category_name'] ?? '',
            'D' => $asset['finance_item_id'] ?? '',
            'E' => $asset['finance_item_name'] ?? '',
            'F' => $asset['finance_ffe_number'] ?? '',
            'G' => $asset['finance_code'] ?? '',
            'H' => $asset['finance_status'] ?? '',
            'I' => $asset['finance_supplier'] ?? '',
            'J' => $asset['finance_po_number'] ?? '',
            'K' => $asset['finance_price_text'] ?? '',
            'L' => $asset['finance_date_purchase_text'] ?? '',
        ];

        foreach ($textValues as $column => $value) {
            $sheet->setCellValueExplicit(
                $column . $row,
                (string) $value,
                DataType::TYPE_STRING
            );
        }

        $row++;
    }

    if ($assets === []) {
        $sheet->mergeCells("A{$row}:L{$row}");
        $sheet->setCellValue('A' . $row, 'No matching assets found.');
        $row++;
    }

    // Official inventory table contains source fields only; accounting totals remain in the summary above.
    $lastAssetRow = max($headerRow, $row - 1);

    // Table borders and vertical alignment.
    $sheet->getStyle("A{$headerRow}:L{$lastAssetRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $sheet->getStyle(
        'A' . ($headerRow + 1) . ':L' . $lastAssetRow
    )->getAlignment()->setVertical(
        Alignment::VERTICAL_CENTER
    );

    // Column widths.
    $widths = [
        'A' => 22, 'B' => 26, 'C' => 20, 'D' => 16,
        'E' => 30, 'F' => 20, 'G' => 18, 'H' => 18,
        'I' => 26, 'J' => 20, 'K' => 18, 'L' => 18,
    ];
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getRowDimension(4)->setRowHeight(25);
    $sheet->getRowDimension($headerRow)->setRowHeight(32);

    // Keep headings visible while scrolling.
    $sheet->freezePane('A7');

    // Enable filtering on the inventory table.
    $sheet->setAutoFilter("A{$headerRow}:L{$lastAssetRow}");

    // Page setup for printing.
    $sheet->getPageSetup()->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

    $sheet->getPageSetup()->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageSetup()->setFitToPage(true);

    $sheet->getPageMargins()->setTop(0.25);
    $sheet->getPageMargins()->setRight(0.25);
    $sheet->getPageMargins()->setBottom(0.25);
    $sheet->getPageMargins()->setLeft(0.25);

    // Prepare the download.
    $filename = 'asset-inventory-' . date('Y-m-d') . '.xlsx';

    if (headers_sent()) {
        throw new RuntimeException(
            'Cannot export Excel because output has already started.'
        );
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' . $filename . '"'
    );

    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $exception) {
    if ($spreadsheet instanceof Spreadsheet) {
        $spreadsheet->disconnectWorksheets();
    }

    error_log(
        'Asset inventory Excel export failed: '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the asset inventory Excel file. '
        . 'Check the application error log.'
    );
}