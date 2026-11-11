<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use App\Models\AssetTransfer;
use PDO;
use RuntimeException;
use App\Core\Auth;
use App\Services\AuthorizationService;
use App\Exceptions\AssetTransferInternalException;

class AssetTransferService
{
    private PDO $db;

    private Asset $assetModel;

    private AssetTransfer $transferModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->assetModel = new Asset();

        $this->transferModel =
            new AssetTransfer();

        $this->authorization = new AuthorizationService();
    }


    /**
     * Ensure only authorized users can transfer an asset.
     */
    private function authorizeTransfer(
        array $asset,
        int $transferredBy
    ): void {
        $authorization = new AuthorizationService();
        $user = $authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || $transferredBy !== (int) $authenticatedUserId
        ) {
            throw new RuntimeException(
                'Invalid transferring user.'
            );
        }

        /*
         * Only the specifically authorized, active Asset Manager
         * account may transfer assets.
         *
         * Do not restrict access to the manager's own department.
         * Destination-user validation is handled separately.
         */
        if (!$authorization->canAccessAssetMaterialAreas()) {
            throw new RuntimeException(
                'You do not have permission to transfer assets.'
            );
        }

        /*
         * Ensure the asset has a valid department.
         * This method does not change the asset's department.
         */
        $assetDepartmentId = isset($asset['department_id'])
            ? (int) $asset['department_id']
            : 0;

        if ($assetDepartmentId <= 0) {
            throw new RuntimeException(
                'The asset must belong to a valid department before it can be transferred.'
            );
        }
    }
    public function transfer(
        int $assetId,
        string $destinationLocationName,
        ?string $destinationDepartmentName,
        ?string $assignedToName,
        int $transferredBy,
        ?string $transferReason = null,
        ?string $transferReference = null,
        ?string $remarks = null
    ): int {
        if ($assetId <= 0 || $transferredBy <= 0) {
            throw new RuntimeException('Invalid asset or transferring user.');
        }

        if ($transferredBy !== (int) Auth::id()) {
            throw new RuntimeException('Invalid transferring user.');
        }

        if (!$this->authorization->canAccessAssetMaterialAreas()) {
            throw new RuntimeException('You do not have permission to transfer assets.');
        }

        $destinationLocationName = trim($destinationLocationName);
        $destinationDepartmentName = $this->normalizeNullableString(
            $destinationDepartmentName
        );
        $assignedToName = $this->normalizeNullableString($assignedToName);
        $transferReason = $this->normalizeNullableString($transferReason);
        $transferReference = $this->normalizeNullableString($transferReference);
        $remarks = $this->normalizeNullableString($remarks);

        if ($destinationLocationName === '') {
            throw new RuntimeException('Destination location is required.');
        }

        if (mb_strlen($destinationLocationName) > 150) {
            throw new RuntimeException(
                'Destination location must not exceed 150 characters.'
            );
        }

        if (
            $destinationDepartmentName !== null
            && mb_strlen($destinationDepartmentName) > 150
        ) {
            throw new RuntimeException(
                'Destination department must not exceed 150 characters.'
            );
        }

        if ($assignedToName !== null && mb_strlen($assignedToName) > 150) {
            throw new RuntimeException(
                'Employee name must not exceed 150 characters.'
            );
        }

        if ($transferReason === null) {
            throw new RuntimeException('Transfer reason is required.');
        }

        if (
            $transferReference !== null
            && mb_strlen($transferReference) > 100
        ) {
            throw new RuntimeException(
                'Transfer reference must not exceed 100 characters.'
            );
        }

        $this->db->beginTransaction();

        try {
            $asset = $this->assetModel->findByIdForUpdate($assetId);

            if (!$asset) {
                throw new RuntimeException('Asset not found.');
            }

            $this->authorizeTransfer($asset, $transferredBy);

            $statusName = strtolower(trim((string) (
                $asset['status_name'] ?? $asset['status'] ?? ''
            )));

            $blockedStatuses = [
                'lost',
                'retired',
                'disposed',
                'for disposal',
                'pending disposal',
            ];

            if (in_array($statusName, $blockedStatuses, true)) {
                throw new RuntimeException(
                    'This asset cannot be transferred because of its current status.'
                );
            }

            /*
             * Assigned assets must be returned before transfer.
             * Keep this check and preserve assignment history.
             */
            $activeAssignment = $this->getActiveAssignment($assetId, true);

            if (
                !empty($asset['assigned_to_user_id'])
                || !empty($asset['assigned_to_name'])
                || $activeAssignment
            ) {
                throw new RuntimeException(
                    'Return the assigned asset before transferring it.'
                );
            }

            $fromDepartmentId = (int) $asset['department_id'];

            $fromLocationId = !empty($asset['location_id'])
                ? (int) $asset['location_id']
                : null;

            $fromUserId = !empty($asset['assigned_to_user_id'])
                ? (int) $asset['assigned_to_user_id']
                : null;

            $fromAssignedToName = $this->normalizeNullableString(
                $asset['assigned_to_name'] ?? null
            );

            /*
             * Resolve or create the destination department.
             * If the department field is blank, retain the current department.
             */
            $toDepartmentId = $fromDepartmentId;

            if ($destinationDepartmentName !== null) {
                $stmt = $this->db->prepare(
                    'SELECT id, is_active
                     FROM departments
                     WHERE name = :name
                     LIMIT 1
                     FOR UPDATE'
                );

                $stmt->execute([
                    'name' => $destinationDepartmentName,
                ]);

                $department = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($department) {
                    if (!(int) $department['is_active']) {
                        throw new RuntimeException(
                            'The destination department is inactive.'
                        );
                    }

                    $toDepartmentId = (int) $department['id'];
                } else {
                    $stmt = $this->db->prepare(
                        'INSERT INTO departments (name, is_active)
                         VALUES (:name, 1)'
                    );

                    $stmt->execute([
                        'name' => $destinationDepartmentName,
                    ]);

                    $toDepartmentId = (int) $this->db->lastInsertId();
                }
            }

            /*
             * Resolve or create the destination location by name.
             */
            $stmt = $this->db->prepare(
                'SELECT id, is_active
                 FROM locations
                 WHERE name = :name
                 LIMIT 1
                 FOR UPDATE'
            );

            $stmt->execute([
                'name' => $destinationLocationName,
            ]);

            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($location) {
                if (!(int) $location['is_active']) {
                    throw new RuntimeException(
                        'The destination location is inactive.'
                    );
                }

                $toLocationId = (int) $location['id'];
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO locations (name, is_active)
                     VALUES (:name, 1)'
                );

                $stmt->execute([
                    'name' => $destinationLocationName,
                ]);

                $toLocationId = (int) $this->db->lastInsertId();
            }

            if ($toLocationId <= 0) {
                throw new RuntimeException(
                    'Unable to resolve the destination location.'
                );
            }

            if (
                $toDepartmentId === $fromDepartmentId
                && $toLocationId === $fromLocationId
            ) {
                throw new RuntimeException(
                    'Choose a different department or location for this transfer.'
                );
            }

            /*
             * An optional new assignee can only receive an Available asset.
             */
            $assignedStatusId = null;

            if ($assignedToName !== null) {
                if ($statusName !== 'available') {
                    throw new RuntimeException(
                        'Only assets with Available status can be assigned after transfer.'
                    );
                }

                $assignedStatusId = $this->getStatusId('Assigned');

                if (!$assignedStatusId) {
                    throw new RuntimeException(
                        'The Assigned asset status is not configured.'
                    );
                }
            }

            /*
             * Record the destination assignee in transfer history.
             * Manual-name assignments do not use a users-table ID.
             */
            $toUserId = null;
            $toAssignedToName = $assignedToName;

            $transferId = $this->createTransferRecord(
                $assetId,
                $fromLocationId,
                $toLocationId,
                $fromUserId,
                $toUserId,
                $transferredBy,
                $transferReason,
                $transferReference,
                $remarks,
                $fromDepartmentId,
                $toDepartmentId,
                $fromAssignedToName,
                $toAssignedToName
            );

            if ($transferId <= 0) {
                throw new RuntimeException(
                    'Unable to create the transfer history record.'
                );
            }

            /*
             * Update the asset. Preserve its existing status when no
             * new assignee is supplied.
             */
            if ($assignedToName !== null) {
                $stmt = $this->db->prepare(
                    'UPDATE assets
                     SET department_id = :department_id,
                         location_id = :location_id,
                         assigned_to_user_id = NULL,
                         assigned_to_name = :assigned_to_name,
                         status_id = :status_id,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     LIMIT 1'
                );

                $stmt->execute([
                    'department_id' => $toDepartmentId,
                    'location_id' => $toLocationId,
                    'assigned_to_name' => $assignedToName,
                    'status_id' => $assignedStatusId,
                    'id' => $assetId,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE assets
                     SET department_id = :department_id,
                         location_id = :location_id,
                         assigned_to_user_id = NULL,
                         assigned_to_name = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     LIMIT 1'
                );

                $stmt->execute([
                    'department_id' => $toDepartmentId,
                    'location_id' => $toLocationId,
                    'id' => $assetId,
                ]);
            }

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Unable to update the asset.');
            }

            /*
             * Create assignment history in the same transaction.
             * assigned_to_user_id remains NULL for a manually entered name.
             */
            if ($assignedToName !== null) {
                $stmt = $this->db->prepare(
                    "INSERT INTO asset_assignments (
                        asset_id,
                        assigned_to_user_id,
                        assigned_to_name,
                        assigned_by,
                        assigned_at,
                        assignment_status,
                        acknowledgement_reference,
                        remarks
                    ) VALUES (
                        :asset_id,
                        NULL,
                        :assigned_to_name,
                        :assigned_by,
                        :assigned_at,
                        'active',
                        :acknowledgement_reference,
                        :remarks
                    )"
                );

                $stmt->execute([
                    'asset_id' => $assetId,
                    'assigned_to_name' => $assignedToName,
                    'assigned_by' => $transferredBy,
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'acknowledgement_reference' => $transferReference,
                    'remarks' => $remarks,
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to create the assignment history record.'
                    );
                }
            }

            // Retrieve the asset's resulting status after the transfer.
            $statusStmt = $this->db->prepare(
                'SELECT
                    a.status_id,
                    s.name AS status_name
                FROM assets a
                INNER JOIN asset_statuses s
                    ON s.id = a.status_id
                WHERE a.id = :asset_id
                LIMIT 1'
            );

            $statusStmt->execute([
                'asset_id' => $assetId,
            ]);

            $resultingAsset = $statusStmt->fetch(PDO::FETCH_ASSOC);

            if (!$resultingAsset) {
                throw new RuntimeException(
                    'Unable to retrieve the transferred asset status.'
                );
            }

            // Record the successful asset transfer.
            (new AuditLogService())->log(
                action: 'transfer_asset',
                module: 'assets',
                entityType: 'asset_transfer',
                entityId: $transferId,
                description: 'Asset transferred successfully.',
                oldValues: [
                    'asset_id' => $assetId,
                    'department_id' => $fromDepartmentId,
                    'location_id' => $fromLocationId,
                    'assigned_to_user_id' => $fromUserId,
                    'assigned_to_name' => $fromAssignedToName,
                    'asset_status' => $statusName,
                ],
                newValues: [
                    'asset_id' => $assetId,
                    'department_id' => $toDepartmentId,
                    'location_id' => $toLocationId,
                    'assigned_to_user_id' => $toUserId,
                    'assigned_to_name' => $toAssignedToName,
                    'asset_status_id' => (int) $resultingAsset['status_id'],
                    'asset_status' => $resultingAsset['status_name'],
                    'transfer_reason' => $transferReason,
                    'transfer_reference' => $transferReference,
                    'transferred_by' => $transferredBy,
                    'transfer_id' => $transferId,
                ]
            );

            $this->db->commit();

            return $transferId;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new AssetTransferInternalException(
                'Unable to transfer the asset.',
                0,
                $e
            );
        }
    }

    public function getHistoryByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            return [];
        }

        return $this->transferModel->getHistoryByAssetId(
            $assetId
        );
    }

    private function getActiveAssignment(
        int $assetId,
        bool $forUpdate = false
    ): array|false {
        $sql = 'SELECT
                id,
                asset_id,
                assigned_to_user_id
            FROM asset_assignments
            WHERE asset_id = :asset_id
              AND assignment_status = :status
            ORDER BY id DESC
            LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'asset_id' => $assetId,
            'status' => 'active',
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    private function createTransferRecord(
        int $assetId,
        ?int $fromLocationId,
        int $toLocationId,
        ?int $fromUserId,
        ?int $toUserId,
        int $transferredBy,
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
            'transfer_date' => date('Y-m-d H:i:s'),
            'transfer_reason' => $transferReason,
            'transfer_reference' => $transferReference,
            'remarks' => $remarks,
        ]);

        return (int) $this->db->lastInsertId();
    }


    private function getStatusId(
        string $statusName
    ): ?int {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM asset_statuses
             WHERE name = :name
             LIMIT 1'
        );

        $stmt->execute([
            'name' =>
                $statusName,
        ]);

        $id = $stmt->fetchColumn();

        return $id !== false
            ? (int) $id
            : null;
    }

    private function validateLocation(
        int $locationId
    ): void {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM locations
             WHERE id = :id
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'id' =>
                $locationId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'Selected destination location is invalid or inactive.'
            );
        }
    }

    private function validateUser(
        int $userId,
        int $expectedDepartmentId
    ): void {
        if ($userId <= 0 || $expectedDepartmentId <= 0) {
            throw new RuntimeException(
                'Invalid destination user or asset department.'
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id
         FROM users
         WHERE id = :id
           AND is_active = 1
           AND account_status = :account_status
           AND department_id = :department_id
         LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
            'account_status' => 'active',
            'department_id' => $expectedDepartmentId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'The destination user must be active and belong to the asset\'s current department. Use the Department Transfer workflow to change the asset\'s department first.'
            );
        }
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