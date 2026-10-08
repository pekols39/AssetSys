<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class DepreciationSchedule
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
                ds.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                u.employee_number AS created_by_employee_id,
                u.first_name AS created_by_first_name,
                u.last_name AS created_by_last_name

            FROM depreciation_schedules ds

            INNER JOIN assets a
                ON a.id = ds.asset_id

            INNER JOIN users u
                ON u.id = ds.created_by

            WHERE ds.id = :id

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':id' => $id,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }

    public function getByAssetId(
        int $assetId
    ): array {
        $sql = "
            SELECT
                ds.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                u.employee_number AS created_by_employee_id,
                u.first_name AS created_by_first_name,
                u.last_name AS created_by_last_name

            FROM depreciation_schedules ds

            INNER JOIN assets a
                ON a.id = ds.asset_id

            INNER JOIN users u
                ON u.id = ds.created_by

            WHERE ds.asset_id = :asset_id

            ORDER BY
                ds.start_date DESC,
                ds.id DESC
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveByAssetId(
        int $assetId
    ): array|false {
        $sql = "
            SELECT
                ds.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                u.employee_number AS created_by_employee_id,
                u.first_name AS created_by_first_name,
                u.last_name AS created_by_last_name

            FROM depreciation_schedules ds

            INNER JOIN assets a
                ON a.id = ds.asset_id

            INNER JOIN users u
                ON u.id = ds.created_by

            WHERE ds.asset_id = :asset_id
              AND ds.schedule_status = 'active'

            ORDER BY
                ds.start_date DESC,
                ds.id DESC

            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':asset_id' => $assetId,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT
                ds.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                u.employee_number AS created_by_employee_id,
                u.first_name AS created_by_first_name,
                u.last_name AS created_by_last_name

            FROM depreciation_schedules ds

            INNER JOIN assets a
                ON a.id = ds.asset_id

            INNER JOIN users u
                ON u.id = ds.created_by

            ORDER BY
                ds.created_at DESC,
                ds.id DESC
        ";

        $stmt = $this->db->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        array $data
    ): int {
        $sql = "
            INSERT INTO depreciation_schedules (
                asset_id,
                depreciation_method,
                start_date,
                useful_life_years,
                original_cost,
                salvage_value,
                depreciable_amount,
                annual_depreciation,
                monthly_depreciation,
                accumulated_depreciation,
                current_book_value,
                schedule_status,
                created_by
            ) VALUES (
                :asset_id,
                :depreciation_method,
                :start_date,
                :useful_life_years,
                :original_cost,
                :salvage_value,
                :depreciable_amount,
                :annual_depreciation,
                :monthly_depreciation,
                :accumulated_depreciation,
                :current_book_value,
                :schedule_status,
                :created_by
            )
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':asset_id' =>
                $data['asset_id'],

            ':depreciation_method' =>
                $data['depreciation_method'],

            ':start_date' =>
                $data['start_date'],

            ':useful_life_years' =>
                $data['useful_life_years'],

            ':original_cost' =>
                $data['original_cost'],

            ':salvage_value' =>
                $data['salvage_value'],

            ':depreciable_amount' =>
                $data['depreciable_amount'],

            ':annual_depreciation' =>
                $data['annual_depreciation'],

            ':monthly_depreciation' =>
                $data['monthly_depreciation'],

            ':accumulated_depreciation' =>
                $data['accumulated_depreciation'],

            ':current_book_value' =>
                $data['current_book_value'],

            ':schedule_status' =>
                $data['schedule_status'],

            ':created_by' =>
                $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        array $data
    ): bool {
        $sql = "
            UPDATE depreciation_schedules
            SET
                depreciation_method =
                    :depreciation_method,

                start_date =
                    :start_date,

                useful_life_years =
                    :useful_life_years,

                original_cost =
                    :original_cost,

                salvage_value =
                    :salvage_value,

                depreciable_amount =
                    :depreciable_amount,

                annual_depreciation =
                    :annual_depreciation,

                monthly_depreciation =
                    :monthly_depreciation,

                accumulated_depreciation =
                    :accumulated_depreciation,

                current_book_value =
                    :current_book_value,

                schedule_status =
                    :schedule_status

            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' =>
                $id,

            ':depreciation_method' =>
                $data['depreciation_method'],

            ':start_date' =>
                $data['start_date'],

            ':useful_life_years' =>
                $data['useful_life_years'],

            ':original_cost' =>
                $data['original_cost'],

            ':salvage_value' =>
                $data['salvage_value'],

            ':depreciable_amount' =>
                $data['depreciable_amount'],

            ':annual_depreciation' =>
                $data['annual_depreciation'],

            ':monthly_depreciation' =>
                $data['monthly_depreciation'],

            ':accumulated_depreciation' =>
                $data['accumulated_depreciation'],

            ':current_book_value' =>
                $data['current_book_value'],

            ':schedule_status' =>
                $data['schedule_status'],
        ]);
    }

    public function updateFinancials(
        int $id,
        float $accumulatedDepreciation,
        float $currentBookValue
    ): bool {
        $sql = "
            UPDATE depreciation_schedules
            SET
                accumulated_depreciation =
                    :accumulated_depreciation,

                current_book_value =
                    :current_book_value

            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' =>
                $id,

            ':accumulated_depreciation' =>
                $accumulatedDepreciation,

            ':current_book_value' =>
                $currentBookValue,
        ]);
    }
}