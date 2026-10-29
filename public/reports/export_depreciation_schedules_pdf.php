<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Services\AuthorizationService;
use Dompdf\Dompdf;
use Dompdf\Options;

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

try {
    $headerImagePath = dirname(__DIR__, 2)
        . '/public/assets/images/header_mmgcci.png';

    if (!is_file($headerImagePath) || !is_readable($headerImagePath)) {
        throw new RuntimeException(
            'The company PDF header image is unavailable.'
        );
    }

    $headerImageData = base64_encode(
        (string) file_get_contents($headerImagePath)
    );

    $headerImageUri = 'data:image/png;base64,' . $headerImageData;

    $reportService = new AssetReportService();

    /*
     * Export every matching schedule, not just the current page.
     */
    $schedules = $reportService->getDepreciationScheduleOverview(
        null,
        0,
        $status !== '' ? $status : null
    );

    $escape = static fn(mixed $value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $totalAccumulated = 0.0;
    $totalRemaining = 0.0;
    $totalBookValue = 0.0;
    $totalFutureEntries = 0;

    foreach ($schedules as $schedule) {
        $depreciableAmount = (float) (
            $schedule['depreciable_amount'] ?? 0
        );

        $accumulatedDepreciation = (float) (
            $schedule['accumulated_depreciation'] ?? 0
        );

        $totalAccumulated += $accumulatedDepreciation;

        $totalRemaining += max(
            0.0,
            $depreciableAmount - $accumulatedDepreciation
        );

        $totalBookValue += (float) (
            $schedule['current_book_value'] ?? 0
        );

        $totalFutureEntries += (int) (
            $schedule['future_posted_periods'] ?? 0
        );
    }

    $statusLabel = $status !== ''
        ? ucfirst($status)
        : 'All Statuses';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Depreciation Monitoring Report</title>
<style>
    @page {
        size: A4 landscape;
        margin: 0 24px 24px 24px;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 7px;
        color: #202b35;
    }

    .company-header {
        width: 100%;
        margin: 0 0 12px 0;
        padding: 0;
        line-height: 0;
    }

    .company-header img {
        display: block;
        width: calc(100% + 48px);
        height: auto;
        margin: 0 -24px;
        padding: 0;
    }

    h1 {
        font-size: 19px;
        margin: 0 0 6px;
        color: #147d70;
    }

    .subtitle {
        color: #64748b;
        margin-bottom: 16px;
        font-size: 8px;
    }

    .summary {
        margin-bottom: 16px;
        padding: 10px;
        background: #f1f7f6;
        border: 1px solid #d7e7e4;
    }

    .summary span {
        display: inline-block;
        margin-right: 12px;
        margin-bottom: 4px;
    }

    table.schedules {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .schedules th {
        background: #147d70;
        color: #ffffff;
        font-weight: bold;
        text-align: left;
        font-size: 6px;
    }

    .schedules th,
    .schedules td {
        border: 1px solid #d9e1e7;
        padding: 4px 3px;
        vertical-align: top;
        overflow-wrap: break-word;
    }

    .schedules tbody tr:nth-child(even) {
        background: #f7f9fa;
    }

    .number {
        text-align: right;
        white-space: nowrap;
    }

    .center {
        text-align: center;
    }

    .status {
        font-weight: bold;
    }

    .footer {
        margin-top: 12px;
        color: #64748b;
        font-size: 8px;
    }
</style>
</head>
<body>

    <div class="company-header">
        <img
            src="' . $headerImageUri . '"
            alt="Company Header"
        >
    </div>

    <h1>Depreciation Monitoring Report</h1>

    <div class="subtitle">
        Generated: ' . $escape(date('F j, Y g:i A')) . '
        &nbsp; | &nbsp; Schedule Status: '
            . $escape($statusLabel) . '
    </div>

    <div class="summary">
        <span><strong>Total schedules:</strong> '
            . number_format(count($schedules)) . '</span>
        <span><strong>Accumulated depreciation:</strong> PHP '
            . number_format($totalAccumulated, 2) . '</span>
        <span><strong>Remaining depreciable amount:</strong> PHP '
            . number_format($totalRemaining, 2) . '</span>
        <span><strong>Current book value:</strong> PHP '
            . number_format($totalBookValue, 2) . '</span>
        <span><strong>Future entries:</strong> '
            . number_format($totalFutureEntries) . '</span>
    </div>

    <table class="schedules">
        <thead>
            <tr>
                <th style="width: 7%;">Asset Code</th>
                <th style="width: 10%;">Asset Name</th>
                <th style="width: 6%;">Asset Tag</th>
                <th style="width: 8%;">Category</th>
                <th style="width: 6%;">Status</th>
                <th style="width: 5%;">Life (Years)</th>
                <th style="width: 5%;">Posted Periods</th>
                <th style="width: 7%;">Monthly Depreciation</th>
                <th style="width: 8%;">Accumulated Depreciation</th>
                <th style="width: 8%;">Remaining Depreciable Amount</th>
                <th style="width: 7%;">Book Value</th>
                <th style="width: 5%;">Progress</th>
                <th style="width: 5%;">Future Entries</th>
                <th style="width: 8%;">Last Posted Period</th>
            </tr>
        </thead>
        <tbody>';

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

        $scheduleStatus = (string) (
            $schedule['schedule_status'] ?? ''
        );

        $futureCount = (int) (
            $schedule['future_posted_periods'] ?? 0
        );

        $assetCode = (string) ($schedule['asset_code'] ?? '');
        $assetName = (string) ($schedule['asset_name'] ?? '');
        $assetTag = (string) ($schedule['asset_tag'] ?? '');
        $categoryName = (string) ($schedule['category_name'] ?? '');

        $html .= '<tr>
            <td>' . $escape($assetCode) . '</td>
            <td>' . $escape($assetName) . '</td>
            <td>' . $escape($assetTag !== '' ? $assetTag : '—') . '</td>
            <td>' . $escape($categoryName !== '' ? $categoryName : '—') . '</td>
            <td class="status">' . $escape(ucfirst($scheduleStatus)) . '</td>
            <td class="number">'
            . number_format(
                (float) ($schedule['useful_life_years'] ?? 0),
                2
            ) . '</td>
            <td class="number">'
            . number_format((int) ($schedule['posted_periods'] ?? 0))
            . '</td>
            <td class="number">'
            . number_format(
                (float) ($schedule['monthly_depreciation'] ?? 0),
                2
            ) . '</td>
            <td class="number">'
            . number_format($accumulatedDepreciation, 2) . '</td>
            <td class="number">'
            . number_format($remainingAmount, 2) . '</td>
            <td class="number">'
            . number_format(
                (float) ($schedule['current_book_value'] ?? 0),
                2
            ) . '</td>
            <td class="center">'
            . ($progress === null
                ? 'N/A'
                : number_format($progress, 1) . '%')
            . '</td>
            <td class="center">'
            . number_format($futureCount) . '</td>
            <td>'
            . $escape(
                $schedule['last_posted_period_end'] ?? '—'
            ) . '</td>
        </tr>';
    }

    if ($schedules === []) {
        $html .= '<tr>
            <td colspan="14" class="center">
                No depreciation schedules match the selected filter.
            </td>
        </tr>';
    }

    $html .= '
        </tbody>
    </table>

    <div class="footer">
        Inventory Tracker — Depreciation Monitoring Report
    </div>
</body>
</html>';

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);

    $pdf = new Dompdf($options);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();

    $filename = 'depreciation-monitoring-' . date('Y-m-d') . '.pdf';

    $pdf->stream($filename, [
        'Attachment' => true,
    ]);

    exit;
} catch (Throwable $exception) {
    error_log(
        'Depreciation schedule PDF export failed: '
        . $exception->getMessage()
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Unable to generate the depreciation schedule PDF. '
        . 'Check the application error log.'
    );
}
