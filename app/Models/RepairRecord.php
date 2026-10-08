<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class RepairRecord
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                rr.*,
                a.asset_code,
                a.asset_name,
                v.name AS service_provider_name,
                cu.employee_number AS created_by_employee_id,
                cb.employee_number AS completed_by_employee_id
             FROM repair_records rr
             INNER JOIN assets a
                ON a.id = rr.asset_id
             LEFT JOIN vendors v
                ON v.id = rr.service_provider_id
             INNER JOIN users cu
                ON cu.id = rr.created_by
             LEFT JOIN users cb
                ON cb.id = rr.completed_by
             WHERE rr.id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return $record !== false ? $record : null;
    }

    public function getByAssetId(int $assetId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                rr.*,
                a.asset_code,
                a.asset_name,
                v.name AS service_provider_name,
                cu.employee_number AS created_by_employee_id,
                cb.employee_number AS completed_by_employee_id
             FROM repair_records rr
             INNER JOIN assets a
                ON a.id = rr.asset_id
             LEFT JOIN vendors v
                ON v.id = rr.service_provider_id
             INNER JOIN users cu
                ON cu.id = rr.created_by
             LEFT JOIN users cb
                ON cb.id = rr.completed_by
             WHERE rr.asset_id = :asset_id
             ORDER BY
                rr.reported_at DESC,
                rr.id DESC"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO repair_records (
                asset_id,
                repair_type,
                title,
                problem_description,
                service_provider_id,
                reported_at,
                started_at,
                completed_at,
                repair_status,
                cost,
                reference_number,
                diagnosis,
                repair_actions,
                parts_replaced,
                recommendations,
                warranty_claim,
                created_by,
                completed_by
            ) VALUES (
                :asset_id,
                :repair_type,
                :title,
                :problem_description,
                :service_provider_id,
                :reported_at,
                :started_at,
                :completed_at,
                :repair_status,
                :cost,
                :reference_number,
                :diagnosis,
                :repair_actions,
                :parts_replaced,
                :recommendations,
                :warranty_claim,
                :created_by,
                :completed_by
            )"
        );

        $stmt->execute([
            'asset_id' => $data['asset_id'],
            'repair_type' => $data['repair_type'],
            'title' => $data['title'],
            'problem_description' => $data['problem_description'],
            'service_provider_id' => $data['service_provider_id'],
            'reported_at' => $data['reported_at'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'repair_status' => $data['repair_status'],
            'cost' => $data['cost'],
            'reference_number' => $data['reference_number'],
            'diagnosis' => $data['diagnosis'],
            'repair_actions' => $data['repair_actions'],
            'parts_replaced' => $data['parts_replaced'],
            'recommendations' => $data['recommendations'],
            'warranty_claim' => $data['warranty_claim'],
            'created_by' => $data['created_by'],
            'completed_by' => $data['completed_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE repair_records
             SET
                repair_type = :repair_type,
                title = :title,
                problem_description = :problem_description,
                service_provider_id = :service_provider_id,
                reported_at = :reported_at,
                started_at = :started_at,
                completed_at = :completed_at,
                repair_status = :repair_status,
                cost = :cost,
                reference_number = :reference_number,
                diagnosis = :diagnosis,
                repair_actions = :repair_actions,
                parts_replaced = :parts_replaced,
                recommendations = :recommendations,
                warranty_claim = :warranty_claim,
                completed_by = :completed_by
             WHERE id = :id"
        );

        return $stmt->execute([
            'id' => $id,
            'repair_type' => $data['repair_type'],
            'title' => $data['title'],
            'problem_description' => $data['problem_description'],
            'service_provider_id' => $data['service_provider_id'],
            'reported_at' => $data['reported_at'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'repair_status' => $data['repair_status'],
            'cost' => $data['cost'],
            'reference_number' => $data['reference_number'],
            'diagnosis' => $data['diagnosis'],
            'repair_actions' => $data['repair_actions'],
            'parts_replaced' => $data['parts_replaced'],
            'recommendations' => $data['recommendations'],
            'warranty_claim' => $data['warranty_claim'],
            'completed_by' => $data['completed_by'],
        ]);
    }
}