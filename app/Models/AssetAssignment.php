<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetAssignment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findActiveByAssetId(int $assetId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                aa.*,
                u.first_name,
                u.last_name,
                u.username,
                assigned_by_user.first_name AS assigned_by_first_name,
                assigned_by_user.last_name AS assigned_by_last_name
             FROM asset_assignments aa
             INNER JOIN users u
                ON u.id = aa.assigned_to_user_id
             INNER JOIN users assigned_by_user
                ON assigned_by_user.id = aa.assigned_by
             WHERE aa.asset_id = :asset_id
               AND aa.assignment_status = :status
             ORDER BY aa.id DESC
             LIMIT 1'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'status' => 'active',
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findActiveByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                aa.*,
                a.asset_code,
                a.asset_tag,
                a.asset_name,
                c.name AS category_name
             FROM asset_assignments aa
             INNER JOIN assets a
                ON a.id = aa.asset_id
             INNER JOIN asset_categories c
                ON c.id = a.category_id
             WHERE aa.assigned_to_user_id = :user_id
               AND aa.assignment_status = :status
             ORDER BY aa.assigned_at DESC'
        );

        $stmt->execute([
            'user_id' => $userId,
            'status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHistoryByAssetId(int $assetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                aa.*,
                assigned_user.first_name AS assigned_first_name,
                assigned_user.last_name AS assigned_last_name,
                assigned_user.username AS assigned_username,
                assigned_by_user.first_name AS assigned_by_first_name,
                assigned_by_user.last_name AS assigned_by_last_name,
                returned_by_user.first_name AS returned_by_first_name,
                returned_by_user.last_name AS returned_by_last_name
             FROM asset_assignments aa
             INNER JOIN users assigned_user
                ON assigned_user.id = aa.assigned_to_user_id
             INNER JOIN users assigned_by_user
                ON assigned_by_user.id = aa.assigned_by
             LEFT JOIN users returned_by_user
                ON returned_by_user.id = aa.returned_to
             WHERE aa.asset_id = :asset_id
             ORDER BY aa.assigned_at DESC, aa.id DESC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        int $assetId,
        int $assignedToUserId,
        int $assignedBy,
        string $assignedAt,
        ?string $acknowledgementReference,
        ?string $remarks
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO asset_assignments (
                asset_id,
                assigned_to_user_id,
                assigned_by,
                assigned_at,
                acknowledgement_reference,
                remarks,
                assignment_status
             ) VALUES (
                :asset_id,
                :assigned_to_user_id,
                :assigned_by,
                :assigned_at,
                :acknowledgement_reference,
                :remarks,
                :assignment_status
             )'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'assigned_to_user_id' => $assignedToUserId,
            'assigned_by' => $assignedBy,
            'assigned_at' => $assignedAt,
            'acknowledgement_reference' => $acknowledgementReference,
            'remarks' => $remarks,
            'assignment_status' => 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function returnAssignment(
        int $assignmentId,
        int $returnedTo,
        string $returnedAt,
        ?string $remarks
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE asset_assignments
         SET returned_at = :returned_at,
             returned_to = :returned_to,
             assignment_status = :assignment_status,
             remarks = :remarks
         WHERE id = :id
           AND assignment_status = :current_status
         LIMIT 1'
        );

        $stmt->execute([
            'returned_at' => $returnedAt,
            'returned_to' => $returnedTo,
            'assignment_status' => 'returned',
            'remarks' => $remarks,
            'id' => $assignmentId,
            'current_status' => 'active',
        ]);

        return $stmt->rowCount() === 1;
    }
}