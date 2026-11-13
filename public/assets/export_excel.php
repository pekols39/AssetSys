<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
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

    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit('You do not have permission to export assets.');
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

$spreadsheet = null;

try {
    $assetService = new AssetService();

    /*
     * Export every matching asset, not only the current page.
     * AssetService::getPage() supports up to 100 records per page.
     */
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

        if ($page === 1) {
            $totalPages = (int) $result['total_pages'];
        }

        foreach ($result['items'] as $asset) {
            $assets[] = $asset;
        }

        $page++;
    } while ($page <= $totalPages);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Assets');

    $headers = [
        'Asset Code',
        'Asset Tag',
        'Serial Number',
        'Asset Name',
        'Category',
        'Status (AssetSys)',
        'Condition',
        'Department (AssetSys)',
        'Location (AssetSys)',
        'Assigned Employee',
        'Purchase Date (AssetSys)',
        'Purchase Cost (AssetSys)',
        'Current Book Value (AssetSys)',
        'LOCATION (Main) - Workbook',
        'OWNER / DEPARTMENT - Workbook',
        'Item ID - Workbook',
        'Item name - Workbook',
        'FFE NUMBER - Workbook',
        'CODE - Workbook',
        'Status - Workbook',
        'SUPPLIER - Workbook',
        'PO NUMBER - Workbook',
        'Price - Workbook',
        'DATE PURCHASE - Workbook',
        'LOCATION - Workbook',
    ];

    $lastColumn = 'Y';

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', 'INVENTORY TRACKER - ASSET REGISTER');

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('F j, Y g:i A')
    );

    $sheet->mergeCells('A4:D4');
    $sheet->setCellValue(
        'A4',
        'Total Assets: ' . count($assets)
    );

    $totalPurchaseCost = 0.0;
    $totalBookValue = 0.0;

    foreach ($assets as $asset) {
        $totalPurchaseCost += (float) ($asset['purchase_cost'] ?? 0);
        $totalBookValue += (float) ($asset['current_book_value'] ?? 0);
    }

    $sheet->mergeCells('E4:I4');
    $sheet->setCellValue(
        'E4',
        'Total Purchase Cost: PHP ' . number_format($totalPurchaseCost, 2)
    );

    $sheet->mergeCells('J4:M4');
    $sheet->setCellValue(
        'J4',
        'Total Book Value: PHP ' . number_format($totalBookValue, 2)
    );

    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '147D70'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
            'color' => ['rgb' => '64748B'],
        ],
    ]);

    $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
        'font' => [
            'bold' => true,
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

    $sheet->mergeCells('A5:M5');
    $sheet->setCellValue('A5', 'ASSETSYS RECORD');
    $sheet->mergeCells('N5:Y5');
    $sheet->setCellValue('N5', 'ORIGINAL WORKBOOK VALUES');
    $sheet->getStyle('A5:Y5')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '52636A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    $headerRow = 6;

    foreach ($headers as $index => $heading) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $index + 1
        );

        $sheet->setCellValue(
            $column . $headerRow,
            $heading
        );
    }

    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")
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

    $row = $headerRow + 1;

    foreach ($assets as $asset) {
        $assignedName = trim(
            (string) ($asset['assigned_to_name'] ?? '')
        );

        if ($assignedName === '') {
            $assignedName = trim(
                (string) ($asset['assigned_first_name'] ?? '')
                . ' '
                . (string) ($asset['assigned_last_name'] ?? '')
            );
        }
        if ($assignedName === '') {
            $assignedName = strtolower((string) ($asset['status_name'] ?? '')) === 'in use'
                ? 'No individual assignee'
                : 'Unassigned';
        }

        $textValues = [
            'A' => $asset['asset_code'] ?? '',
            'B' => $asset['asset_tag'] ?? '',
            'C' => $asset['serial_number'] ?? '',
            'D' => $asset['asset_name'] ?? '',
            'E' => $asset['category_name'] ?? '',
            'F' => $asset['status_name'] ?? '',
            'G' => $asset['condition_name'] ?? '',
            'H' => $asset['department_name'] ?? '',
            'I' => $asset['location_name'] ?? '',
            'J' => $assignedName !== '' ? $assignedName : 'Unassigned',
            'K' => $asset['purchase_date'] ?? '',
            'N' => $asset['finance_main_location'] ?? '',
            'O' => $asset['finance_owner_department'] ?? '',
            'P' => $asset['finance_item_id'] ?? '',
            'Q' => $asset['finance_item_name'] ?? '',
            'R' => $asset['finance_ffe_number'] ?? '',
            'S' => $asset['finance_code'] ?? '',
            'T' => $asset['finance_status'] ?? '',
            'U' => $asset['finance_supplier'] ?? '',
            'V' => $asset['finance_po_number'] ?? '',
            'W' => $asset['finance_price_text'] ?? '',
            'X' => $asset['finance_date_purchase_text'] ?? '',
            'Y' => $asset['finance_location'] ?? '',
        ];

        foreach ($textValues as $column => $value) {
            $sheet->setCellValueExplicit(
                $column . $row,
                (string) $value,
                DataType::TYPE_STRING
            );
        }

        $sheet->setCellValue(
            'L' . $row,
            (float) ($asset['purchase_cost'] ?? 0)
        );

        $sheet->setCellValue(
            'M' . $row,
            (float) ($asset['current_book_value'] ?? 0)
        );

        $row++;
    }

    if ($assets === []) {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue('A' . $row, 'No matching assets found.');
        $row++;
    }

    $totalRow = $row;

    $sheet->mergeCells("A{$totalRow}:K{$totalRow}");
    $sheet->setCellValue('A' . $totalRow, 'TOTALS');
    $sheet->setCellValue('L' . $totalRow, $totalPurchaseCost);
    $sheet->setCellValue('M' . $totalRow, $totalBookValue);

    $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")
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

    $sheet->getStyle("L" . ($headerRow + 1) . ":M{$totalRow}")
        ->getNumberFormat()
        ->setFormatCode('"₱"#,##0.00;[Red]-"₱"#,##0.00;-');

    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$totalRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $widths = [
        'A' => 20,
        'B' => 18,
        'C' => 22,
        'D' => 30,
        'E' => 22,
        'F' => 18,
        'G' => 18,
        'H' => 22,
        'I' => 22,
        'J' => 26,
        'K' => 16,
        'L' => 20,
        'M' => 22,
        'N' => 24,
        'O' => 24,
        'P' => 18,
        'Q' => 38,
        'R' => 28,
        'S' => 12,
        'T' => 16,
        'U' => 22,
        'V' => 18,
        'W' => 16,
        'X' => 18,
        'Y' => 30,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getRowDimension(4)->setRowHeight(28);
    $sheet->getRowDimension($headerRow)->setRowHeight(34);

    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}" . max($headerRow, $row - 1));

    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageSetup()->setFitToPage(true);

    $filename = 'inventory-tracker-assets-' . date('Y-m-d') . '.xlsx';

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
        'Assets Excel export failed: ' . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the Assets Excel file. '
        . 'Check the application error log.'
    );
}
