<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class DashboardService
{
    private PDO $db;
    private string $assetScopeColumn;
    private int $assetScopeId;

    public function __construct(
        int $departmentId,
        ?int $assignedToUserId = null
    ) {
        if ($assignedToUserId !== null) {
            if ($assignedToUserId <= 0) {
                throw new \InvalidArgumentException(
                    'A valid assigned user is required for dashboard access.'
                );
            }

            $this->assetScopeColumn = 'assigned_to_user_id';
            $this->assetScopeId = $assignedToUserId;
        } else {
            if ($departmentId <= 0) {
                throw new \InvalidArgumentException(
                    'A valid department is required for dashboard access.'
                );
            }

            $this->assetScopeColumn = 'department_id';
            $this->assetScopeId = $departmentId;
        }

        $this->db = Database::connection();
    }

    /**
     * Get the main dashboard summary cards.
     */
    public function getSummary(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_assets,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status_id = 2 THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS assigned_assets,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status_id = 1 THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS available_assets,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status_id = 3 THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS maintenance_assets,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status_id = 4 THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS repair_assets,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status_id = 9 THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS disposed_assets,

                COALESCE(
                    SUM(purchase_cost),
                    0
                ) AS total_purchase_value,

                COALESCE(
                    SUM(current_book_value),
                    0
                ) AS current_book_value

            FROM assets a
            WHERE a.{$this->assetScopeColumn} = :scope_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_assets' => (int) ($result['total_assets'] ?? 0),
            'assigned_assets' => (int) ($result['assigned_assets'] ?? 0),
            'available_assets' => (int) ($result['available_assets'] ?? 0),
            'maintenance_assets' => (int) ($result['maintenance_assets'] ?? 0),
            'repair_assets' => (int) ($result['repair_assets'] ?? 0),
            'disposed_assets' => (int) ($result['disposed_assets'] ?? 0),
            'total_purchase_value' => (float) ($result['total_purchase_value'] ?? 0),
            'current_book_value' => (float) ($result['current_book_value'] ?? 0),
        ];
    }

    /**
     * Get asset counts grouped by category.
     */
    public function getAssetsByCategory(): array
    {
        $sql = "
            SELECT
                c.name AS category_name,
                COUNT(a.id) AS asset_count
            FROM asset_categories c
            LEFT JOIN assets a
                ON a.category_id = c.id
                AND a.{$this->assetScopeColumn} = :scope_id
            GROUP BY
                c.id,
                c.name
            HAVING COUNT(a.id) > 0
            ORDER BY
                asset_count DESC,
                category_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get asset counts grouped by location.
     */
    public function getAssetsByLocation(): array
    {
        $sql = "
            SELECT
                l.name AS location_name,
                COUNT(a.id) AS asset_count
            FROM locations l
            LEFT JOIN assets a
                ON a.location_id = l.id
                AND a.{$this->assetScopeColumn} = :scope_id
            GROUP BY
                l.id,
                l.name
            HAVING COUNT(a.id) > 0
            ORDER BY
                asset_count DESC,
                location_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get asset counts grouped by status.
     */
    public function getAssetsByStatus(): array
    {
        $sql = "
            SELECT
                s.name AS status_name,
                COUNT(a.id) AS asset_count
            FROM asset_statuses s
            LEFT JOIN assets a
                ON a.status_id = s.id
                AND a.{$this->assetScopeColumn} = :scope_id
            GROUP BY
                s.id,
                s.name
            HAVING COUNT(a.id) > 0
            ORDER BY
                asset_count DESC,
                status_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get posted depreciation grouped by year.
     */
    public function getDepreciationByYear(): array
    {
        $sql = "
            SELECT
                YEAR(de.period_end) AS depreciation_year,
                COALESCE(
                    SUM(de.depreciation_amount),
                    0
                ) AS depreciation_amount
            FROM depreciation_entries de
            INNER JOIN assets a
                ON a.id = de.asset_id
            WHERE de.entry_status = 'posted'
              AND a.{$this->assetScopeColumn} = :scope_id
            GROUP BY YEAR(de.period_end)
            ORDER BY depreciation_year ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get maintenance costs grouped by year.
     */
    public function getMaintenanceCosts(): array
    {
        $sql = "
            SELECT
                YEAR(
                    COALESCE(
                        completed_at,
                        scheduled_date
                    )
                ) AS cost_year,
                COALESCE(
                    SUM(cost),
                    0
                ) AS maintenance_cost
            FROM maintenance_records m
            INNER JOIN assets a
                ON a.id = m.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND m.cost IS NOT NULL
              AND cost > 0
              AND COALESCE(
                    completed_at,
                    scheduled_date
                  ) IS NOT NULL
            GROUP BY
                YEAR(
                    COALESCE(
                        completed_at,
                        scheduled_date
                    )
                )
            ORDER BY cost_year ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get repair costs grouped by year.
     */
    public function getRepairCosts(): array
    {
        $sql = "
            SELECT
                YEAR(
                    COALESCE(
                        completed_at,
                        reported_at
                    )
                ) AS cost_year,
                COALESCE(
                    SUM(cost),
                    0
                ) AS repair_cost
            FROM repair_records r
            INNER JOIN assets a
                ON a.id = r.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND r.cost IS NOT NULL
              AND cost > 0
              AND COALESCE(
                    completed_at,
                    reported_at
                  ) IS NOT NULL
            GROUP BY
                YEAR(
                    COALESCE(
                        completed_at,
                        reported_at
                    )
                )
            ORDER BY cost_year ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get asset counts grouped by condition.
     */
    public function getAssetsByCondition(): array
    {
        $sql = "
            SELECT
                c.name AS condition_name,
                COUNT(a.id) AS asset_count
            FROM asset_conditions c
            INNER JOIN assets a
                ON a.condition_id = c.id
                AND a.{$this->assetScopeColumn} = :scope_id
            WHERE c.is_active = 1
            GROUP BY
                c.id,
                c.name
            HAVING COUNT(a.id) > 0
            ORDER BY
                asset_count DESC,
                condition_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Get upcoming and overdue maintenance records.
     */
    public function getUpcomingMaintenance(int $limit = 8): array
    {
        $limit = max(1, min($limit, 50));

        $sql = "
            SELECT
                m.id,
                m.asset_id,
                a.asset_code,
                a.asset_name,
                m.title,
                m.scheduled_date,
                m.next_maintenance_date,
                m.maintenance_status,
                    CASE
                        WHEN COALESCE(
                            m.next_maintenance_date,
                            m.scheduled_date
                        ) < CURDATE()
                            THEN 'overdue'
                        WHEN COALESCE(
                            m.next_maintenance_date,
                            m.scheduled_date
                        ) = CURDATE()
                            THEN 'due_today'
                        ELSE 'upcoming'
                    END AS attention_status
            FROM maintenance_records m
            INNER JOIN assets a
                ON a.id = m.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND m.maintenance_status IN (
                'scheduled',
                'in_progress'
              )
              AND (
                    (
                        m.next_maintenance_date IS NOT NULL
                        AND m.next_maintenance_date
                            <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    )
                    OR
                    (
                        m.next_maintenance_date IS NULL
                        AND m.scheduled_date IS NOT NULL
                        AND m.scheduled_date
                            <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    )
              )
            ORDER BY
                CASE
                    WHEN COALESCE(
                        m.next_maintenance_date,
                        m.scheduled_date
                    ) < CURDATE() THEN 0
                    WHEN COALESCE(
                        m.next_maintenance_date,
                        m.scheduled_date
                    ) = CURDATE() THEN 1
                    ELSE 2
                END ASC,
                COALESCE(
                    m.next_maintenance_date,
                    m.scheduled_date
                ) ASC,
                m.id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(
            ':scope_id',
            $this->assetScopeId,
            PDO::PARAM_INT
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get operational indicators.
     */
    public function getOperationalIndicators(): array
    {
        $today = date('Y-m-d');

        $maintenanceDueSql = "
            SELECT COUNT(*)
            FROM maintenance_records m
            INNER JOIN assets a
                ON a.id = m.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND m.next_maintenance_date = :today
              AND m.maintenance_status != 'cancelled'
        ";

        $stmt = $this->db->prepare($maintenanceDueSql);

        $stmt->execute([
            'today' => $today,
            'scope_id' => $this->assetScopeId,
        ]);

        $maintenanceDue = (int) $stmt->fetchColumn();

        $overdueMaintenanceSql = "
            SELECT COUNT(*)
            FROM maintenance_records m
            INNER JOIN assets a
                ON a.id = m.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND m.next_maintenance_date < :today
              AND m.maintenance_status != 'cancelled'
        ";

        $stmt = $this->db->prepare($overdueMaintenanceSql);

        $stmt->execute([
            'today' => $today,
            'scope_id' => $this->assetScopeId,
        ]);

        $overdueMaintenance = (int) $stmt->fetchColumn();

        $repairSql = "
            SELECT COUNT(*)
            FROM assets
            WHERE department_id = :department_id
              AND status_id = 4
        ";

        $stmt = $this->db->prepare($repairSql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        $assetsUnderRepair = (int) $stmt->fetchColumn();

        $repairCostSql = "
            SELECT COALESCE(
                SUM(r.cost),
                0
            )
            FROM repair_records r
            INNER JOIN assets a
                ON a.id = r.asset_id
            WHERE a.{$this->assetScopeColumn} = :scope_id
              AND r.cost IS NOT NULL
              AND r.cost > 0
        ";

        $stmt = $this->db->prepare($repairCostSql);
        $stmt->execute([
            'scope_id' => $this->assetScopeId,
        ]);

        $repairCosts = (float) $stmt->fetchColumn();

        $warrantySql = "
            SELECT COUNT(*)
            FROM assets
            WHERE department_id = :department_id
              AND warranty_end IS NOT NULL
              AND warranty_end >= :today_start
              AND warranty_end <= DATE_ADD(
                    :today_end,
                    INTERVAL 90 DAY
                )
        ";

        $stmt = $this->db->prepare($warrantySql);

        $stmt->execute([
            'scope_id' => $this->assetScopeId,
            'today_start' => $today,
            'today_end' => $today,
        ]);

        $warrantyExpiring = (int) $stmt->fetchColumn();

        return [
            'maintenance_due' => $maintenanceDue,
            'overdue_maintenance' => $overdueMaintenance,
            'assets_under_repair' => $assetsUnderRepair,
            'repair_costs' => $repairCosts,
            'warranty_expiring' => $warrantyExpiring,
        ];
    }
}