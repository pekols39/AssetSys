<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class AssetReportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Retrieve asset inventory records with optional filters and pagination.
     *
     * If $limit is null, all matching records are returned.
     */
    public function getAssetInventory(
        array $filters = [],
        ?int $limit = null,
        int $offset = 0
    ): array {
        if (
            $offset < 0
            || (
                $limit !== null
                && ($limit < 1 || $limit > 100)
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid asset inventory pagination settings.'
            );
        }

        $sql = '
        SELECT
            a.id,
            a.asset_code,
            a.asset_tag,
            a.serial_number,
            a.asset_name,
            a.manufacturer,
            a.model,
            a.model_number,
            a.purchase_date,
            a.purchase_cost,
            a.current_book_value,

            c.name AS category_name,
            s.name AS status_name,
            ac.name AS condition_name,
            l.name AS location_name,
            v.name AS supplier_name,

            assigned.first_name AS assigned_first_name,
            assigned.last_name AS assigned_last_name

        FROM assets a

        INNER JOIN asset_categories c
            ON c.id = a.category_id

        INNER JOIN asset_statuses s
            ON s.id = a.status_id

        LEFT JOIN asset_conditions ac
            ON ac.id = a.condition_id

        LEFT JOIN locations l
            ON l.id = a.location_id

        LEFT JOIN vendors v
            ON v.id = a.supplier_id

        LEFT JOIN users assigned
            ON assigned.id = a.assigned_to_user_id
    ';

        [$whereSql, $params] =
            $this->buildAssetInventoryFilters($filters);

        $sql .= $whereSql . ' ORDER BY a.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        if ($limit !== null) {
            $stmt->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':offset',
                $offset,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get totals for all matching inventory records.
     *
     * These values are independent of pagination.
     */
    public function getAssetInventorySummary(
        array $filters = []
    ): array {
        $sql = '
        SELECT
            COUNT(*) AS total_assets,

            COALESCE(
                SUM(a.purchase_cost),
                0
            ) AS total_purchase_cost,

            COALESCE(
                SUM(a.current_book_value),
                0
            ) AS total_book_value

        FROM assets a

        INNER JOIN asset_categories c
            ON c.id = a.category_id

        INNER JOIN asset_statuses s
            ON s.id = a.status_id

        LEFT JOIN asset_conditions ac
            ON ac.id = a.condition_id

        LEFT JOIN locations l
            ON l.id = a.location_id

        LEFT JOIN vendors v
            ON v.id = a.supplier_id

        LEFT JOIN users assigned
            ON assigned.id = a.assigned_to_user_id
    ';

        [$whereSql, $params] =
            $this->buildAssetInventoryFilters($filters);

        $sql .= $whereSql;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        return $summary ?: [
            'total_assets' => 0,
            'total_purchase_cost' => 0.00,
            'total_book_value' => 0.00,
        ];
    }

    /**
     * Build the department and filter conditions shared by inventory queries.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function buildAssetInventoryFilters(
        array $filters
    ): array {
        $whereSql = '
        WHERE 1 = 1
    ';

        $params = [];

        $filterColumns = [
            'category_id' => 'a.category_id',
            'status_id' => 'a.status_id',
            'condition_id' => 'a.condition_id',
            'location_id' => 'a.location_id',
        ];

        foreach ($filterColumns as $filter => $column) {
            $value = $filters[$filter] ?? '';

            if (
                $value !== ''
                && $value !== null
                && filter_var(
                    $value,
                    FILTER_VALIDATE_INT
                ) !== false
                && (int) $value > 0
            ) {
                $whereSql .= " AND {$column} = :{$filter}";

                $params[$filter] = (int) $value;
            }
        }

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        if ($search !== '') {
            $whereSql .= '
            AND (
                a.asset_code LIKE :search_code
                OR a.asset_tag LIKE :search_tag
                OR a.serial_number LIKE :search_serial
                OR a.asset_name LIKE :search_name
                OR a.manufacturer LIKE :search_manufacturer
                OR a.model LIKE :search_model
                OR v.name LIKE :search_supplier
                OR assigned.first_name LIKE :search_first_name
                OR assigned.last_name LIKE :search_last_name
            )
        ';

            $searchTerm = '%' . $search . '%';

            $params['search_code'] = $searchTerm;
            $params['search_tag'] = $searchTerm;
            $params['search_serial'] = $searchTerm;
            $params['search_name'] = $searchTerm;
            $params['search_manufacturer'] = $searchTerm;
            $params['search_model'] = $searchTerm;
            $params['search_supplier'] = $searchTerm;
            $params['search_first_name'] = $searchTerm;
            $params['search_last_name'] = $searchTerm;
        }

        return [$whereSql, $params];
    }

    /**
     * Get totals for all posted depreciation entries in a month.
     *
     * Totals are independent of the current pagination page.
     */
    public function getDepreciationReportSummary(
        int $year,
        int $month
    ): array {
        if (
            $year < 1000
            || $year > 9999
            || $month < 1
            || $month > 12
        ) {
            throw new \InvalidArgumentException(
                'A valid reporting month and year are required.'
            );
        }

        $periodStart = sprintf(
            '%04d-%02d-01',
            $year,
            $month
        );

        $nextMonth = (
            new \DateTimeImmutable($periodStart)
        )->modify('+1 month')->format('Y-m-d');

        $sql = "
            SELECT
                COUNT(*) AS total_entries,
                COALESCE(
                    SUM(de.depreciation_amount),
                    0
                ) AS total_depreciation

            FROM depreciation_entries de

            INNER JOIN assets a
                ON a.id = de.asset_id

            INNER JOIN depreciation_schedules ds
                ON ds.id = de.depreciation_schedule_id

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            LEFT JOIN users poster
                ON poster.id = de.posted_by

            WHERE de.entry_status = 'posted'
            AND de.period_end >= :period_start
            AND de.period_end < :next_month
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':period_start' => $periodStart,
            ':next_month' => $nextMonth,
        ]);

        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        return $summary ?: [
            'total_entries' => 0,
            'total_depreciation' => 0.00,
        ];
    }

    /**
     * Retrieve posted depreciation entries for a calendar month.
     *
     * The report month is determined by period_end.
     * Results are restricted to the service's department.
     */
    public function getDepreciationReport(
        int $year,
        int $month,
        ?int $limit = null,
        int $offset = 0
    ): array {
        if (
            $year < 1000
            || $year > 9999
            || $month < 1
            || $month > 12
        ) {
            throw new \InvalidArgumentException(
                'A valid reporting month and year are required.'
            );
        }

        if (
            $offset < 0
            || (
                $limit !== null
                && ($limit < 1 || $limit > 100)
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid depreciation report pagination settings.'
            );
        }

        $periodStart = sprintf(
            '%04d-%02d-01',
            $year,
            $month
        );

        $nextMonth = (
            new \DateTimeImmutable($periodStart)
        )->modify('+1 month')->format('Y-m-d');

        $sql = "
            SELECT
                de.id AS entry_id,
                de.period_start,
                de.period_end,
                de.depreciation_amount,
                de.accumulated_depreciation,
                de.book_value_before,
                de.book_value_after,
                de.posted_at,

                a.id AS asset_id,
                a.asset_code,
                a.asset_tag,
                a.asset_name,

                c.name AS category_name,

                ds.depreciation_method,
                ds.useful_life_years,
                ds.original_cost,
                ds.salvage_value,

                CONCAT_WS(
                    ' ',
                    poster.first_name,
                    poster.last_name
                ) AS posted_by_name

            FROM depreciation_entries de

            INNER JOIN assets a
                ON a.id = de.asset_id

            INNER JOIN depreciation_schedules ds
                ON ds.id = de.depreciation_schedule_id

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            LEFT JOIN users poster
                ON poster.id = de.posted_by

            WHERE de.entry_status = 'posted'
              AND de.period_end >= :period_start
              AND de.period_end < :next_month

            ORDER BY
                de.period_end ASC,
                a.asset_code ASC,
                de.id ASC
        ";

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->bindValue(
            ':period_start',
            $periodStart,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':next_month',
            $nextMonth,
            PDO::PARAM_STR
        );

        if ($limit !== null) {
            $stmt->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':offset',
                $offset,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve depreciation schedules for the current department.
     *
     * Includes posted-period counts and identifies entries whose
     * period-end date is still in the future.
     */
    public function getDepreciationScheduleOverview(
        ?int $limit = null,
        int $offset = 0,
        ?string $status = null
    ): array {

        $allowedStatuses = [
            'active',
            'completed',
            'cancelled',
        ];

        if (
            $offset < 0
            || (
                $limit !== null
                && ($limit < 1 || $limit > 100)
            )
            || (
                $status !== null
                && !in_array($status, $allowedStatuses, true)
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid depreciation schedule filter or pagination settings.'
            );
        }

        $today = date('Y-m-d');

        $sql = "
            SELECT
                ds.id AS schedule_id,
                ds.start_date,
                ds.useful_life_years,
                ds.original_cost,
                ds.salvage_value,
                ds.depreciable_amount,
                ds.monthly_depreciation,
                ds.accumulated_depreciation,
                ds.current_book_value,
                ds.schedule_status,
                ds.created_at,

                a.id AS asset_id,
                a.asset_code,
                a.asset_tag,
                a.asset_name,

                c.name AS category_name,

                COALESCE(
                    entry_summary.posted_periods,
                    0
                ) AS posted_periods,

                entry_summary.last_posted_period_end,

                COALESCE(
                    entry_summary.future_posted_periods,
                    0
                ) AS future_posted_periods,

                entry_summary.latest_future_period_end

            FROM depreciation_schedules ds

            INNER JOIN assets a
                ON a.id = ds.asset_id

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            LEFT JOIN (
                SELECT
                    de.depreciation_schedule_id,

                    COUNT(*) AS posted_periods,

                    MAX(de.period_end)
                        AS last_posted_period_end,

                    SUM(
                        CASE
                            WHEN de.period_end > :future_cutoff_count
                            THEN 1
                            ELSE 0
                        END
                    ) AS future_posted_periods,

                    MAX(
                        CASE
                            WHEN de.period_end > :future_cutoff_latest
                            THEN de.period_end
                            ELSE NULL
                        END
                    ) AS latest_future_period_end

                FROM depreciation_entries de

                WHERE de.entry_status = 'posted'

                GROUP BY de.depreciation_schedule_id
            ) entry_summary
                ON entry_summary.depreciation_schedule_id = ds.id

            WHERE 1 = 1
            ";

        if ($status !== null) {
            $sql .= " AND ds.schedule_status = :schedule_status";
        }

        $sql .= "
            ORDER BY
                CASE ds.schedule_status
                    WHEN 'active' THEN 0
                    WHEN 'completed' THEN 1
                    ELSE 2
                END,
                ds.start_date DESC,
                ds.id DESC
        ";

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->bindValue(
            ':future_cutoff_count',
            $today,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':future_cutoff_latest',
            $today,
            PDO::PARAM_STR
        );

        if ($status !== null) {
            $stmt->bindValue(
                ':schedule_status',
                $status,
                PDO::PARAM_STR
            );
        }

        if ($limit !== null) {
            $stmt->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ':offset',
                $offset,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count depreciation schedules for the current department,
     * optionally filtered by schedule status.
     */
    public function countDepreciationScheduleOverview(
        ?string $status = null
    ): int {
        $allowedStatuses = [
            'active',
            'completed',
            'cancelled',
        ];

        if (
            $status !== null
            && !in_array($status, $allowedStatuses, true)
        ) {
            throw new \InvalidArgumentException(
                'Invalid depreciation schedule status.'
            );
        }

        $sql = "
        SELECT COUNT(ds.id)
        FROM depreciation_schedules ds
        INNER JOIN assets a
            ON a.id = ds.asset_id
        INNER JOIN asset_categories c
            ON c.id = a.category_id
        WHERE 1 = 1
    ";

        if ($status !== null) {
            $sql .= " AND ds.schedule_status = :schedule_status";
        }

        $stmt = $this->db->prepare($sql);

        if ($status !== null) {
            $stmt->bindValue(
                ':schedule_status',
                $status,
                PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Calculate summary totals for every depreciation schedule
     * belonging to the current department.
     *
     * These totals do not depend on pagination.
     */
    public function getDepreciationScheduleOverviewSummary(): array
    {
        $today = date('Y-m-d');

        $sql = "
        SELECT
            COUNT(ds.id) AS total_schedules,

            COALESCE(
                SUM(
                    CASE
                        WHEN ds.schedule_status = 'active'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS active_schedules,

            COALESCE(
                SUM(
                    CASE
                        WHEN ds.schedule_status = 'completed'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS completed_schedules,

            COALESCE(
                SUM(ds.depreciable_amount),
                0
            ) AS total_depreciable_amount,

            COALESCE(
                SUM(ds.accumulated_depreciation),
                0
            ) AS total_accumulated_depreciation,

            COALESCE(
                SUM(ds.current_book_value),
                0
            ) AS total_book_value,

            COALESCE(
                SUM(
                    COALESCE(
                        entry_summary.future_posted_periods,
                        0
                    )
                ),
                0
            ) AS total_future_posted_periods

        FROM depreciation_schedules ds

        INNER JOIN assets a
            ON a.id = ds.asset_id

        INNER JOIN asset_categories c
            ON c.id = a.category_id

        LEFT JOIN (
            SELECT
                de.depreciation_schedule_id,

                SUM(
                    CASE
                        WHEN de.period_end > :today
                        THEN 1
                        ELSE 0
                    END
                ) AS future_posted_periods

            FROM depreciation_entries de

            WHERE de.entry_status = 'posted'

            GROUP BY de.depreciation_schedule_id
        ) entry_summary
            ON entry_summary.depreciation_schedule_id = ds.id

    ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':today' => $today,
        ]);

        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        return $summary ?: [
            'total_schedules' => 0,
            'active_schedules' => 0,
            'completed_schedules' => 0,
            'total_depreciable_amount' => 0.00,
            'total_accumulated_depreciation' => 0.00,
            'total_book_value' => 0.00,
            'total_future_posted_periods' => 0,
        ];
    }
}