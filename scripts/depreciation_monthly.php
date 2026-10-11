<?php

declare(strict_types=1);

use App\Core\Database;
use App\Exceptions\DepreciationPeriodNotDueException;
use App\Services\DepreciationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/app/config/config.php';

/*
 * Usage:
 * php scripts/depreciation_monthly.php
 * php scripts/depreciation_monthly.php --dry-run
 * php scripts/depreciation_monthly.php --dry-run --as-of=2026-11-08
 *
 * Live posting requires:
 * php scripts/depreciation_monthly.php --execute
 */
$arguments = $argv ?? [];

$execute = in_array(
    '--execute',
    $arguments,
    true
);

$dryRunOption = in_array(
    '--dry-run',
    $arguments,
    true
);

/*
 * Never allow conflicting execution modes.
 */
if ($execute && $dryRunOption) {
    fwrite(
        STDERR,
        'ERROR: Use either --dry-run or --execute, not both.'
        . PHP_EOL
    );

    exit(2);
}

/*
 * Read-only preview is the default.
 * Database writes require explicit --execute.
 */
$dryRun = !$execute;

$logPath =
    ASSETSYS_ROOT
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'logs'
    . DIRECTORY_SEPARATOR
    . 'depreciation-scheduler.log';

$logDirectory = dirname($logPath);

if (
    !is_dir($logDirectory)
    && !@mkdir($logDirectory, 0750, true)
    && !is_dir($logDirectory)
) {
    fwrite(
        STDERR,
        '[WARNING] Could not create the scheduler log directory.'
        . PHP_EOL
    );
}

$writeLog = static function (string $level, string $message) use ($logPath): void {
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $level,
        $message,
        PHP_EOL
    );

    $logDirectory = dirname($logPath);

    $written = false;

    if (
        is_dir($logDirectory)
        && is_writable($logDirectory)
    ) {
        $written =
            file_put_contents(
                $logPath,
                $line,
                FILE_APPEND | LOCK_EX
            ) !== false;
    }

    fwrite(
        $level === 'ERROR' ? STDERR : STDOUT,
        $line
    );

    if (!$written) {
        fwrite(
            STDERR,
            "[WARNING] Could not write the scheduler log file."
            . PHP_EOL
        );
    }
};

