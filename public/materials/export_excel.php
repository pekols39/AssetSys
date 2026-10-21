<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

try {
    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'category_id' => (string) ($_GET['category_id'] ?? ''),
        'status' => (string) ($_GET['status'] ?? ''),
        'sort' => (string) ($_GET['sort'] ?? 'name'),
        'direction' => (string) ($_GET['direction'] ?? 'asc'),
    ];

    $materialService = new MaterialService();

    // Fetch every matching material, not just the current page.
    $page = 1;
    $perPage = 100;
    $materials = [];

    do {
        $result = $materialService->getPage(
            $filters,
            $page,
            $perPage
        );

        foreach ($result['items'] as $material) {
            $materials[] = $material;
        }

        $totalPages = (int) $result['total_pages'];
        $page++;
    } while ($page <= $totalPages);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Materials');

    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', 'INVENTORY TRACKER - MATERIALS');

    $sheet->getStyle('A1:J1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '007472'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(30);

    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('Y-m-d H:i:s')
    );

    $sheet->getStyle('A2:J2')->applyFromArray([
        'font' => [
            'italic' => true,
            'color' => ['rgb' => '666666'],
        ],
    ]);

    $headers = [
        'Material Code',
        'Material Name',
        'Category',
        'Category Code',
        'Description',
        'Unit',
        'Minimum Stock Level',
        'Reorder Level',
        'Returnable',
        'Status',
    ];

    $headerRow = 4;

    foreach ($headers as $index => $header) {
        $column = chr(ord('A') + $index);

        $sheet->setCellValue(
            $column . $headerRow,
            $header
        );
    }

    $sheet->getStyle('A4:J4')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '007472'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'bottom' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '005B59'],
            ],
        ],
    ]);

    $row = 5;

    foreach ($materials as $material) {
        $values = [
            (string) ($material['code'] ?? ''),
            (string) ($material['name'] ?? ''),
            (string) ($material['category_name'] ?? ''),
            (string) ($material['category_code'] ?? ''),
            (string) ($material['description'] ?? ''),
            (string) ($material['unit'] ?? ''),
            (float) ($material['minimum_stock_level'] ?? 0),
            (float) ($material['reorder_level'] ?? 0),
            (int) ($material['is_returnable'] ?? 0) === 1
                ? 'Yes'
                : 'No',
            (int) ($material['is_active'] ?? 0) === 1
                ? 'Active'
                : 'Inactive',
        ];

        foreach ($values as $index => $value) {
            $column = chr(ord('A') + $index);
            $cell = $column . $row;

            // Keep user-controlled text as literal text to prevent
            // spreadsheet formula injection.
            if (in_array($index, [6, 7], true)) {
                $sheet->setCellValue($cell, $value);
            } else {
                $sheet->setCellValueExplicit(
                    $cell,
                    (string) $value,
                    DataType::TYPE_STRING
                );
            }
        }

        $row++;
    }

    $lastRow = max(4, $row - 1);

    if ($lastRow >= 5) {
        $sheet->getStyle('G5:H' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        $sheet->getStyle('A5:J' . $lastRow)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $sheet->getColumnDimension('E')->setWidth(40);
    $sheet->getStyle('E5:E' . max(5, $lastRow))
        ->getAlignment()
        ->setWrapText(true);

    $sheet->freezePane('A5');
    $sheet->setAutoFilter('A4:J' . $lastRow);

    $filename = 'inventory-tracker-materials-'
        . date('Y-m-d')
        . '.xlsx';

    // Prevent accidental output from corrupting the Excel file.
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
    header('X-Content-Type-Options: nosniff');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $e) {
    error_log(
        'Materials Excel export failed: ' . $e->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit('Unable to export materials. Please try again later.');
}
