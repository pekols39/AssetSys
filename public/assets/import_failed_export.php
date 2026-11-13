<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to export import errors.');
}

$batchId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($batchId === false || $batchId <= 0) {
    http_response_code(400);
    exit('Invalid import batch.');
}

$service = new AssetImportService();
$batch = $service->getBatch((int) $batchId);
if (!$batch) {
    http_response_code(404);
    exit('Import batch not found.');
}

$headers = [
    'Excel Row',
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
    'Import Error',
];

$filename = 'asset-import-batch-' . (int) $batchId . '-failed-rows.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Unable to generate CSV export.');
}

// UTF-8 BOM for Excel compatibility.
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $headers);

$offset = 0;
$chunkSize = 500;

do {
    $rows = $service->getFailedRows((int) $batchId, $chunkSize, $offset);
    foreach ($rows as $row) {
        $values = $row['source_values'] ?? [];
        $line = [(int) $row['source_row_number']];
        foreach (array_slice($headers, 1, 12) as $header) {
            $line[] = $values[$header] ?? '';
        }
        $line[] = $row['validation_errors'] ?? '';
        fputcsv($output, $line);
    }
    $offset += count($rows);
} while (count($rows) === $chunkSize);

fclose($output);
exit;
