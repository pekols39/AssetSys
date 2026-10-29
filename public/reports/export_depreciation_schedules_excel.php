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

    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit(
        'Access denied. Depreciation reports are available '
        . 'to Asset Managers only.'
    );
}

/*
 * Only accept known schedule statuses.
 * An empty status means all statuses.
 */
$allowedStatuses = [
    'active',
    'completed',
    'cancelled',
];

$status = trim((string) ($_GET['status'] ?? ''));

if (
    $status !== ''
    && !in_array($status, $allowedStatuses, true)
) {
    http_response_code(400);
    exit('Invalid depreciation schedule status.');
}

$spreadsheet = null;

try {
    $reportService = new AssetReportService();

    /*
     * A null limit exports every matching schedule.
     * Do not apply the report page's per_page setting here.
     */
    $schedules = $reportService->getDepreciationScheduleOverview(
        null,
        0,
        $status !== '' ? $status : null
    );

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Depreciation Monitoring');

    $lastColumn = 'N';

    /*
     * Report heading.
     */
    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', 'DEPRECIATION MONITORING REPORT');

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('F j, Y g:i A')
    );

    $sheet->mergeCells("A3:{$lastColumn}3");
    $sheet->setCellValue(
        'A3',
        'Schedule Status: '
        . ($status !== '' ? ucfirst($status) : 'All Statuses')
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

    $sheet->getStyle("A2:{$lastColumn}3")->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
            'color' => ['rgb' => '64748B'],
        ],
    ]);

    /*
     * Summary totals for the exported schedules.
     */
    $totalAccumulated = 0.0;
    $totalBookValue = 0.0;
    $totalRemaining = 0.0;
    $totalFutureEntries = 0;

    foreach ($schedules as $schedule) {
        $depreciableAmount = (float) (
            $schedule['depreciable_amount'] ?? 0
        );

        $accumulatedDepreciation = (float) (
            $schedule['accumulated_depreciation'] ?? 0
        );

        $totalAccumulated += $accumulatedDepreciation;

        $totalBookValue += (float) (
            $schedule['current_book_value'] ?? 0
        );

        $totalRemaining += max(
            0.0,
            $depreciableAmount - $accumulatedDepreciation
        );

        $totalFutureEntries += (int) (
            $schedule['future_posted_periods'] ?? 0
        );
    }

    $sheet->mergeCells('A5:C5');
    $sheet->setCellValue(
        'A5',
        'Total Schedules: ' . count($schedules)
    );

    $sheet->mergeCells('D5:F5');
    $sheet->setCellValue(
        'D5',
        'Accumulated Depreciation: PHP '
        . number_format($totalAccumulated, 2)
    );

    $sheet->mergeCells('G5:I5');
    $sheet->setCellValue(
        'G5',
        'Remaining Depreciable Amount: PHP '
        . number_format($totalRemaining, 2)
    );

    $sheet->mergeCells('J5:L5');
    $sheet->setCellValue(
        'J5',
        'Current Book Value: PHP '
        . number_format($totalBookValue, 2)
    );

    $sheet->mergeCells('M5:N5');
    $sheet->setCellValue(
        'M5',
        'Future Entries: ' . number_format($totalFutureEntries)
    );

    $sheet->getStyle("A5:{$lastColumn}5")->applyFromArray([
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

    /*
     * Schedule table headings.
     */
    $headers = [
        'Asset Code',
        'Asset Name',
        'Asset Tag',
        'Category',
        'Schedule Status',
        'Useful Life (Years)',
        'Posted Periods',
        'Monthly Depreciation',
        'Accumulated Depreciation',
        'Remaining Depreciable Amount',
        'Current Book Value',
        'Progress (%)',
        'Future Entries',
        'Last Posted Period',
    ];

    $headerRow = 7;

    foreach ($headers as $index => $heading) {
        $column = chr(ord('A') + $index);
        $sheet->setCellValue($column . $headerRow, $heading);
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

    /*
     * Schedule records.
     */
    $row = $headerRow + 1;

    foreach ($schedules as $schedule) {
        $depreciableAmount = (float) (
            $schedule['depreciable_amount'] ?? 0
        );

        $accumulatedDepreciation = (float) (
            $schedule['accumulated_depreciation'] ?? 0
        );

        $remainingAmount = max(
            0.0,
            $depreciableAmount - $accumulatedDepreciation
        );

        $progress = $depreciableAmount > 0
            ? min(
                100.0,
                max(
                    0.0,
                    ($accumulatedDepreciation / $depreciableAmount) * 100
                )
            )
            : null;

        $textValues = [
            'A' => $schedule['asset_code'] ?? '',
            'B' => $schedule['asset_name'] ?? '',
            'C' => $schedule['asset_tag'] ?? '',
            'D' => $schedule['category_name'] ?? '',
            'E' => ucfirst(
                (string) ($schedule['schedule_status'] ?? '')
            ),
            'N' => $schedule['last_posted_period_end'] ?? '',
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
            (float) ($schedule['useful_life_years'] ?? 0)
        );

        $sheet->setCellValue(
            'G' . $row,
            (int) ($schedule['posted_periods'] ?? 0)
        );

        $sheet->setCellValue(
            'H' . $row,
            (float) ($schedule['monthly_depreciation'] ?? 0)
        );

        $sheet->setCellValue(
            'I' . $row,
            $accumulatedDepreciation
        );

        $sheet->setCellValue(
            'J' . $row,
            $remainingAmount
        );

        $sheet->setCellValue(
            'K' . $row,
            (float) ($schedule['current_book_value'] ?? 0)
        );

        if ($progress === null) {
            $sheet->setCellValueExplicit(
                'L' . $row,
                'Not applicable',
                DataType::TYPE_STRING
            );
        } else {
            $sheet->setCellValue('L' . $row, $progress);
        }

        $sheet->setCellValue(
            'M' . $row,
            (int) ($schedule['future_posted_periods'] ?? 0)
        );

        $row++;
    }

    if ($schedules === []) {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue(
            'A' . $row,
            'No depreciation schedules match the selected filter.'
        );

        $row++;
    }

    $lastDataRow = $row - 1;

    /*
     * Format numeric and monetary columns.
     */
    if ($lastDataRow > $headerRow) {
        $firstDataRow = $headerRow + 1;

        $sheet->getStyle("F{$firstDataRow}:F{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('0.00');

        $sheet->getStyle("H{$firstDataRow}:K{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('"₱"#,##0.00;[Red]-"₱"#,##0.00;-');

        $sheet->getStyle("L{$firstDataRow}:L{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('0.0"%"');

        $sheet->getStyle("F{$firstDataRow}:M{$lastDataRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastDataRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastDataRow}")
            ->getBorders()
            ->getAllBorders()
            ->getColor()
            ->setRGB('D9E2E1');
    }

    /*
     * Column widths.
     */
    $widths = [
        'A' => 20,
        'B' => 30,
        'C' => 20,
        'D' => 24,
        'E' => 18,
        'F' => 18,
        'G' => 16,
        'H' => 23,
        'I' => 25,
        'J' => 30,
        'K' => 22,
        'L' => 16,
        'M' => 16,
        'N' => 22,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getRowDimension(5)->setRowHeight(32);
    $sheet->getRowDimension($headerRow)->setRowHeight(40);

    $sheet->freezePane('A8');
    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastDataRow}");

    /*
     * Landscape A4 print settings.
     */
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

    /*
     * Prepare the download.
     */
    $filename = 'depreciation-monitoring-' . date('Y-m-d') . '.xlsx';

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
        'Depreciation schedule Excel export failed: '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the depreciation schedule Excel file. '
        . 'Check the application error log.'
    );
}
