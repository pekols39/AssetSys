<?php

declare(strict_types=1);

use App\Services\NotificationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$projectRoot = dirname(__DIR__);
$storageDir = $projectRoot . '/storage';
$logDir = $storageDir . '/logs';
$lockFile = $storageDir . '/email_queue_worker.lock';
$logFile = $logDir . '/email_queue_worker.log';

if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "ERROR: Unable to create the worker log directory.\n");
    exit(1);
}

$log = static function (string $message) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
};

// Rotate the active log when it exceeds 2 MB.
if (is_file($logFile) && filesize($logFile) > 2 * 1024 * 1024) {
    $archiveFile = $logDir . '/email_queue_worker_' . date('Ymd_His') . '.log';

    if (!rename($logFile, $archiveFile)) {
        fwrite(STDERR, "ERROR: Unable to rotate the worker log.\n");
        exit(1);
    }
}

// Remove rotated logs older than 30 days.
foreach (glob($logDir . '/email_queue_worker_*.log') ?: [] as $archiveFile) {
    if (filemtime($archiveFile) < time() - (30 * 24 * 60 * 60)) {
        @unlink($archiveFile);
    }
}

$limit = 20;

foreach (($argv ?? []) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches) === 1) {
        $limit = (int) $matches[1];
    }
}

if ($limit < 1 || $limit > 100) {
    fwrite(STDERR, "ERROR: --limit must be between 1 and 100.\n");
    exit(2);
}

$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {
    $log('ERROR: Unable to open the worker lock file.');
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $log('Another worker is already running. Exiting.');
    fclose($lockHandle);
    exit(0);
}

$exitCode = 0;

try {
    require_once $projectRoot . '/vendor/autoload.php';
    require_once $projectRoot . '/app/config/config.php';

    $service = new NotificationService();
    $processed = $service->processPendingEmails($limit);

    $log("Email queue worker finished. Processed: {$processed}");
} catch (Throwable $e) {
    error_log('[AssetSys Email Queue Worker] ' . $e->getMessage());

    $log('ERROR: Email queue processing failed. See the PHP error log.');
    $exitCode = 1;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);
