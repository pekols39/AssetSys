<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class MaintenanceRecord
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                mr.*,
                a.asset_code,
                a.asset_tag,
                a.asset_name,
                v.name AS service_provider_name,
                created_user.first_name AS created_by_first_name,
                created_user.last_name AS created_by_last_name,
                completed_user.first_name AS completed_by_first_name,
                completed_user.last_name AS completed_by_last_name
             FROM maintenance_records mr
             INNER JOIN assets a
                ON a.id = mr.asset_id
             LEFT JOIN vendors v
                ON v.id = mr.service_provider_id
             INNER JOIN users created_user
                ON created_user.id = mr.created_by
             LEFT JOIN users completed_user
                ON completed_user.id = mr.completed_by
             WHERE mr.id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByAssetId(int $assetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                mr.*,
                v.name AS service_provider_name,
                created_user.first_name AS created_by_first_name,
                created_user.last_name AS created_by_last_name,
                completed_user.first_name AS completed_by_first_name,
                completed_user.last_name AS completed_by_last_name
             FROM maintenance_records mr
             LEFT JOIN vendors v
                ON v.id = mr.service_provider_id
             INNER JOIN users created_user
                ON created_user.id = mr.created_by
             LEFT JOIN users completed_user
                ON completed_user.id = mr.completed_by
             WHERE mr.asset_id = :asset_id
             ORDER BY
                COALESCE(mr.scheduled_date, DATE(mr.created_at)) DESC,
                mr.id DESC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO maintenance_records (
                asset_id,
                maintenance_type,
                title,
                description,
                service_provider_id,
                scheduled_date,
                started_at,
                completed_at,
                maintenance_status,
                cost,
                reference_number,
                findings,
                actions_taken,
                recommendations,
                next_maintenance_date,
                created_by,
                completed_by
             ) VALUES (
                :asset_id,
                :maintenance_type,
                :title,
                :description,
                :service_provider_id,
                :scheduled_date,
                :started_at,
                :completed_at,
                :maintenance_status,
                :cost,
                :reference_number,
                :findings,
                :actions_taken,
                :recommendations,
                :next_maintenance_date,
                :created_by,
                :completed_by
             )'
        );

        $stmt->execute([
            'asset_id' => $data['asset_id'],
            'maintenance_type' => $data['maintenance_type'],
            'title' => $data['title'],
            'description' => $data['description'],
            'service_provider_id' => $data['service_provider_id'],
            'scheduled_date' => $data['scheduled_date'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'maintenance_status' => $data['maintenance_status'],
            'cost' => $data['cost'],
            'reference_number' => $data['reference_number'],
            'findings' => $data['findings'],
            'actions_taken' => $data['actions_taken'],
            'recommendations' => $data['recommendations'],
            'next_maintenance_date' => $data['next_maintenance_date'],
            'created_by' => $data['created_by'],
            'completed_by' => $data['completed_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE maintenance_records
             SET maintenance_type = :maintenance_type,
                 title = :title,
                 description = :description,
                 service_provider_id = :service_provider_id,
                 scheduled_date = :scheduled_date,
                 started_at = :started_at,
                 completed_at = :completed_at,
                 maintenance_status = :maintenance_status,
                 cost = :cost,
                 reference_number = :reference_number,
                 findings = :findings,
                 actions_taken = :actions_taken,
                 recommendations = :recommendations,
                 next_maintenance_date = :next_maintenance_date,
                 completed_by = :completed_by
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'maintenance_type' => $data['maintenance_type'],
            'title' => $data['title'],
            'description' => $data['description'],
            'service_provider_id' => $data['service_provider_id'],
            'scheduled_date' => $data['scheduled_date'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'maintenance_status' => $data['maintenance_status'],
            'cost' => $data['cost'],
            'reference_number' => $data['reference_number'],
            'findings' => $data['findings'],
            'actions_taken' => $data['actions_taken'],
            'recommendations' => $data['recommendations'],
            'next_maintenance_date' => $data['next_maintenance_date'],
            'completed_by' => $data['completed_by'],
            'id' => $id,
        ]);

        return $stmt->rowCount() === 1;
    }
}