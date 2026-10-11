<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Services\AuthorizationService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

if (($user['role_name'] ?? '') !== 'Asset Manager') {
    http_response_code(403);
    exit('Access denied. Depreciation reports are available to Asset Managers only.');
}

$departmentId = (int) ($user['department_id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(403);
    exit('Access denied. Your account is not assigned to a department.');
}

$year = filter_var(
    $_GET['year'] ?? date('Y'),
    FILTER_VALIDATE_INT
);

$month = filter_var(
    $_GET['month'] ?? date('n'),
    FILTER_VALIDATE_INT
);

if (
    $year === false
    || $year < 1000
    || $year > 9999
    || $month === false
    || $month < 1
    || $month > 12
) {
    http_response_code(400);
    exit('Invalid depreciation report month or year.');
}

$spreadsheet = null;

try {
    $reportService = new AssetReportService($departmentId);

    $entries = $reportService->getDepreciationReport(
        $year,
        $month
    );

    $totalDepreciation = 0.0;

    foreach ($entries as $entry) {
        $totalDepreciation +=
            (float) ($entry['depreciation_amount'] ?? 0);
    }

    $periodLabel = (
        new \DateTimeImmutable(
            sprintf('%04d-%02d-01', $year, $month)
        )
    )->format('F Y');

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Depreciation Report');

    // Report title and generation date.
    $sheet->mergeCells('A1:K1');
    $sheet->setCellValue('A1', 'DEPRECIATION REPORT');

    $sheet->mergeCells('A2:K2');
    $sheet->setCellValue(
        'A2',
        'Reporting Period: ' . $periodLabel
        . ' | Generated: ' . date('F j, Y g:i A')
    );

    $sheet->getStyle('A1:K1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '147D70'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle('A2:K2')->applyFromArray([
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
        'Posted Entries: ' . count($entries)
    );

    $sheet->mergeCells('D4:F4');
    $sheet->setCellValue(
        'D4',
        'Total Depreciation: PHP '
        . number_format($totalDepreciation, 2)
    );

    $sheet->mergeCells('G4:K4');
    $sheet->setCellValue(
        'G4',
        'Reporting Period: ' . $periodLabel
    );

    $sheet->getStyle('A4:K4')->applyFromArray([
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
        'Period Start',
        'Period End',
        'Asset Code',
        'Asset Name',
        'Category',
        'Depreciation',
        'Accumulated Depreciation',
        'Opening Book Value',
        'Closing Book Value',
        'Posted By',
        'Posted At',
    ];

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

    $sheet->getStyle("A{$headerRow}:K{$headerRow}")
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

    // Depreciation entries.
    $row = $headerRow + 1;

    foreach ($entries as $entry) {
        $textValues = [
            'A' => $entry['period_start'] ?? '',
            'B' => $entry['period_end'] ?? '',
            'C' => $entry['asset_code'] ?? '',
            'D' => $entry['asset_name'] ?? '',
            'E' => $entry['category_name'] ?? '',
            'J' => $entry['posted_by_name'] ?? '',
            'K' => $entry['posted_at'] ?? '',
        ];

        foreach ($textValues as $column => $value) {
            $sheet->setCellValueExplicit(
                $column . $row,
                (string) $value,
                DataType::TYPE_STRING
            );
        }

        $sheet->setCellValue(
            'F' . $row,
            (float) ($entry['depreciation_amount'] ?? 0)
        );

        $sheet->setCellValue(
            'G' . $row,
            (float) ($entry['accumulated_depreciation'] ?? 0)
        );

        $sheet->setCellValue(
            'H' . $row,
            (float) ($entry['book_value_before'] ?? 0)
        );

        $sheet->setCellValue(
            'I' . $row,
            (float) ($entry['book_value_after'] ?? 0)
        );

        $row++;
    }

    if ($entries === []) {
        $sheet->mergeCells("A{$row}:K{$row}");
        $sheet->setCellValue(
            'A' . $row,
            'No posted depreciation entries found for this period.'
        );
        $row++;
    }

    // Total row.
    $totalRow = $row;

    $sheet->mergeCells("A{$totalRow}:E{$totalRow}");
    $sheet->setCellValue(
        'A' . $totalRow,
        'TOTAL DEPRECIATION'
    );

    $sheet->setCellValue(
        'F' . $totalRow,
        $totalDepreciation
    );

    $sheet->getStyle("A{$totalRow}:K{$totalRow}")
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

    // Philippine peso formatting.
    $sheet->getStyle("F" . ($headerRow + 1) . ":I{$totalRow}")
        ->getNumberFormat()
        ->setFormatCode('"₱"#,##0.00;[Red]-"₱"#,##0.00;-');

    // Table borders and alignment.
    $sheet->getStyle("A{$headerRow}:K{$totalRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $sheet->getStyle(
        "A" . ($headerRow + 1) . ":K{$totalRow}"
    )->getAlignment()->setVertical(
        Alignment::VERTICAL_CENTER
    );

    // Column widths.
    $widths = [
        'A' => 14,
        'B' => 14,
        'C' => 18,
        'D' => 28,
        'E' => 20,
        'F' => 18,
        'G' => 24,
        'H' => 20,
        'I' => 20,
        'J' => 22,
        'K' => 22,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getRowDimension(4)->setRowHeight(25);
    $sheet->getRowDimension($headerRow)->setRowHeight(36);

    $sheet->freezePane('A7');

    $lastEntryRow = max($headerRow, $row - 1);

    $sheet->setAutoFilter(
        "A{$headerRow}:K{$lastEntryRow}"
    );

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

    $filename = sprintf(
        'depreciation-report-%04d-%02d.xlsx',
        $year,
        $month
    );

    if (headers_sent()) {
        throw new \RuntimeException(
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

} catch (\Throwable $exception) {
    if ($spreadsheet instanceof Spreadsheet) {
        $spreadsheet->disconnectWorksheets();
    }

    error_log(
        'Depreciation Excel export failed: '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the depreciation Excel file. '
        . 'Check the application error log.'
    );
}