<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Models\AssetQrScanLog;
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

if (
    !$authorization->canAccessAssetMaterialAreas()
    && !$authorization->isPropertyCustodian()
) {
    http_response_code(403);
    exit('Access denied. You do not have permission to export QR scan monitoring records.');
}

$assetTag = trim((string) ($_GET['asset_tag'] ?? ''));
$scanner = trim((string) ($_GET['scanner'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$validateDate = static function (string $value): bool {
    if ($value === '') {
        return true;
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = \DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || (
            $errors['warning_count'] === 0
            && $errors['error_count'] === 0
        ))
        && $date->format('Y-m-d') === $value;
};

if (!$validateDate($dateFrom) || !$validateDate($dateTo)) {
    http_response_code(400);
    exit('Invalid date filter. Please use valid dates.');
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    http_response_code(400);
    exit('The start date cannot be after the end date.');
}

$filters = [
    'asset_tag' => $assetTag,
    'scanner' => $scanner,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

if ($authorization->isPropertyCustodian()) {
    $filters['scanned_by_user_id'] = (int) $user['user_id'];
}

$spreadsheet = null;

try {
    $scanLog = new AssetQrScanLog();
    $scans = $scanLog->getAllScans($filters);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('QR Scan Monitoring');

    $sheet->mergeCells('A1:E1');
    $sheet->setCellValue('A1', 'QR SCAN MONITORING REPORT');

    $sheet->mergeCells('A2:E2');
    $sheet->setCellValue(
        'A2',
        'Generated: ' . date('F j, Y g:i A')
    );

    $sheet->getStyle('A1:E1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '147D70'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle('A2:E2')->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
            'color' => ['rgb' => '64748B'],
        ],
    ]);

    $sheet->mergeCells('A4:E4');
    $sheet->setCellValue(
        'A4',
        'Total Matching Scans: ' . count($scans)
    );

    $sheet->getStyle('A4:E4')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 11,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'EAF2F1'],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $headers = [
        'Scanned At',
        'Asset Tag',
        'Asset Name',
        'QR Code ID',
        'Scanned By',
    ];

    $headerRow = 6;

    foreach ($headers as $index => $heading) {
        $column = chr(ord('A') + $index);
        $sheet->setCellValue($column . $headerRow, $heading);
    }

    $sheet->getStyle("A{$headerRow}:E{$headerRow}")
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

    foreach ($scans as $scan) {
        $scannerName = trim(
            (string) ($scan['first_name'] ?? '')
            . ' '
            . (string) ($scan['last_name'] ?? '')
        );

        if (($scan['scanned_by'] ?? null) === null) {
            $scannerName = 'User unavailable';
        } elseif ($scannerName === '') {
            $scannerName = trim((string) ($scan['username'] ?? ''));
            if ($scannerName === '') {
                $scannerName = 'Unknown user';
            }
        }

        $values = [
            'A' => $scan['scanned_at'] ?? '',
            'B' => $scan['asset_tag'] ?? '',
            'C' => $scan['asset_name'] ?? '',
            'D' => $scan['asset_qr_code_id'] ?? '',
            'E' => $scannerName,
        ];

        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicit(
                $column . $row,
                (string) $value,
                DataType::TYPE_STRING
            );
        }

        $row++;
    }

    if ($scans === []) {
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue('A' . $row, 'No matching QR scans found.');
        $row++;
    }

    $lastRow = $row - 1;

    $sheet->getStyle("A{$headerRow}:E{$lastRow}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $sheet->getStyle("A{$headerRow}:E{$lastRow}")
        ->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER);

    $widths = [
        'A' => 23,
        'B' => 22,
        'C' => 34,
        'D' => 16,
        'E' => 28,
    ];

    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getRowDimension(4)->setRowHeight(25);
    $sheet->getRowDimension($headerRow)->setRowHeight(30);

    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A{$headerRow}:E" . max($headerRow, $row - 1));

    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageSetup()->setFitToPage(true);

    $sheet->getPageMargins()->setTop(0.25);
    $sheet->getPageMargins()->setRight(0.25);
    $sheet->getPageMargins()->setBottom(0.25);
    $sheet->getPageMargins()->setLeft(0.25);

    $filename = 'qr-scan-monitoring-' . date('Y-m-d') . '.xlsx';

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
    header('Content-Disposition: attachment; filename="' . $filename . '"');
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
        'QR scan monitoring Excel export failed: '
        . get_class($exception)
        . ': '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the QR scan monitoring Excel file. '
        . 'Check the application error log.'
    );
}
