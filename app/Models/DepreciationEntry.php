<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class DepreciationEntry
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): array|false
    {
        $sql = "
            SELECT
                de.*,

                ds.depreciation_method,
                ds.start_date,
                ds.useful_life_years,
                ds.original_cost,
                ds.salvage_value,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                u.employee_number AS posted_by_employee_id,
                u.first_name AS posted_by_first_name,
                u.last_name AS posted_by_last_name

            FROM depreciation_entries de

            INNER JOIN depreciation_schedules ds
                ON ds.id = de.depreciation_schedule_id

            INNER JOIN assets a
                ON a.id = de.asset_id

            INNER JOIN users u
                ON u.id = de.posted_by

            WHERE de.id = :id

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':id' => $id,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }

    public function getByScheduleId(
        int $scheduleId
    ): array {
        $sql = "
            SELECT
                de.*,

                u.employee_number AS posted_by_employee_id,
                u.first_name AS posted_by_first_name,
                u.last_name AS posted_by_last_name

            FROM depreciation_entries de

            INNER JOIN users u
                ON u.id = de.posted_by

            WHERE de.depreciation_schedule_id =
                :depreciation_schedule_id

            ORDER BY
                de.period_start ASC,
                de.id ASC
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':depreciation_schedule_id' =>
                $scheduleId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByAssetId(
        int $assetId
    ): array {
        $sql = "
            SELECT
                de.*,

                ds.depreciation_method,
                ds.start_date,

                u.employee_number AS posted_by_employee_id,
                u.first_name AS posted_by_first_name,
                u.last_name AS posted_by_last_name

            FROM depreciation_entries de

            INNER JOIN depreciation_schedules ds
                ON ds.id = de.depreciation_schedule_id

            INNER JOIN users u
                ON u.id = de.posted_by

            WHERE de.asset_id = :asset_id

            ORDER BY
                de.period_start ASC,
                de.id ASC
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLatestPostedByScheduleId(
        int $scheduleId
    ): array|false {
        $sql = "
            SELECT
                de.*

            FROM depreciation_entries de

            WHERE de.depreciation_schedule_id =
                :depreciation_schedule_id

              AND de.entry_status = 'posted'

            ORDER BY
                de.period_end DESC,
                de.id DESC

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':depreciation_schedule_id' =>
                $scheduleId,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }

    public function findByPeriod(
        int $scheduleId,
        string $periodStart,
        string $periodEnd
    ): array|false {
        $sql = "
            SELECT
                de.*

            FROM depreciation_entries de

            WHERE de.depreciation_schedule_id =
                :depreciation_schedule_id

              AND de.period_start =
                :period_start

              AND de.period_end =
                :period_end

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':depreciation_schedule_id' =>
                $scheduleId,

            ':period_start' =>
                $periodStart,

            ':period_end' =>
                $periodEnd,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }

    public function create(
        array $data
    ): int {
        $sql = "
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
                posted_at,
                reversal_reason
            ) VALUES (
                :depreciation_schedule_id,
                :asset_id,
                :period_start,
                :period_end,
                :depreciation_amount,
                :accumulated_depreciation,
                :book_value_before,
                :book_value_after,
                :entry_status,
                :posted_by,
                :posted_at,
                :reversal_reason
            )
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':depreciation_schedule_id' =>
                $data['depreciation_schedule_id'],

            ':asset_id' =>
                $data['asset_id'],

            ':period_start' =>
                $data['period_start'],

            ':period_end' =>
                $data['period_end'],

            ':depreciation_amount' =>
                $data['depreciation_amount'],

            ':accumulated_depreciation' =>
                $data['accumulated_depreciation'],

            ':book_value_before' =>
                $data['book_value_before'],

            ':book_value_after' =>
                $data['book_value_after'],

            ':entry_status' =>
                $data['entry_status'] ?? 'posted',

            ':posted_by' =>
                $data['posted_by'],

            ':posted_at' =>
                $data['posted_at'],

            ':reversal_reason' =>
                $data['reversal_reason'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function reverse(
        int $id,
        string $reason
    ): bool {
        $sql = "
            UPDATE depreciation_entries
            SET
                entry_status = 'reversed',
                reversal_reason = :reversal_reason

            WHERE id = :id

              AND entry_status = 'posted'
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' =>
                $id,

            ':reversal_reason' =>
                $reason,
        ]);
    }
}