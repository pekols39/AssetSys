<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);
$apply = in_array('--apply', $argv, true);

if (!$dryRun && !$apply) {
    fwrite(
        STDERR,
        "Choose --dry-run to inspect expired jobs or --apply to reconcile them.\n"
    );
    exit(2);
}

try {
    $db = Database::connection();

    $countStmt = $db->query(
        "SELECT COUNT(*)
         FROM niimbot_print_jobs
         WHERE status = 'processing'
           AND lease_expires_at IS NOT NULL
           AND lease_expires_at < NOW()"
    );
    $expiredCount = (int) $countStmt->fetchColumn();

    if ($dryRun) {
        printf("Expired NIIMBOT jobs requiring review: %d (dry run; no changes made).\n", $expiredCount);
        exit(0);
    }

    $update = $db->prepare(
        "UPDATE niimbot_print_jobs
         SET status = 'failed',
             error_message = 'Printer lease expired; physical print outcome is unknown. Verify the label before manually requesting another print.',
             lease_expires_at = NULL
         WHERE status = 'processing'
           AND lease_expires_at IS NOT NULL
           AND lease_expires_at < NOW()"
    );
    $update->execute();

    printf(
        "NIIMBOT expired-job reconciliation complete. Marked for manual review: %d.\n",
        $update->rowCount()
    );
    exit(0);
} catch (Throwable $e) {
    error_log(
        'NIIMBOT expired-job reconciliation failed: '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );
    fwrite(STDERR, "NIIMBOT expired-job reconciliation failed. Check the PHP error log.\n");
    exit(1);
}
