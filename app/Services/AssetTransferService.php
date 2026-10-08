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

class AssetTransferService
{
    private PDO $db;

    private Asset $assetModel;

    private AssetTransfer $transferModel;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->assetModel = new Asset();

        $this->transferModel =
            new AssetTransfer();
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
            || $transferredBy !== $authenticatedUserId
        ) {
            throw new RuntimeException(
                'Invalid transferring user.'
            );
        }

        if (
            !$authorization->isAssetManager()
            && !$authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to transfer assets.'
            );
        }

        $userDepartmentId = $authorization->departmentId();

        $assetDepartmentId = $asset['department_id'] === null
            ? null
            : (int) $asset['department_id'];

        if (
            $userDepartmentId === null
            || $assetDepartmentId === null
            || $userDepartmentId !== $assetDepartmentId
        ) {
            throw new RuntimeException(
                'You may only transfer assets belonging to your department.'
            );
        }
    }

    public function transfer(
        int $assetId,
        int $toLocationId,
        ?int $toUserId,
        int $transferredBy,
        ?string $transferReason = null,
        ?string $transferReference = null,
        ?string $remarks = null
    ): int {
        if ($assetId <= 0) {
            throw new RuntimeException(
                'Invalid asset.'
            );
        }

        if ($toLocationId <= 0) {
            throw new RuntimeException(
                'Please select a destination location.'
            );
        }

        if ($transferredBy <= 0) {
            throw new RuntimeException(
                'Invalid transferring user.'
            );
        }

        if ($toUserId !== null && $toUserId <= 0) {
            throw new RuntimeException(
                'Invalid destination user.'
            );
        }

        $transferReason =
            $this->normalizeNullableString(
                $transferReason
            );

        $transferReference =
            $this->normalizeNullableString(
                $transferReference
            );

        $remarks =
            $this->normalizeNullableString(
                $remarks
            );

        /*
         * Start the transaction before reading the asset.
         *
         * This allows findByIdForUpdate() to lock the
         * asset row until the transfer is completed.
         */
        $this->db->beginTransaction();

        try {

            /*
             * Lock the asset row before reading its current
             * department, location, assignment, and status.
             */
            $asset = $this->assetModel->findByIdForUpdate(
                $assetId
            );

            if (!$asset) {
                throw new RuntimeException(
                    'Asset not found.'
                );
            }

            /*
             * Authorization is performed against the
             * authenticated user and the asset's actual
             * department.
             */
            $this->authorizeTransfer(
                $asset,
                $transferredBy
            );

            /*
             * Validate the destination location while the
             * transaction is active.
             */
            $this->validateLocation(
                $toLocationId
            );

            /*
             * If a destination user was selected, validate
             * that the account is active.
             */
            if ($toUserId !== null) {
                $this->validateUser(
                    $toUserId
                );
            }

            /*
             * Read the asset's current state only after the
             * asset row has been locked.
             */
            $fromLocationId =
                !empty($asset['location_id'])
                ? (int) $asset['location_id']
                : null;

            $fromUserId =
                !empty($asset['assigned_to_user_id'])
                ? (int) $asset['assigned_to_user_id']
                : null;

            /*
             * Prevent a transfer that changes nothing.
             */
            if (
                $fromLocationId === $toLocationId
                && $fromUserId === $toUserId
            ) {
                throw new RuntimeException(
                    'The destination location and assigned user are the same as the current asset assignment.'
                );
            }

            /*
             * Lock the current active assignment as well.
             *
             * This prevents concurrent assignment changes
             * from interfering with this transfer.
             */
            $activeAssignment =
                $this->getActiveAssignment(
                    $assetId,
                    true
                );

            /*
             * If the current assigned user is changing,
             * close the existing assignment.
             */
            if (
                $activeAssignment
                && $fromUserId !== $toUserId
            ) {
                $returnedAt =
                    date('Y-m-d H:i:s');

                $assignmentRemarks =
                    $remarks
                    ?? 'Assignment changed through asset transfer.';

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
                    'returned_at' =>
                        $returnedAt,

                    'returned_to' =>
                        $transferredBy,

                    'assignment_status' =>
                        'returned',

                    'remarks' =>
                        $assignmentRemarks,

                    'id' =>
                        (int) $activeAssignment['id'],

                    'current_status' =>
                        'active',
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to close the existing asset assignment.'
                    );
                }
            }

            /*
             * If a destination user was selected and it
             * differs from the current user, create a
             * new active assignment.
             */
            if (
                $toUserId !== null
                && $fromUserId !== $toUserId
            ) {
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
                    'asset_id' =>
                        $assetId,

                    'assigned_to_user_id' =>
                        $toUserId,

                    'assigned_by' =>
                        $transferredBy,

                    'assigned_at' =>
                        date('Y-m-d H:i:s'),

                    'acknowledgement_reference' =>
                        $transferReference,

                    'remarks' =>
                        $remarks,

                    'assignment_status' =>
                        'active',
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to create the new asset assignment.'
                    );
                }
            }

            /*
             * Record the transfer using the same database
             * transaction.
             */
            $transferId =
                $this->createTransferRecord(
                    $assetId,
                    $fromLocationId,
                    $toLocationId,
                    $fromUserId,
                    $toUserId,
                    $transferredBy,
                    $transferReason,
                    $transferReference,
                    $remarks
                );

            if ($transferId <= 0) {
                throw new RuntimeException(
                    'Unable to create the asset transfer record.'
                );
            }

            /*
             * Keep the asset's current location, assignment,
             * and status synchronized.
             */
            $statusName =
                $toUserId !== null
                ? 'Assigned'
                : 'Available';

            $statusId =
                $this->getStatusId(
                    $statusName
                );

            if (!$statusId) {
                throw new RuntimeException(
                    $statusName
                    . ' asset status is not configured.'
                );
            }

            $stmt = $this->db->prepare(
                'UPDATE assets
             SET location_id = :location_id,
                 assigned_to_user_id = :assigned_to_user_id,
                 status_id = :status_id
             WHERE id = :id
             LIMIT 1'
            );

            $stmt->execute([
                'location_id' =>
                    $toLocationId,

                'assigned_to_user_id' =>
                    $toUserId,

                'status_id' =>
                    $statusId,

                'id' =>
                    $assetId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update the asset after transfer.'
                );
            }

            /*
             * Commit the asset assignment change,
             * transfer history, and asset update together.
             */
            $this->db->commit();

            return $transferId;

        } catch (\Throwable $e) {

            /*
             * Any failure rolls back the complete transfer.
             */
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                'Unable to transfer the asset.'
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
        ?string $remarks
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO asset_transfers (
                asset_id,
                from_location_id,
                to_location_id,
                from_user_id,
                to_user_id,
                transferred_by,
                transfer_date,
                transfer_reason,
                transfer_reference,
                remarks
             ) VALUES (
                :asset_id,
                :from_location_id,
                :to_location_id,
                :from_user_id,
                :to_user_id,
                :transferred_by,
                :transfer_date,
                :transfer_reason,
                :transfer_reference,
                :remarks
             )'
        );

        $stmt->execute([
            'asset_id' =>
                $assetId,

            'from_location_id' =>
                $fromLocationId,

            'to_location_id' =>
                $toLocationId,

            'from_user_id' =>
                $fromUserId,

            'to_user_id' =>
                $toUserId,

            'transferred_by' =>
                $transferredBy,

            'transfer_date' =>
                date('Y-m-d H:i:s'),

            'transfer_reason' =>
                $transferReason,

            'transfer_reference' =>
                $transferReference,

            'remarks' =>
                $remarks,
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
        int $userId
    ): void {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM users
             WHERE id = :id
               AND is_active = 1
               AND account_status = :account_status
             LIMIT 1'
        );

        $stmt->execute([
            'id' =>
                $userId,

            'account_status' =>
                'active',
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'Selected destination user is invalid or inactive.'
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