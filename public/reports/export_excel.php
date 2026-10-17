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

$spreadsheet = null;

try {
    $reportService = new AssetReportService($departmentId);
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
    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', 'ASSET INVENTORY REPORT');

    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('F j, Y g:i A')
    );

    $sheet->getStyle('A1:J1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '147D70'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle('A2:J2')->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
            'color' => ['rgb' => '64748B'],
        ],
    ]);

    // Summary.
    $sheet->mergeCells('A4:C4');
    $sheet->setCellValue(
        'A4',
        'Total Assets: ' . count($assets)
    );

    $sheet->mergeCells('D4:F4');
    $sheet->setCellValue(
        'D4',
        'Purchase Cost: PHP ' . number_format($totalPurchaseCost, 2)
    );

    $sheet->mergeCells('G4:J4');
    $sheet->setCellValue(
        'G4',
        'Current Book Value: PHP ' . number_format($totalBookValue, 2)
    );

    $sheet->getStyle('A4:J4')->applyFromArray([
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
        'Asset Code',
        'Asset Name',
        'Category',
        'Status',
        'Condition',
        'Location',
        'Assigned To',
        'Purchase Date',
        'Purchase Cost',
        'Current Book Value',
    ];

    $headerRow = 6;

    foreach ($headers as $index => $heading) {
        $column = chr(ord('A') + $index);
        $sheet->setCellValue($column . $headerRow, $heading);
    }

    $sheet->getStyle("A{$headerRow}:J{$headerRow}")
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
        $assignedName = trim(
            (string) ($asset['assigned_first_name'] ?? '')
            . ' '
            . (string) ($asset['assigned_last_name'] ?? '')
        );

        $textValues = [
            'A' => $asset['asset_code'] ?? '',
            'B' => $asset['asset_name'] ?? '',
            'C' => $asset['category_name'] ?? '',
            'D' => $asset['status_name'] ?? '',
            'E' => $asset['condition_name'] ?? '',
            'F' => $asset['location_name'] ?? '',
            'G' => $assignedName !== '' ? $assignedName : 'Unassigned',
            'H' => $asset['purchase_date'] ?? '',
        ];

        foreach ($textValues as $column => $value) {
            $sheet->setCellValueExplicit(
                $column . $row,
                (string) $value,
                DataType::TYPE_STRING
            );
        }

        $sheet->setCellValue(
            'I' . $row,
            (float) ($asset['purchase_cost'] ?? 0)
        );

        $sheet->setCellValue(
            'J' . $row,
            (float) ($asset['current_book_value'] ?? 0)
        );

        $row++;
    }

    if ($assets === []) {
        $sheet->mergeCells("A{$row}:J{$row}");
        $sheet->setCellValue('A' . $row, 'No matching assets found.');
        $row++;
    }

    // Filtered totals.
    $totalRow = $row;

    $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
    $sheet->setCellValue('A' . $totalRow, 'FILTERED TOTALS');
    $sheet->setCellValue('I' . $totalRow, $totalPurchaseCost);
    $sheet->setCellValue('J' . $totalRow, $totalBookValue);

    $sheet->getStyle("A{$totalRow}:J{$totalRow}")
        ->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAF2F1'],
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'B8CECA'],
                ],
            ],
        ]);

    // Philippine peso formatting for purchase cost and book value.
    $sheet->getStyle('I' . ($headerRow + 1) . ':J' . $totalRow)
        ->getNumberFormat()
        ->setFormatCode('"₱"#,##0.00;[Red]-"₱"#,##0.00;-');

    // Table borders and vertical alignment.
    $sheet->getStyle("A{$headerRow}:J{$totalRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $sheet->getStyle(
        'I' . ($headerRow + 1) . ':J' . $totalRow
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_RIGHT
    );

    $sheet->getStyle(
        'A' . ($headerRow + 1) . ':J' . $totalRow
    )->getAlignment()->setVertical(
        Alignment::VERTICAL_CENTER
    );

    // Column widths.
    $widths = [
        'A' => 22,
        'B' => 30,
        'C' => 25,
        'D' => 18,
        'E' => 18,
        'F' => 22,
        'G' => 26,
        'H' => 16,
        'I' => 20,
        'J' => 22,
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
    $lastAssetRow = max($headerRow, $row - 1);
    $sheet->setAutoFilter("A{$headerRow}:J{$lastAssetRow}");

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