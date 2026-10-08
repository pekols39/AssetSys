<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AssetAssignment;
use App\Services\AuthorizationService;
use PDO;
use RuntimeException;

class AssetAssignmentService
{
    private PDO $db;
    private AssetAssignment $assignmentModel;
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assignmentModel = new AssetAssignment();
        $this->authorization = new AuthorizationService();
    }

    public function assign(
        int $assetId,
        int $assignedToUserId,
        int $assignedBy,
        ?string $acknowledgementReference = null,
        ?string $remarks = null
    ): int {
        if ($assetId <= 0) {
            throw new RuntimeException(
                'Invalid asset.'
            );
        }

        if ($assignedToUserId <= 0) {
            throw new RuntimeException(
                'Please select a user.'
            );
        }

        if ($assignedBy <= 0) {
            throw new RuntimeException(
                'Invalid assigning user.'
            );
        }

        $asset = $this->getAssetForAssignment(
            $assetId
        );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        if (
            !$this->authorization->isAssetManager()
            && !$this->authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to assign assets.'
            );
        }

        $userDepartmentId = $this->authorization->departmentId();

        $assetDepartmentId = $asset['department_id'] === null
            ? null
            : (int) $asset['department_id'];

        if (
            $userDepartmentId === null
            || $assetDepartmentId === null
            || $userDepartmentId !== $assetDepartmentId
        ) {
            throw new RuntimeException(
                'You may only assign assets belonging to your department.'
            );
        }

        $user = $this->getActiveUser(
            $assignedToUserId
        );

        if (!$user) {
            throw new RuntimeException(
                'Selected user is not active.'
            );
        }

        $userDepartmentId = $this->authorization->departmentId();

        $recipientDepartmentId = $user['department_id'] === null
            ? null
            : (int) $user['department_id'];

        if (
            $userDepartmentId === null
            || $recipientDepartmentId === null
            || $userDepartmentId !== $recipientDepartmentId
        ) {
            throw new RuntimeException(
                'You may only assign an asset to a user in your department.'
            );
        }

        if (
            !empty($asset['assigned_to_user_id'])
        ) {
            throw new RuntimeException(
                'This asset is already assigned to a user.'
            );
        }

        if (
            strtolower(
                (string) ($asset['status_name'] ?? '')
            ) !== 'available'
        ) {
            throw new RuntimeException(
                'Only assets with Available status can be assigned.'
            );
        }

        $acknowledgementReference =
            $this->normalizeNullableString(
                $acknowledgementReference
            );

        $remarks = $this->normalizeNullableString(
            $remarks
        );

        $this->db->beginTransaction();

        try {
            // Re-read and lock the asset inside the transaction.
            $lockedAsset = $this->getAssetForAssignment(
                $assetId,
                true
            );

            if (!$lockedAsset) {
                throw new RuntimeException(
                    'Asset not found.'
                );
            }

            if (
                !empty($lockedAsset['assigned_to_user_id'])
            ) {
                throw new RuntimeException(
                    'This asset is already assigned to a user.'
                );
            }

            if (
                strtolower(
                    (string) ($lockedAsset['status_name'] ?? '')
                ) !== 'available'
            ) {
                throw new RuntimeException(
                    'Only assets with Available status can be assigned.'
                );
            }

            $assignmentId = $this->assignmentModel->create(
                $assetId,
                $assignedToUserId,
                $assignedBy,
                date('Y-m-d H:i:s'),
                $acknowledgementReference,
                $remarks
            );

            $assignedStatusId = $this->getStatusId(
                'Assigned'
            );

            if (!$assignedStatusId) {
                throw new RuntimeException(
                    'Assigned asset status is not configured.'
                );
            }

            $stmt = $this->db->prepare(
                'UPDATE assets
                 SET assigned_to_user_id = :assigned_to_user_id,
                     status_id = :status_id
                 WHERE id = :id
                 LIMIT 1'
            );

            $stmt->execute([
                'assigned_to_user_id' => $assignedToUserId,
                'status_id' => $assignedStatusId,
                'id' => $assetId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update asset assignment status.'
                );
            }

            $this->db->commit();

            return $assignmentId;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                'Unable to assign the asset.'
            );
        }
    }

    public function findActiveByAssetId(
        int $assetId
    ): array|false {
        if ($assetId <= 0) {
            return false;
        }

        return $this->assignmentModel
            ->findActiveByAssetId($assetId);
    }

    public function getHistoryByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            return [];
        }

        return $this->assignmentModel
            ->getHistoryByAssetId($assetId);
    }

    public function returnAssignment(
        int $assignmentId,
        int $returnedTo,
        ?string $remarks = null
    ): bool {
        if ($assignmentId <= 0) {
            throw new RuntimeException(
                'Invalid assignment.'
            );
        }

        if ($returnedTo <= 0) {
            throw new RuntimeException(
                'Invalid returning user.'
            );
        }

        $remarks = $this->normalizeNullableString(
            $remarks
        );

        $this->db->beginTransaction();

        try {
            /*
             * Lock the active assignment row before making
             * any authorization or state changes.
             */
            $assignment = $this->getActiveAssignment(
                $assignmentId,
                true
            );

            if (!$assignment) {
                throw new RuntimeException(
                    'Active assignment not found.'
                );
            }

            /*
             * Only Asset Managers and Asset Custodians
             * may return assets.
             */
            if (
                !$this->authorization->isAssetManager()
                && !$this->authorization->isAssetCustodian()
            ) {
                throw new RuntimeException(
                    'You do not have permission to return assets.'
                );
            }

            /*
             * The asset must belong to the current user's
             * department.
             */
            $userDepartmentId = $this->authorization->departmentId();

            $assetDepartmentId = $assignment['department_id'] === null
                ? null
                : (int) $assignment['department_id'];

            if (
                $userDepartmentId === null
                || $assetDepartmentId === null
                || $userDepartmentId !== $assetDepartmentId
            ) {
                throw new RuntimeException(
                    'You may only return assets belonging to your department.'
                );
            }

            /*
             * Mark the assignment as returned.
             */
            $updated = $this->assignmentModel->returnAssignment(
                $assignmentId,
                $returnedTo,
                date('Y-m-d H:i:s'),
                $remarks
            );

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to return the asset assignment.'
                );
            }

            /*
             * Get the Available status.
             */
            $availableStatusId = $this->getStatusId(
                'Available'
            );

            if (!$availableStatusId) {
                throw new RuntimeException(
                    'Available asset status is not configured.'
                );
            }

            /*
             * Release the asset back to available inventory.
             *
             * The assigned user condition prevents the asset
             * from being released if its assignment state has
             * unexpectedly changed.
             */
            $stmt = $this->db->prepare(
                'UPDATE assets
             SET assigned_to_user_id = NULL,
                 status_id = :status_id
             WHERE id = :id
               AND assigned_to_user_id = :assigned_to_user_id
             LIMIT 1'
            );

            $stmt->execute([
                'status_id' => $availableStatusId,
                'id' => (int) $assignment['asset_id'],
                'assigned_to_user_id' => (int) $assignment['assigned_to_user_id'],
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update the asset after return.'
                );
            }

            $this->db->commit();

            return true;

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                'Unable to return the asset.'
            );
        }
    }

    private function getActiveAssignment(
        int $assignmentId,
        bool $forUpdate = false
    ): array|false {
        $sql = 'SELECT
                aa.id,
                aa.asset_id,
                aa.assigned_to_user_id,
                a.department_id
            FROM asset_assignments aa
            INNER JOIN assets a
                ON a.id = aa.asset_id
            WHERE aa.id = :id
              AND aa.assignment_status = :status
            LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'id' => $assignmentId,
            'status' => 'active',
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getAssetForAssignment(
        int $assetId,
        bool $forUpdate = false
    ): array|false {
        $sql = 'SELECT
                a.id,
                a.department_id,
                a.assigned_to_user_id,
                s.name AS status_name
            FROM assets a
            INNER JOIN asset_statuses s
                ON s.id = a.status_id
            WHERE a.id = :id
            LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'id' => $assetId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getActiveUser(
        int $userId
    ): array|false {
        $stmt = $this->db->prepare(
            'SELECT
            id,
            first_name,
            last_name,
            username,
            department_id
         FROM users
         WHERE id = :id
           AND is_active = 1
           AND account_status = :account_status
         LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
            'account_status' => 'active',
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getStatusId(
        string $statusName
    ): ?int {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM asset_statuses
             WHERE name = :name
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'name' => $statusName,
        ]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    private function normalizeNullableString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }
}