<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetDepartmentTransfer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }


    /**
     * Record a completed department transfer.
     *
     * The calling service must update the asset and insert this
     * history record within the same database transaction.
     */
    public function createCompletedTransfer(
        int $assetId,
        int $sourceDepartmentId,
        int $destinationDepartmentId,
        string $destinationDepartmentName,
        int $transferredBy,
        string $transferReason
    ): int {
        if (
            $assetId <= 0
            || $sourceDepartmentId <= 0
            || $destinationDepartmentId <= 0
            || $sourceDepartmentId === $destinationDepartmentId
            || $transferredBy <= 0
        ) {
            throw new \InvalidArgumentException(
                'Invalid completed department transfer details.'
            );
        }

        $stmt = $this->db->prepare(
            "INSERT INTO asset_department_transfers (
                asset_id,
                source_department_id,
                destination_department_id,
                destination_department_name,
                requested_by,
                request_reason,
                request_status,
                reviewed_by,
                reviewed_at,
                review_notes
            ) VALUES (
                :asset_id,
                :source_department_id,
                :destination_department_id,
                :destination_department_name,
                :transferred_by,
                :transfer_reason,
                'completed',
                :reviewed_by,
                CURRENT_TIMESTAMP,
                NULL
            )"
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'source_department_id' => $sourceDepartmentId,
            'destination_department_id' => $destinationDepartmentId,
            'destination_department_name' => $destinationDepartmentName,
            'transferred_by' => $transferredBy,
            'transfer_reason' => $transferReason,
            'reviewed_by' => $transferredBy,
        ]);

        return (int) $this->db->lastInsertId();
    }


    /**
     * Find a request for transactional updates.
     *
     * Call inside a transaction when locking is required.
     */
    public function findByIdForUpdate(
        int $id
    ): array|false {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM asset_department_transfers
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find a request with asset, department, requester,
     * and reviewer details.
     */
    public function findById(
        int $id
    ): ?array {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' WHERE t.id = :id
                LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        return $request !== false
            ? $request
            : null;
    }

    /**
     * Retrieve all department-transfer requests for the Finance asset register.
     */
    public function getAllRequests(): array
    {
        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' ORDER BY t.requested_at DESC, t.id DESC'
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve the department-transfer history for an asset.
     *
     * The calling service must authorize access before returning it.
     */
    public function getHistoryByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' WHERE t.asset_id = :asset_id
                ORDER BY t.requested_at ASC, t.id ASC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Shared query for displaying request details.
     */
    private function detailsSelect(): string
    {
        return '
            SELECT
                t.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                source_department.name
                    AS source_department_name,

                COALESCE(
                    destination_department.name,
                    t.destination_department_name
                ) AS destination_department_name,

                requester.first_name
                    AS requester_first_name,

                requester.last_name
                    AS requester_last_name,

                requester.username
                    AS requester_username,

                reviewer.first_name
                    AS reviewer_first_name,

                reviewer.last_name
                    AS reviewer_last_name,

                reviewer.username
                    AS reviewer_username

            FROM asset_department_transfers t

            INNER JOIN assets a
                ON a.id = t.asset_id

            LEFT JOIN departments source_department
                ON source_department.id = t.source_department_id

            LEFT JOIN departments destination_department
                ON destination_department.id = t.destination_department_id

            LEFT JOIN users requester
                ON requester.id = t.requested_by

            LEFT JOIN users reviewer
                ON reviewer.id = t.reviewed_by
        ';
    }
}