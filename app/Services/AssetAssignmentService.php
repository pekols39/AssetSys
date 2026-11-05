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
        string $assignedToName,
        int $assignedBy,
        ?string $acknowledgementReference = null,
        ?string $remarks = null
    ): int {
        if ($assetId <= 0) {
            throw new RuntimeException('Invalid asset.');
        }

        $assignedToName = trim($assignedToName);

        if ($assignedToName === '') {
            throw new RuntimeException('Please enter the employee name.');
        }

        if (mb_strlen($assignedToName) > 150) {
            throw new RuntimeException(
                'Employee name must not exceed 150 characters.'
            );
        }

        if ($assignedBy <= 0) {
            throw new RuntimeException('Invalid assigning user.');
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to assign assets.'
            );
        }

        $acknowledgementReference =
            $this->normalizeNullableString($acknowledgementReference);

        $remarks = $this->normalizeNullableString($remarks);

        $this->db->beginTransaction();

        try {
            $asset = $this->getAssetForAssignment($assetId, true);

            if (!$asset) {
                throw new RuntimeException('Asset not found.');
            }

            if (
                !empty($asset['assigned_to_user_id'])
                || trim((string) ($asset['assigned_to_name'] ?? '')) !== ''
            ) {
                throw new RuntimeException(
                    'This asset is already assigned. Return it before assigning it again.'
                );
            }

            if (
                strtolower(trim((string) ($asset['status_name'] ?? '')))
                !== 'available'
            ) {
                throw new RuntimeException(
                    'Only assets with Available status can be assigned.'
                );
            }

            $assignmentId = $this->assignmentModel->create(
                $assetId,
                null,
                $assignedBy,
                date('Y-m-d H:i:s'),
                $acknowledgementReference,
                $remarks,
                $assignedToName
            );

            $assignedStatusId = $this->getStatusId('Assigned');

            if (!$assignedStatusId) {
                throw new RuntimeException(
                    'Assigned asset status is not configured.'
                );
            }

            $stmt = $this->db->prepare(
                'UPDATE assets
             SET assigned_to_user_id = NULL,
                 assigned_to_name = :assigned_to_name,
                 status_id = :status_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND assigned_to_user_id IS NULL
             LIMIT 1'
            );

            $stmt->execute([
                'assigned_to_name' => $assignedToName,
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
                'Unable to assign the asset.',
                0,
                $e
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
            throw new RuntimeException('Invalid assignment.');
        }

        if ($returnedTo <= 0) {
            throw new RuntimeException('Invalid returning user.');
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to return assets.'
            );
        }

        $remarks = $this->normalizeNullableString($remarks);

        $this->db->beginTransaction();

        try {
            $assignment = $this->getActiveAssignment(
                $assignmentId,
                true
            );

            if (!$assignment) {
                throw new RuntimeException(
                    'Active assignment not found.'
                );
            }

            $assetId = (int) ($assignment['asset_id'] ?? 0);

            if ($assetId <= 0) {
                throw new RuntimeException(
                    'The assignment record is invalid.'
                );
            }

            $availableStatusId = $this->getStatusId('Available');

            if (!$availableStatusId) {
                throw new RuntimeException(
                    'Available asset status is not configured.'
                );
            }

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

            $stmt = $this->db->prepare(
                'UPDATE assets
             SET assigned_to_user_id = NULL,
                 assigned_to_name = NULL,
                 status_id = :status_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
             LIMIT 1'
            );

            $stmt->execute([
                'status_id' => $availableStatusId,
                'id' => $assetId,
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
                'Unable to return the asset.',
                0,
                $e
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