try {

    /*
     * A simulated date is allowed only during dry runs.
     */
    $asOfArgument = null;

    foreach (($argv ?? []) as $argument) {
        if (str_starts_with($argument, '--as-of=')) {
            if (!$dryRun) {
                throw new \RuntimeException(
                    '--as-of can only be used with --dry-run.'
                );
            }

            if ($asOfArgument !== null) {
                throw new \RuntimeException(
                    'Specify --as-of only once.'
                );
            }

            $asOfArgument = substr($argument, strlen('--as-of='));
        }
    }

    $asOfDate = null;

    if ($asOfArgument !== null) {
        $asOfDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $asOfArgument
        );

        $dateErrors = \DateTimeImmutable::getLastErrors();

        if (
            $asOfDate === false
            || (
                $dateErrors !== false
                && (
                    $dateErrors['warning_count'] > 0
                    || $dateErrors['error_count'] > 0
                )
            )
            || $asOfDate->format('Y-m-d') !== $asOfArgument
        ) {
            throw new \RuntimeException(
                'The simulated date must use a valid YYYY-MM-DD date.'
            );
        }
    }

    $today = (
    $asOfDate ?? new \DateTimeImmutable('today')
    )->format('Y-m-d');

    if ($asOfDate !== null) {
        $writeLog(
            'INFO',
            "DRY RUN only: using simulated date {$today}. "
            . 'No depreciation entries will be written.'
        );
    }

    $automationUserId =
        (int) (
            $_ENV['DEPRECIATION_AUTOMATION_USER_ID']
            ?? 0
        );

    if ($automationUserId <= 0) {
        throw new \RuntimeException(
            'Set DEPRECIATION_AUTOMATION_USER_ID '
            . 'to an active Administrator ID in .env.'
        );
    }

    $db = Database::connection();

    /*
     * Validate the audit account before processing schedules.
     */
    $actorStmt = $db->prepare(
        "
        SELECT u.id
        FROM users AS u
        INNER JOIN roles AS r
            ON r.id = u.role_id
        WHERE u.id = :user_id
          AND u.account_status = 'active'
          AND u.is_active = 1
          AND r.name = 'Administrator'
          AND r.is_active = 1
        LIMIT 1
        "
    );

    $actorStmt->execute([
        ':user_id' => $automationUserId,
    ]);

    if ($actorStmt->fetchColumn() === false) {
        throw new \RuntimeException(
            'The configured automation account is not '
            . 'an active Administrator.'
        );
    }

    /*
     * Count all active schedules for the run summary.
     */
    $scheduleStmt = $db->query(
        "
    SELECT id
    FROM depreciation_schedules
    WHERE schedule_status = 'active'
    ORDER BY id ASC
    "
    );

    $allActiveScheduleIds = array_map(
        'intval',
        $scheduleStmt->fetchAll(\PDO::FETCH_COLUMN)
    );

    /*
     * Find active schedules with posted entries whose period end
     * is later than today. Preserve their history and skip them
     * until their future-dated entries no longer need special review.
     */

    $futureStmt = $db->prepare(
        "
    SELECT DISTINCT ds.id
    FROM depreciation_schedules ds
    INNER JOIN depreciation_entries de
        ON de.depreciation_schedule_id = ds.id
    WHERE ds.schedule_status = 'active'
      AND de.entry_status = 'posted'
      AND de.period_end > :today
    ORDER BY ds.id ASC
    "
    );

    $futureStmt->execute([
        ':today' => $today,
    ]);

    $futureScheduleIds = array_map(
        'intval',
        $futureStmt->fetchAll(\PDO::FETCH_COLUMN)
    );

    $futureScheduleLookup = array_fill_keys(
        $futureScheduleIds,
        true
    );

    foreach ($futureScheduleIds as $futureScheduleId) {
        $writeLog(
            'WARNING',
            "Schedule {$futureScheduleId} has a posted entry "
            . "with a future period-end date. Skipping this "
            . "schedule pending review."
        );
    }

    /*
     * Only process active schedules that do not have
     * future-dated posted entries.
     */
    $scheduleIds = array_values(
        array_filter(
            $allActiveScheduleIds,
            static fn(int $id): bool =>
                !isset($futureScheduleLookup[$id])
        )
    );

    $service = new DepreciationService();

    $posted = 0;
    $wouldPost = 0;
    $notDue = 0;
    $failed = 0;

    foreach ($scheduleIds as $scheduleId) {

        /*
         * Preview this schedule and never call postNextEntry()
         * when dry-run mode is enabled.
         */
        if ($dryRun) {
            try {
                $previewResult = $service->previewDuePeriods(
                    $scheduleId,
                    $asOfDate
                );

                $duePeriods = $previewResult['periods'];

                if ($duePeriods === []) {
                    $notDue++;

                    $nextPeriod = $previewResult['next_period'];

                    if ($nextPeriod !== null) {
                        $writeLog(
                            'INFO',
                            sprintf(
                                'DRY RUN: Schedule %d NOT DUE. Period %s to %s; eligible on %s.',
                                $scheduleId,
                                $nextPeriod['period_start'],
                                $nextPeriod['period_end'],
                                $nextPeriod['eligible_on']
                            )
                        );
                    }

                } else {
                    foreach ($duePeriods as $period) {
                        $wouldPost++;

                        $writeLog(
                            'INFO',
                            sprintf(
                                'DRY RUN: Schedule %d WOULD POST period %s to %s. '
                                . 'Depreciation: PHP %s; closing book value: PHP %s; '
                                . 'expected status: %s.',
                                $scheduleId,
                                $period['period_start'],
                                $period['period_end'],
                                number_format(
                                    (float) $period['depreciation_amount'],
                                    2,
                                    '.',
                                    ','
                                ),
                                number_format(
                                    (float) $period['book_value_after'],
                                    2,
                                    '.',
                                    ','
                                ),
                                $period['expected_schedule_status']
                            )
                        );
                    }

                    if ($previewResult['completed']) {
                        $writeLog(
                            'INFO',
                            "DRY RUN: Schedule {$scheduleId} would be completed."
                        );

                    } elseif ($previewResult['next_period'] !== null) {
                        $nextPeriod = $previewResult['next_period'];

                        $writeLog(
                            'INFO',
                            sprintf(
                                'DRY RUN: Schedule %d next period is not due: %s to %s; eligible on %s.',
                                $scheduleId,
                                $nextPeriod['period_start'],
                                $nextPeriod['period_end'],
                                $nextPeriod['eligible_on']
                            )
                        );
                    }
                }

            } catch (\Throwable $e) {
                $failed++;

                $writeLog(
                    'ERROR',
                    "DRY RUN: Schedule {$scheduleId} preview failed: "
                    . $e->getMessage()
                );
            }

            continue;
        }

        while (true) {
            try {
                $entryId = $service->postNextEntry(
                    $scheduleId,
                    $automationUserId,
                    true
                );

                $posted++;

                $writeLog(
                    'INFO',
                    "Posted entry {$entryId} for schedule {$scheduleId}."
                );

                /*
                 * Stop when the schedule has been completed.
                 * Otherwise, continue to the next due period.
                 */
                $statusStmt = $db->prepare(
                    "
                SELECT schedule_status
                FROM depreciation_schedules
                WHERE id = :id
                LIMIT 1
                "
                );

                $statusStmt->execute([
                    ':id' => $scheduleId,
                ]);

                $status = $statusStmt->fetchColumn();

                if ($status !== 'active') {
                    break;
                }

            } catch (DepreciationPeriodNotDueException $e) {
                /*
                 * No more due periods for this schedule.
                 */
                $notDue++;
                break;

            } catch (\Throwable $e) {
                /*
                 * Stop this schedule on an unexpected failure,
                 * log it, and continue with the next schedule.
                 */
                $failed++;

                $writeLog(
                    'ERROR',
                    "Schedule {$scheduleId} failed: "
                    . $e->getMessage()
                );

                break;
            }
        }
    }

    $writeLog(
        $failed > 0 ? 'ERROR' : 'INFO',
        sprintf(
            '%s complete: active=%d, skipped_future=%d, would_post=%d, posted=%d, not_due=%d, failed=%d.',
            $dryRun ? 'DRY RUN' : 'Run',
            count($allActiveScheduleIds),
            count($futureScheduleIds),
            $wouldPost,
            $posted,
            $notDue,
            $failed
        )
    );

    exit($failed > 0 ? 1 : 0);

} catch (\Throwable $e) {
    $writeLog(
        'ERROR',
        'Scheduler aborted: ' . $e->getMessage()
    );

    exit(1);
}