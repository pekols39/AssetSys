<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetTransfer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }


    public function create(
        int $assetId,
        ?int $fromLocationId,
        int $toLocationId,
        ?int $fromUserId,
        ?int $toUserId,
        int $transferredBy,
        string $transferDate,
        ?string $transferReason,
        ?string $transferReference,
        ?string $remarks,
        ?int $fromDepartmentId = null,
        ?int $toDepartmentId = null,
        ?string $fromAssignedToName = null,
        ?string $toAssignedToName = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO asset_transfers (
            asset_id,
            from_department_id,
            to_department_id,
            from_location_id,
            to_location_id,
            from_user_id,
            to_user_id,
            from_assigned_to_name,
            to_assigned_to_name,
            transferred_by,
            transfer_date,
            transfer_reason,
            transfer_reference,
            remarks
        ) VALUES (
            :asset_id,
            :from_department_id,
            :to_department_id,
            :from_location_id,
            :to_location_id,
            :from_user_id,
            :to_user_id,
            :from_assigned_to_name,
            :to_assigned_to_name,
            :transferred_by,
            :transfer_date,
            :transfer_reason,
            :transfer_reference,
            :remarks
        )'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'from_department_id' => $fromDepartmentId,
            'to_department_id' => $toDepartmentId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'from_assigned_to_name' => $fromAssignedToName,
            'to_assigned_to_name' => $toAssignedToName,
            'transferred_by' => $transferredBy,
            'transfer_date' => $transferDate,
            'transfer_reason' => $transferReason,
            'transfer_reference' => $transferReference,
            'remarks' => $remarks,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getHistoryByAssetId(int $assetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                at.*,

                from_department.name AS from_department_name,
                to_department.name AS to_department_name,

                from_location.name AS from_location_name,
                to_location.name AS to_location_name,

                from_user.first_name AS from_user_first_name,
                from_user.last_name AS from_user_last_name,
                from_user.username AS from_user_username,

                to_user.first_name AS to_user_first_name,
                to_user.last_name AS to_user_last_name,
                to_user.username AS to_user_username,

                transferred_by_user.first_name AS transferred_by_first_name,
                transferred_by_user.last_name AS transferred_by_last_name,
                transferred_by_user.username AS transferred_by_username

             FROM asset_transfers at

             LEFT JOIN locations from_location
                ON from_location.id = at.from_location_id

             INNER JOIN locations to_location
                ON to_location.id = at.to_location_id

            LEFT JOIN departments from_department
                ON from_department.id = at.from_department_id

            LEFT JOIN departments to_department
                ON to_department.id = at.to_department_id

             LEFT JOIN users from_user
                ON from_user.id = at.from_user_id

             LEFT JOIN users to_user
                ON to_user.id = at.to_user_id

             INNER JOIN users transferred_by_user
                ON transferred_by_user.id = at.transferred_by

             WHERE at.asset_id = :asset_id

             ORDER BY
                at.transfer_date DESC,
                at.id DESC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}