<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Services\AuthorizationService;
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
     * Post the next depreciation period for an active schedule.
     *
     * The method automatically determines the next period based
     * on the schedule start date and the latest posted entry.
     */
    public function postNextEntry(
        int $scheduleId,
        int $postedBy
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

        $schedule =
            $this->scheduleModel->findById(
                $scheduleId
            );

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

        $periodEndDate =
            $periodStartDate->modify(
                '+1 month -1 day'
            );

        $periodEnd =
            $periodEndDate->format(
                'Y-m-d'
            );

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

        if ($isFinalPeriod) {
            $depreciationAmount =
                $remainingDepreciation;
        } else {
            $depreciationAmount =
                $monthlyDepreciation;
        }

        $depreciationAmount =
            $this->toMoney(
                $depreciationAmount
            );

        if ($depreciationAmount <= 0) {
            throw new RuntimeException(
                'Calculated depreciation amount is zero.'
            );
        }

        $bookValueBefore =
            $this->toMoney(
                $currentBookValue
            );

        $bookValueAfter =
            $this->toMoney(
                $bookValueBefore
                - $depreciationAmount
            );

        /*
         * Never allow the book value to fall below
         * the salvage value.
         */
        if ($bookValueAfter < $salvageValue) {
            $bookValueAfter =
                $salvageValue;

            $depreciationAmount =
                $this->toMoney(
                    $bookValueBefore
                    - $bookValueAfter
                );
        }

        if ($depreciationAmount <= 0) {
            throw new RuntimeException(
                'Calculated depreciation amount is invalid.'
            );
        }

        $newAccumulatedDepreciation =
            $this->toMoney(
                $accumulatedDepreciation
                + $depreciationAmount
            );

        /*
         * Never allow accumulated depreciation to
         * exceed the depreciable amount.
         */
        if (
            $newAccumulatedDepreciation
            > $depreciableAmount
        ) {
            $newAccumulatedDepreciation =
                $depreciableAmount;

            $bookValueAfter =
                $this->toMoney(
                    $originalCost
                    - $newAccumulatedDepreciation
                );

            if ($bookValueAfter < $salvageValue) {
                $bookValueAfter =
                    $salvageValue;
            }

            $depreciationAmount =
                $this->toMoney(
                    $bookValueBefore
                    - $bookValueAfter
                );
        }

        $newScheduleStatus =
            (
                $newAccumulatedDepreciation
                >= $depreciableAmount
            )
            ? 'completed'
            : 'active';

        $postedAt =
            date('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            /*
             * Lock the schedule row so two requests
             * cannot post the same next period
             * simultaneously.
             */
            $lockStmt =
                $this->db->prepare(
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
                ':id' =>
                    $scheduleId,
            ]);

            $lockedSchedule =
                $lockStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$lockedSchedule) {
                throw new RuntimeException(
                    'Depreciation schedule not found.'
                );
            }

            if (
                ($lockedSchedule['schedule_status'] ?? '')
                !== 'active'
            ) {
                throw new RuntimeException(
                    'This depreciation schedule is no longer active.'
                );
            }

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