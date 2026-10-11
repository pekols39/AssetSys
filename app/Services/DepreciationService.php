<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Services\AuthorizationService;
use App\Exceptions\DepreciationPeriodNotDueException;
use PDO;
use RuntimeException;

class DepreciationService
{
    private PDO $db;

    private Asset $assetModel;

    private DepreciationSchedule $scheduleModel;
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->assetModel =
            new Asset();

        $this->scheduleModel =
            new DepreciationSchedule();

        $this->authorization =
            new AuthorizationService();
    }

    /**
     * Create a depreciation schedule for an asset.
     */
    public function createSchedule(
        int $assetId,
        int $createdBy,
        ?string $startDate = null
    ): int {
        if ($assetId <= 0) {
            throw new RuntimeException(
                'Invalid asset.'
            );
        }

        if ($createdBy <= 0) {
            throw new RuntimeException(
                'Invalid user.'
            );
        }

        $asset =
            $this->assetModel->findById(
                $assetId
            );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess(
            $asset
        );

        if (
            (int) \App\Core\Auth::id()
            !== $createdBy
        ) {
            throw new RuntimeException(
                'Invalid creating user.'
            );
        }

        $activeSchedule =
            $this->scheduleModel
                ->getActiveByAssetId(
                    $assetId
                );

        if ($activeSchedule) {
            throw new RuntimeException(
                'This asset already has an active depreciation schedule.'
            );
        }

        $depreciationMethod =
            strtolower(
                trim(
                    (string) (
                        $asset['depreciation_method']
                        ?? ''
                    )
                )
            );

        if (
            $depreciationMethod === ''
            || $depreciationMethod === 'straight_line'
        ) {
            $depreciationMethod =
                'straight_line';
        }

        if (
            $depreciationMethod !== 'straight_line'
        ) {
            throw new RuntimeException(
                'Unsupported depreciation method.'
            );
        }

        $originalCost =
            $this->toMoney(
                $asset['purchase_cost'] ?? 0
            );

        $salvageValue =
            $this->toMoney(
                $asset['salvage_value'] ?? 0
            );

        $usefulLife =
            $this->toDecimal(
                $asset['useful_life_years'] ?? 0
            );

        if ($originalCost < 0) {
            throw new RuntimeException(
                'Asset purchase cost cannot be negative.'
            );
        }

        if ($salvageValue < 0) {
            throw new RuntimeException(
                'Salvage value cannot be negative.'
            );
        }

        if ($salvageValue > $originalCost) {
            throw new RuntimeException(
                'Salvage value cannot exceed the original cost.'
            );
        }

        if ($usefulLife <= 0) {
            throw new RuntimeException(
                'Useful life must be greater than zero.'
            );
        }

        $resolvedStartDate =
            $startDate
            ?: (
                $asset['purchase_date']
                ?? date('Y-m-d')
            );

        $this->validateDate(
            $resolvedStartDate,
            'Depreciation start date'
        );

        $depreciableAmount =
            $this->toMoney(
                $originalCost - $salvageValue
            );

        $annualDepreciation =
            $this->calculateAnnualDepreciation(
                $depreciableAmount,
                $usefulLife
            );

        $monthlyDepreciation =
            $this->toMoney(
                $annualDepreciation / 12
            );

        $currentBookValue =
            $this->toMoney(
                $originalCost
            );

        $data = [
            'asset_id' =>
                $assetId,

            'depreciation_method' =>
                $depreciationMethod,

            'start_date' =>
                $resolvedStartDate,

            'useful_life_years' =>
                $usefulLife,

            'original_cost' =>
                $originalCost,

            'salvage_value' =>
                $salvageValue,

            'depreciable_amount' =>
                $depreciableAmount,

            'annual_depreciation' =>
                $annualDepreciation,

            'monthly_depreciation' =>
                $monthlyDepreciation,

            'accumulated_depreciation' =>
                0.00,

            'current_book_value' =>
                $currentBookValue,

            'schedule_status' =>
                'active',

            'created_by' =>
                $createdBy,
        ];

        $this->db->beginTransaction();

        try {
            $scheduleId =
                $this->scheduleModel->create(
                    $data
                );

            if ($scheduleId <= 0) {
                throw new RuntimeException(
                    'Unable to create depreciation schedule.'
                );
            }

            /*
             * Keep the asset's current book value
             * synchronized with the new schedule.
             *
             * At schedule creation there is no
             * depreciation posted yet.
             */
            $stmt = $this->db->prepare(
                "
                UPDATE assets
                SET current_book_value =
                    :current_book_value

                WHERE id = :asset_id
                "
            );

            $stmt->execute([
                ':current_book_value' =>
                    $currentBookValue,

                ':asset_id' =>
                    $assetId,
            ]);

            $this->db->commit();

            return $scheduleId;

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function authorizeAssetAccess(
        array $asset
    ): void {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (
            !$this->authorization->isAssetManager()
            && !$this->authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to manage depreciation.'
            );
        }

        $authenticatedUserId =
            \App\Core\Auth::id();

        if ($authenticatedUserId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $userDepartmentId =
            $this->authorization->departmentId();

        $assetDepartmentId =
            $asset['department_id'] === null
            ? null
            : (int) $asset['department_id'];

        if (
            $userDepartmentId === null
            || $assetDepartmentId === null
            || $userDepartmentId !== $assetDepartmentId
        ) {
            throw new RuntimeException(
                'You may only manage depreciation for assets belonging to your department.'
            );
        }
    }

    /**
     * Validate the account used to audit automated depreciation.
     *
     * Automated posting is restricted to an active Administrator
     * and is callable only through the CLI entry point.
     */
    private function assertAutomationActor(
        int $userId
    ): void {
        if ($userId <= 0) {
            throw new RuntimeException(
                'A valid automation posting user is required.'
            );
        }

        $stmt = $this->db->prepare(
            "
        SELECT u.id
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        WHERE u.id = :user_id
          AND u.account_status = 'active'
          AND u.is_active = 1
          AND r.name = 'Administrator'
          AND r.is_active = 1
        LIMIT 1
        "
        );

        $stmt->execute([
            ':user_id' => $userId,
        ]);

        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException(
                'The automation posting user must be an active Administrator.'
            );
        }
    }

    /**
     * Calculate annual straight-line depreciation.
     */
    private function calculateAnnualDepreciation(
        float $depreciableAmount,
        float $usefulLife
    ): float {
        if ($usefulLife <= 0) {
            throw new RuntimeException(
                'Useful life must be greater than zero.'
            );
        }

        return $this->toMoney(
            $depreciableAmount / $usefulLife
        );
    }

    /**
     * Convert a value to money precision.
     */
    private function toMoney(
        mixed $value
    ): float {
        return round(
            (float) $value,
            2,
            PHP_ROUND_HALF_UP
        );
    }

    /**
     * Convert a value to decimal precision.
     */
    private function toDecimal(
        mixed $value
    ): float {
        return round(
            (float) $value,
            2,
            PHP_ROUND_HALF_UP
        );
    }

    /**
     * Validate YYYY-MM-DD date format.
     */
    private function validateDate(
        string $date,
        string $fieldName
    ): void {
        $dateObject =
            \DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $date
            );

        $errors =
            \DateTimeImmutable::getLastErrors();

        if (
            $dateObject === false
            || (
                $errors !== false
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $dateObject->format('Y-m-d') !== $date
        ) {
            throw new RuntimeException(
                $fieldName . ' must be a valid date.'
            );
        }
    }

    /**
     * Calculate the final date of a monthly depreciation period.
     *
     * The next period begins on the following day.
     * Clamp the next month's date to that month's last day
     * to avoid PHP's month-overflow behavior.
     */
    private function calculatePeriodEndDate(
        \DateTimeImmutable $periodStartDate,
        int $anchorDay
    ): \DateTimeImmutable {
        $nextMonth =
            $periodStartDate->modify(
                'first day of next month'
            );

        $dayOfMonth = min(
            $anchorDay,
            (int) $nextMonth->format('t')
        );

        $nextPeriodStartDate =
            $nextMonth->setDate(
                (int) $nextMonth->format('Y'),
                (int) $nextMonth->format('n'),
                $dayOfMonth
            );

        return $nextPeriodStartDate->modify('-1 day');
    }

    /**
     * Preview the next depreciation period without changing records.
     *
     * This method performs SELECT queries only.
     */
    public function previewNextPeriod(
        int $scheduleId,
        ?\DateTimeImmutable $asOf = null
    ): array {
        if ($scheduleId <= 0) {
            throw new RuntimeException(
                'Invalid depreciation schedule.'
            );
        }

        $schedule = $this->scheduleModel->findById(
            $scheduleId
        );

        if (!$schedule) {
            throw new RuntimeException(
                'Depreciation schedule not found.'
            );
        }

        if (
            ($schedule['schedule_status'] ?? '')
            !== 'active'
        ) {
            throw new RuntimeException(
                'This depreciation schedule is not active.'
            );
        }

        $startDate = (string) (
            $schedule['start_date'] ?? ''
        );

        $this->validateDate(
            $startDate,
            'Depreciation schedule start date'
        );

        $latestEntry = $this->getLatestPostedEntry(
            $scheduleId
        );

        if ($latestEntry) {
            $periodStart = (
                new \DateTimeImmutable(
                    (string) $latestEntry['period_end']
                )
            )->modify('+1 day')->format('Y-m-d');
        } else {
            $periodStart = $startDate;
        }

        $this->validateDate(
            $periodStart,
            'Next depreciation period start'
        );

        $periodStartDate = new \DateTimeImmutable(
            $periodStart
        );

        $scheduleAnchorDay = (int) (
            new \DateTimeImmutable($startDate)
        )->format('j');

        $periodEndDate = $this->calculatePeriodEndDate(
            $periodStartDate,
            $scheduleAnchorDay
        );

        $eligibleOn = $periodEndDate->modify('+1 day');

        $today = (
        $asOf ?? new \DateTimeImmutable('today')
        )->setTime(0, 0);

        $preview = [
            'schedule_id' => $scheduleId,
            'period_start' => $periodStartDate->format('Y-m-d'),
            'period_end' => $periodEndDate->format('Y-m-d'),
            'eligible_on' => $eligibleOn->format('Y-m-d'),
            'is_due' => $today >= $eligibleOn,
        ];

        if (!$preview['is_due']) {
            return $preview;
        }

        /*
         * Validate the calculation before declaring this schedule
         * eligible for posting.
         */
        $method = strtolower(
            trim((string) ($schedule['depreciation_method'] ?? ''))
        );

        if ($method !== 'straight_line') {
            throw new RuntimeException(
                'Unsupported depreciation method.'
            );
        }

        $originalCost = $this->toMoney(
            $schedule['original_cost'] ?? 0
        );

        $salvageValue = $this->toMoney(
            $schedule['salvage_value'] ?? 0
        );

        $depreciableAmount = $this->toMoney(
            $schedule['depreciable_amount'] ?? 0
        );

        $monthlyDepreciation = $this->toMoney(
            $schedule['monthly_depreciation'] ?? 0
        );

        $usefulLifeYears = $this->toDecimal(
            $schedule['useful_life_years'] ?? 0
        );

        $totalPeriods = (int) round(
            $usefulLifeYears * 12
        );

        $accumulatedDepreciation = $this->toMoney(
            $schedule['accumulated_depreciation'] ?? 0
        );

        $currentBookValue = $this->toMoney(
            $schedule['current_book_value'] ?? $originalCost
        );

        if (
            $originalCost < 0
            || $salvageValue < 0
            || $salvageValue > $originalCost
            || $depreciableAmount < 0
            || $totalPeriods <= 0
        ) {
            throw new RuntimeException(
                'Depreciation schedule values are invalid.'
            );
        }

        $remainingDepreciation = $this->toMoney(
            $depreciableAmount - $accumulatedDepreciation
        );

        $remainingBookValue = $this->toMoney(
            $currentBookValue - $salvageValue
        );

        if ($remainingDepreciation <= 0) {
            throw new RuntimeException(
                'This depreciation schedule has no remaining depreciation to post.'
            );
        }

        if (
            abs($remainingDepreciation - $remainingBookValue) > 0.005
        ) {
            throw new RuntimeException(
                'Depreciation schedule values are inconsistent.'
            );
        }

        if (
            $this->findEntryByPeriod(
                $scheduleId,
                $preview['period_start'],
                $preview['period_end']
            )
        ) {
            throw new RuntimeException(
                'Depreciation for this period has already been posted.'
            );
        }

        $postedPeriodCount = $this->getPostedPeriodCount(
            $scheduleId
        );

        $isFinalPeriod = (
            $postedPeriodCount + 1
        ) >= $totalPeriods;

        $maximumDepreciation = min(
            $remainingDepreciation,
            $remainingBookValue
        );

        $depreciationAmount = $this->toMoney(
            $isFinalPeriod
            ? $maximumDepreciation
            : min($monthlyDepreciation, $maximumDepreciation)
        );

        if ($depreciationAmount <= 0) {
            throw new RuntimeException(
                'Calculated depreciation amount is invalid.'
            );
        }

        $bookValueAfter = $this->toMoney(
            $currentBookValue - $depreciationAmount
        );

        $newAccumulatedDepreciation = $this->toMoney(
            $accumulatedDepreciation + $depreciationAmount
        );

        return array_merge($preview, [
            'depreciation_amount' => $depreciationAmount,
            'book_value_after' => $bookValueAfter,
            'expected_schedule_status' =>
                $newAccumulatedDepreciation >= $depreciableAmount
                ? 'completed'
                : 'active',
        ]);
    }

    /**
     * Preview all due periods for a schedule without writing records.
     *
     * Simulates each posting in memory so the dry run matches
     * the sequential catch-up behavior of the live scheduler.
     */
    public function previewDuePeriods(
        int $scheduleId,
        ?\DateTimeImmutable $asOf = null
    ): array {
        if ($scheduleId <= 0) {
            throw new RuntimeException(
                'Invalid depreciation schedule.'
            );
        }

        $schedule = $this->scheduleModel->findById($scheduleId);

        if (!$schedule) {
            throw new RuntimeException(
                'Depreciation schedule not found.'
            );
        }

        if (($schedule['schedule_status'] ?? '') !== 'active') {
            throw new RuntimeException(
                'This depreciation schedule is not active.'
            );
        }

        $startDate = (string) ($schedule['start_date'] ?? '');

        $this->validateDate(
            $startDate,
            'Depreciation schedule start date'
        );

        $latestEntry = $this->getLatestPostedEntry($scheduleId);

        $periodStart = $latestEntry
            ? (
                new \DateTimeImmutable(
                    (string) $latestEntry['period_end']
                )
            )->modify('+1 day')->format('Y-m-d')
            : $startDate;

        $this->validateDate(
            $periodStart,
            'Next depreciation period start'
        );

        $anchorDay = (int) (
            new \DateTimeImmutable($startDate)
        )->format('j');

        $today = (
        $asOf ?? new \DateTimeImmutable('today')
        )->setTime(0, 0);

        $originalCost = $this->toMoney(
            $schedule['original_cost'] ?? 0
        );

        $salvageValue = $this->toMoney(
            $schedule['salvage_value'] ?? 0
        );

        $depreciableAmount = $this->toMoney(
            $schedule['depreciable_amount'] ?? 0
        );

        $monthlyDepreciation = $this->toMoney(
            $schedule['monthly_depreciation'] ?? 0
        );

        $usefulLifeYears = $this->toDecimal(
            $schedule['useful_life_years'] ?? 0
        );

        $totalPeriods = (int) round(
            $usefulLifeYears * 12
        );

        $accumulatedDepreciation = $this->toMoney(
            $schedule['accumulated_depreciation'] ?? 0
        );

        $currentBookValue = $this->toMoney(
            $schedule['current_book_value'] ?? $originalCost
        );

        if (
            $originalCost < 0
            || $salvageValue < 0
            || $salvageValue > $originalCost
            || $depreciableAmount < 0
            || $usefulLifeYears <= 0
            || $totalPeriods <= 0
        ) {
            throw new RuntimeException(
                'Depreciation schedule values are invalid.'
            );
        }

        $method = strtolower(
            trim((string) ($schedule['depreciation_method'] ?? ''))
        );

        if ($method !== 'straight_line') {
            throw new RuntimeException(
                'Unsupported depreciation method.'
            );
        }

        $postedPeriodCount = $this->getPostedPeriodCount(
            $scheduleId
        );

        $periods = [];
        $nextPeriod = null;
        $completed = false;

        /*
         * Safety limit prevents a malformed schedule from causing
         * an unbounded preview loop.
         */
        $maximumPreviewPeriods = 1200;

        for (
            $iteration = 0;
            $iteration < $maximumPreviewPeriods;
            $iteration++
        ) {
            $this->validateDate(
                $periodStart,
                'Depreciation period start'
            );

            $periodStartDate = new \DateTimeImmutable(
                $periodStart
            );

            $periodEndDate = $this->calculatePeriodEndDate(
                $periodStartDate,
                $anchorDay
            );

            $periodEnd = $periodEndDate->format('Y-m-d');
            $eligibleOn = $periodEndDate->modify('+1 day');

            /*
             * Stop at the first period that has not ended.
             */
            if ($today < $eligibleOn) {
                $nextPeriod = [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'eligible_on' => $eligibleOn->format('Y-m-d'),
                    'is_due' => false,
                ];

                break;
            }

            $remainingDepreciation = $this->toMoney(
                $depreciableAmount - $accumulatedDepreciation
            );

            $remainingBookValue = $this->toMoney(
                $currentBookValue - $salvageValue
            );

            if ($remainingDepreciation <= 0) {
                throw new RuntimeException(
                    'This depreciation schedule has no remaining depreciation to post.'
                );
            }

            if (
                abs(
                    $remainingDepreciation - $remainingBookValue
                ) > 0.005
            ) {
                throw new RuntimeException(
                    'Depreciation schedule values are inconsistent.'
                );
            }

            if (
                $this->findEntryByPeriod(
                    $scheduleId,
                    $periodStart,
                    $periodEnd
                )
            ) {
                throw new RuntimeException(
                    'Depreciation for this period has already been posted.'
                );
            }

            $isFinalPeriod = (
                $postedPeriodCount + 1
            ) >= $totalPeriods;

            $maximumDepreciation = min(
                $remainingDepreciation,
                $remainingBookValue
            );

            $depreciationAmount = $this->toMoney(
                $isFinalPeriod
                ? $maximumDepreciation
                : min(
                    $monthlyDepreciation,
                    $maximumDepreciation
                )
            );

            if ($depreciationAmount <= 0) {
                throw new RuntimeException(
                    'Calculated depreciation amount is invalid.'
                );
            }

            $bookValueBefore = $currentBookValue;

            $bookValueAfter = $this->toMoney(
                $bookValueBefore - $depreciationAmount
            );

            $newAccumulatedDepreciation = $this->toMoney(
                $accumulatedDepreciation + $depreciationAmount
            );

            $newStatus = (
                $newAccumulatedDepreciation >= $depreciableAmount
            ) ? 'completed' : 'active';

            $periods[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'eligible_on' => $eligibleOn->format('Y-m-d'),
                'depreciation_amount' => $depreciationAmount,
                'book_value_before' => $bookValueBefore,
                'book_value_after' => $bookValueAfter,
                'accumulated_depreciation' => $newAccumulatedDepreciation,
                'expected_schedule_status' => $newStatus,
            ];

            /*
             * Advance only in memory. No database updates occur here.
             */
            $accumulatedDepreciation = $newAccumulatedDepreciation;
            $currentBookValue = $bookValueAfter;
            $postedPeriodCount++;

            if ($newStatus === 'completed') {
                $completed = true;
                break;
            }

            $periodStart = $periodEndDate
                ->modify('+1 day')
                ->format('Y-m-d');
        }

        if (
            !$completed
            && $nextPeriod === null
            && count($periods) >= $maximumPreviewPeriods
        ) {
            throw new RuntimeException(
                'The preview reached its maximum period limit.'
            );
        }

        return [
            'schedule_id' => $scheduleId,
            'periods' => $periods,
            'next_period' => $nextPeriod,
            'completed' => $completed,
        ];
    }

    /**
     * Post the next depreciation period for an active schedule.
     *
     * The method automatically determines the next period based
     * on the schedule start date and the latest posted entry.
     */
    public function postNextEntry(
        int $scheduleId,
        int $postedBy,
        bool $automated = false
    ): int {
        if ($scheduleId <= 0) {
            throw new RuntimeException(
                'Invalid depreciation schedule.'
            );
        }

        if ($postedBy <= 0) {
            throw new RuntimeException(
                'Invalid user.'
            );
        }

        if ($automated) {
            if (PHP_SAPI !== 'cli') {
                throw new RuntimeException(
                    'Automated depreciation can only run from the command line.'
                );
            }

            $this->assertAutomationActor($postedBy);
        }

        $this->db->beginTransaction();

        try {
            /*
             * Lock the schedule before reading its values
             * or calculating the next depreciation entry.
             */
            $lockStmt = $this->db->prepare(
                "
                SELECT
                    id,
                    asset_id,
                    accumulated_depreciation,
                    current_book_value,
                    schedule_status
                FROM depreciation_schedules
                WHERE id = :id
                FOR UPDATE
                "
            );

            $lockStmt->execute([
                ':id' => $scheduleId,
            ]);

            $lockedSchedule = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedSchedule) {
                throw new RuntimeException(
                    'Depreciation schedule not found.'
                );
            }

            if (($lockedSchedule['schedule_status'] ?? '') !== 'active') {
                throw new RuntimeException(
                    'This depreciation schedule is no longer active.'
                );
            }

            /*
             * Read the schedule only after acquiring the lock.
             */
            $schedule = $this->scheduleModel->findById($scheduleId);

            if (!$schedule) {
                throw new RuntimeException(
                    'Depreciation schedule not found.'
                );
            }

            $assetId =
                (int) (
                    $schedule['asset_id']
                    ?? 0
                );

            if ($assetId <= 0) {
                throw new RuntimeException(
                    'Invalid asset associated with depreciation schedule.'
                );
            }

            $asset =
                $this->assetModel->findById(
                    $assetId
                );

            if (!$asset) {
                throw new RuntimeException(
                    'Asset not found.'
                );
            }

            if (!$automated) {
                $this->authorizeAssetAccess(
                    $asset
                );

                if (
                    (int) \App\Core\Auth::id()
                    !== $postedBy
                ) {
                    throw new RuntimeException(
                        'Invalid posting user.'
                    );
                }
            }

            if (
                ($schedule['schedule_status'] ?? '')
                !== 'active'
            ) {
                throw new RuntimeException(
                    'This depreciation schedule is not active.'
                );
            }

            $depreciationMethod =
                strtolower(
                    trim(
                        (string) (
                            $schedule['depreciation_method']
                            ?? ''
                        )
                    )
                );

            if (
                $depreciationMethod
                !== 'straight_line'
            ) {
                throw new RuntimeException(
                    'Unsupported depreciation method.'
                );
            }

            $scheduleAssetId = $assetId;

            $originalCost =
                $this->toMoney(
                    $schedule['original_cost']
                    ?? 0
                );

            $salvageValue =
                $this->toMoney(
                    $schedule['salvage_value']
                    ?? 0
                );

            $depreciableAmount =
                $this->toMoney(
                    $schedule['depreciable_amount']
                    ?? 0
                );

            $monthlyDepreciation =
                $this->toMoney(
                    $schedule['monthly_depreciation']
                    ?? 0
                );

            $usefulLifeYears =
                $this->toDecimal(
                    $schedule['useful_life_years']
                    ?? 0
                );

            $totalPeriods =
                (int) round(
                    $usefulLifeYears * 12
                );

            if ($totalPeriods <= 0) {
                throw new RuntimeException(
                    'Useful life must result in at least one depreciation period.'
                );
            }

            $accumulatedDepreciation =
                $this->toMoney(
                    $schedule['accumulated_depreciation']
                    ?? 0
                );

            $currentBookValue =
                $this->toMoney(
                    $schedule['current_book_value']
                    ?? $originalCost
                );

            if ($salvageValue > $originalCost) {
                throw new RuntimeException(
                    'Salvage value cannot exceed original cost.'
                );
            }

            if ($depreciableAmount < 0) {
                throw new RuntimeException(
                    'Depreciable amount cannot be negative.'
                );
            }

            /*
             * If the schedule has already reached its
             * depreciable limit, there is nothing left to post.
             */
            $remainingDepreciation =
                $this->toMoney(
                    $depreciableAmount
                    - $accumulatedDepreciation
                );

            if ($remainingDepreciation <= 0) {
                throw new RuntimeException(
                    'This depreciation schedule has no remaining depreciation to post.'
                );
            }

            $postedPeriodCount =
                $this->getPostedPeriodCount(
                    $scheduleId
                );

            /*
             * Determine the next period.
             */
            $latestEntry = $this->getLatestPostedEntry(
                $scheduleId
            );

            if ($latestEntry) {
                $periodStart =
                    (
                        new \DateTimeImmutable(
                            (string) $latestEntry['period_end']
                        )
                    )->modify('+1 day')
                        ->format('Y-m-d');
            } else {
                $periodStart =
                    (string) $schedule['start_date'];
            }

            $this->validateDate(
                $periodStart,
                'Depreciation period start'
            );

            $periodStartDate =
                new \DateTimeImmutable(
                    $periodStart
                );

            $scheduleAnchorDay =
                (int) (
                    new \DateTimeImmutable(
                        (string) $schedule['start_date']
                    )
                )->format('j');

            $periodEndDate =
                $this->calculatePeriodEndDate(
                    $periodStartDate,
                    $scheduleAnchorDay
                );

            $periodEnd =
                $periodEndDate->format(
                    'Y-m-d'
                );

            /*
             * Do not post depreciation before the entire period has ended.
             * The next period becomes eligible on the day after period_end.
             */
            $eligibleOn =
                $periodEndDate->modify('+1 day');

            $today =
                new \DateTimeImmutable('today');

            if ($today < $eligibleOn) {
                throw new DepreciationPeriodNotDueException(
                    'The next depreciation period is not due yet. '
                    . 'It may be posted on or after '
                    . $eligibleOn->format('Y-m-d')
                    . '.'
                );
            }

            /*
             * Explicit duplicate-period protection.
             * The database UNIQUE constraint remains
             * the final protection.
             */
            if (
                $this->findEntryByPeriod(
                    $scheduleId,
                    $periodStart,
                    $periodEnd
                )
            ) {
                throw new RuntimeException(
                    'Depreciation for this period has already been posted.'
                );
            }

            /*
             * Use the configured monthly amount for normal
             * periods.
             *
             * The final scheduled period receives the exact
             * remaining depreciable amount. This absorbs any
             * rounding difference from the monthly amount.
             */

            $isFinalPeriod =
                ($postedPeriodCount + 1)
                >= $totalPeriods;

            /*
             * Calculate depreciation without exceeding either
             * the remaining depreciable amount or salvage value.
             */
            $bookValueBefore =
                $this->toMoney(
                    $currentBookValue
                );

            $remainingBookValue =
                $this->toMoney(
                    $bookValueBefore - $salvageValue
                );

            /*
             * Ensure the schedule's remaining balances agree.
             */
            if (
                abs(
                    $remainingDepreciation
                    - $remainingBookValue
                ) > 0.005
            ) {
                throw new RuntimeException(
                    'Depreciation schedule values are inconsistent. '
                    . 'Please review the schedule before posting.'
                );
            }

            $maximumDepreciation = min(
                $remainingDepreciation,
                $remainingBookValue
            );

            if ($isFinalPeriod) {
                $depreciationAmount = $maximumDepreciation;
            } else {
                $depreciationAmount = min(
                    $monthlyDepreciation,
                    $maximumDepreciation
                );
            }

            $depreciationAmount =
                $this->toMoney(
                    $depreciationAmount
                );

            if ($depreciationAmount <= 0) {
                throw new RuntimeException(
                    'Calculated depreciation amount is invalid.'
                );
            }

            $bookValueAfter =
                $this->toMoney(
                    $bookValueBefore - $depreciationAmount
                );

            $newAccumulatedDepreciation =
                $this->toMoney(
                    $accumulatedDepreciation
                    + $depreciationAmount
                );

            $newScheduleStatus =
                (
                    $newAccumulatedDepreciation
                    >= $depreciableAmount
                )
                ? 'completed'
                : 'active';

            $postedAt =
                date('Y-m-d H:i:s');

            /*
             * Insert the depreciation entry.
             */
            $entryStmt =
                $this->db->prepare(
                    "
                    INSERT INTO depreciation_entries (
                        depreciation_schedule_id,
                        asset_id,
                        period_start,
                        period_end,
                        depreciation_amount,
                        accumulated_depreciation,
                        book_value_before,
                        book_value_after,
                        entry_status,
                        posted_by,
                        posted_at
                    ) VALUES (
                        :depreciation_schedule_id,
                        :asset_id,
                        :period_start,
                        :period_end,
                        :depreciation_amount,
                        :accumulated_depreciation,
                        :book_value_before,
                        :book_value_after,
                        'posted',
                        :posted_by,
                        :posted_at
                    )
                    "
                );

            $entryStmt->execute([
                ':depreciation_schedule_id' =>
                    $scheduleId,

                ':asset_id' =>
                    $scheduleAssetId,

                ':period_start' =>
                    $periodStart,

                ':period_end' =>
                    $periodEnd,

                ':depreciation_amount' =>
                    $depreciationAmount,

                ':accumulated_depreciation' =>
                    $newAccumulatedDepreciation,

                ':book_value_before' =>
                    $bookValueBefore,

                ':book_value_after' =>
                    $bookValueAfter,

                ':posted_by' =>
                    $postedBy,

                ':posted_at' =>
                    $postedAt,
            ]);

            $entryId =
                (int) $this->db->lastInsertId();

            if ($entryId <= 0) {
                throw new RuntimeException(
                    'Unable to create depreciation entry.'
                );
            }

            /*
             * Update the depreciation schedule.
             */
            $scheduleStmt =
                $this->db->prepare(
                    "
                    UPDATE depreciation_schedules

                    SET
                        accumulated_depreciation =
                            :accumulated_depreciation,

                        current_book_value =
                            :current_book_value,

                        schedule_status =
                            :schedule_status

                    WHERE id = :id
                    "
                );

            $scheduleStmt->execute([
                ':accumulated_depreciation' =>
                    $newAccumulatedDepreciation,

                ':current_book_value' =>
                    $bookValueAfter,

                ':schedule_status' =>
                    $newScheduleStatus,

                ':id' =>
                    $scheduleId,
            ]);

            /*
             * Keep the asset's current book value
             * synchronized with the schedule.
             */
            $assetStmt =
                $this->db->prepare(
                    "
                    UPDATE assets

                    SET
                        current_book_value =
                            :current_book_value

                    WHERE id = :asset_id
                    "
                );

            $assetStmt->execute([
                ':current_book_value' =>
                    $bookValueAfter,

                ':asset_id' =>
                    $scheduleAssetId,
            ]);

            $this->db->commit();

            return $entryId;

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Get the latest posted entry using the service PDO.
     */
    private function getLatestPostedEntry(
        int $scheduleId
    ): array|false {
        $stmt =
            $this->db->prepare(
                "
                SELECT
                    id,
                    period_start,
                    period_end,
                    accumulated_depreciation,
                    book_value_before,
                    book_value_after

                FROM depreciation_entries

                WHERE depreciation_schedule_id =
                    :schedule_id

                  AND entry_status = 'posted'

                ORDER BY
                    period_end DESC,
                    id DESC

                LIMIT 1
                "
            );

        $stmt->execute([
            ':schedule_id' =>
                $scheduleId,
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $result ?: false;
    }

    /**
     * Count posted depreciation periods for a schedule.
     */
    private function getPostedPeriodCount(
        int $scheduleId
    ): int {
        $stmt =
            $this->db->prepare(
                "
                SELECT COUNT(*)
                FROM depreciation_entries

                WHERE depreciation_schedule_id =
                    :schedule_id

                  AND entry_status = 'posted'
                "
            );

        $stmt->execute([
            ':schedule_id' =>
                $scheduleId,
        ]);

        return (int) $stmt->fetchColumn();
    }


    /**
     * Find an existing entry for a specific period.
     */
    private function findEntryByPeriod(
        int $scheduleId,
        string $periodStart,
        string $periodEnd
    ): array|false {
        $stmt =
            $this->db->prepare(
                "
                SELECT
                    id,
                    entry_status

                FROM depreciation_entries

                WHERE depreciation_schedule_id =
                    :schedule_id

                  AND period_start =
                    :period_start

                  AND period_end =
                    :period_end

                LIMIT 1
                "
            );

        $stmt->execute([
            ':schedule_id' =>
                $scheduleId,

            ':period_start' =>
                $periodStart,

            ':period_end' =>
                $periodEnd,
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $result ?: false;
    }
}