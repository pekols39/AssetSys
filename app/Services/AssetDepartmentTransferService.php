<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\AssetDepartmentTransfer;
use PDO;
use RuntimeException;

class AssetDepartmentTransferService
{
    private PDO $db;

    private Asset $assetModel;

    private AssetDepartmentTransfer $transferModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->transferModel = new AssetDepartmentTransfer();
        $this->authorization = new AuthorizationService();
    }


    /**
     * Transfer an asset to another department immediately.
     *
     * The asset update and completed-history record are committed
     * together, or both are rolled back if an operation fails.
     */
    public function requestTransfer(
        int $assetId,
        string $destinationDepartmentName,
        int $requestedBy,
        string $requestReason
    ): int {
        $destinationDepartmentName = trim($destinationDepartmentName);
        $requestReason = trim($requestReason);

        if ($assetId <= 0) {
            throw new RuntimeException('A valid asset is required.');
        }

        if ($destinationDepartmentName === '') {
            throw new RuntimeException(
                'A destination department is required.'
            );
        }

        if (mb_strlen($destinationDepartmentName) > 150) {
            throw new RuntimeException(
                'The destination department must not exceed 150 characters.'
            );
        }

        if ($requestReason === '') {
            throw new RuntimeException(
                'A reason for the department transfer is required.'
            );
        }

        if (mb_strlen($requestReason) > 500) {
            throw new RuntimeException(
                'The transfer reason must not exceed 500 characters.'
            );
        }

        $this->requireActor($requestedBy, []);

        $this->db->beginTransaction();

        try {
            $asset = $this->assetModel->findByIdForUpdate($assetId);

            if (!$asset) {
                throw new RuntimeException('Asset not found.');
            }

            $this->assertAssetCanTransfer($asset);

            $sourceDepartmentId = (int) ($asset['department_id'] ?? 0);

            if ($sourceDepartmentId <= 0) {
                throw new RuntimeException(
                    'The asset must have a current responsible department before it can be transferred.'
                );
            }

            $sourceDepartmentStmt = $this->db->prepare(
                'SELECT name
                FROM departments
                WHERE id = :department_id
                LIMIT 1'
            );

            $sourceDepartmentStmt->execute([
                'department_id' => $sourceDepartmentId,
            ]);

            if ($sourceDepartmentStmt->fetchColumn() === false) {
                throw new RuntimeException(
                    'The asset’s current department does not exist or is inactive.'
                );
            }

            /*
             * Resolve an existing active destination department.
             * Departments are never created by a transfer.
             * This lookup occurs inside the transfer transaction.
             */
            $destinationDepartmentId =
                $this->resolveExistingDestinationDepartment(
                    $destinationDepartmentName
                );

            if ($destinationDepartmentId === $sourceDepartmentId) {
                throw new RuntimeException(
                    'The destination department must differ from the asset’s current department.'
                );
            }

            /*
             * Use the actual department name in the transfer history,
             * rather than relying on the user's capitalization.
             */
            $destinationNameStmt = $this->db->prepare(
                'SELECT name
                    FROM departments
                    WHERE id = :department_id
                    LIMIT 1'
            );

            $destinationNameStmt->execute([
                'department_id' => $destinationDepartmentId,
            ]);

            $canonicalDestinationName =
                $destinationNameStmt->fetchColumn();

            if ($canonicalDestinationName === false) {
                throw new RuntimeException(
                    'The destination department does not exist or is inactive.'
                );
            }

            /*
             * Update only department ownership.
             * Location and employee assignment remain unchanged.
             */
            $assetUpdate = $this->db->prepare(
                'UPDATE assets
                 SET department_id = :destination_department_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :asset_id
                   AND department_id = :source_department_id'
            );

            $assetUpdate->execute([
                'destination_department_id' => $destinationDepartmentId,
                'asset_id' => $assetId,
                'source_department_id' => $sourceDepartmentId,
            ]);

            if ($assetUpdate->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update the asset department. No transfer was completed.'
                );
            }

            $transferId = $this->transferModel->createCompletedTransfer(
                $assetId,
                $sourceDepartmentId,
                $destinationDepartmentId,
                (string) $canonicalDestinationName,
                $requestedBy,
                $requestReason
            );

            if ($transferId <= 0) {
                throw new RuntimeException(
                    'Unable to record the completed department transfer.'
                );
            }

            $this->db->commit();

            return $transferId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }



    /**
     * The application no longer uses an approval workflow
     * for department transfers.
     */
    public function approve(
        int $requestId,
        int $reviewedBy,
        ?string $reviewNotes = null
    ): void {
        throw new RuntimeException(
            'Department transfers are completed immediately. Approval is no longer available.'
        );
    }


    /**
     * The application no longer uses a rejection workflow
     * for department transfers.
     */
    public function reject(
        int $requestId,
        int $reviewedBy,
        ?string $reviewNotes = null
    ): void {
        throw new RuntimeException(
            'Department transfers are completed immediately. Rejection is no longer available.'
        );
    }

    /**
     * Get the complete department-transfer history for the
     * Asset Manager/Property Custodian.
     */
    public function getDepartmentHistory(): array
    {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException('Authentication required.');
        }

        $this->requireActor((int) $actorId, []);

        return $this->transferModel->getAllRequests();
    }

    /**
     * Get one department-transfer record for the authenticated
     * Asset Manager/Property Custodian.
     */
    public function getRequestForCurrentUser(
        int $requestId
    ): array {
        if ($requestId <= 0) {
            throw new RuntimeException(
                'Invalid department transfer request.'
            );
        }

        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException('Authentication required.');
        }

        $this->requireActor((int) $actorId, []);

        $request = $this->transferModel->findById($requestId);

        if (!$request) {
            throw new RuntimeException(
                'Department transfer record not found.'
            );
        }

        return $request;
    }

    /**
     * Retrieve complete transfer history for an asset.
     *
     * Access is restricted to the Asset Manager/Property Custodian.
     * Historical transfer records are not modified.
     */
    public function getHistoryByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            throw new RuntimeException('Invalid asset.');
        }

        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException('Authentication required.');
        }

        $this->requireActor((int) $actorId, []);

        $asset = $this->assetModel->findById($assetId);

        if (!$asset) {
            throw new RuntimeException('Asset not found.');
        }

        return $this->transferModel->getHistoryByAssetId($assetId);
    }


    /**
     * Reload the authenticated user and enforce the department-transfer
     * access policy.
     *
     * Only the active account named "manager" with the active
     * "Asset Manager" role may use department transfers.
     * Administrators and all other accounts are denied.
     */
    private function requireActor(
        int $providedUserId,
        array $allowedRoles = []
    ): array {
        if ($providedUserId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || (int) $authenticatedUserId !== $providedUserId
        ) {
            throw new RuntimeException(
                'The acting user does not match the authenticated user.'
            );
        }

        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required. Your account may be inactive.'
            );
        }

        $username = (string) ($user['username'] ?? '');
        $roleName = (string) ($user['role_name'] ?? '');

        if (
            $username !== 'manager'
            || $roleName !== 'Asset Manager'
        ) {
            throw new RuntimeException(
                'You do not have permission to perform this department transfer action.'
            );
        }

        $roleStatusStmt = $this->db->prepare(
            "SELECT r.is_active
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id
           AND u.username = 'manager'
           AND u.is_active = 1
           AND u.account_status = 'active'
           AND r.name = 'Asset Manager'
           AND r.is_active = 1
         LIMIT 1"
        );

        $roleStatusStmt->execute([
            'user_id' => $providedUserId,
        ]);

        if ($roleStatusStmt->fetchColumn() === false) {
            throw new RuntimeException(
                'Your account or role is inactive or unavailable.'
            );
        }

        return $user;
    }
    /**
     * Resolves the destination department by name.
     * Reuses an existing department or creates it when missing.
     *
     * @throws RuntimeException If the destination department cannot be resolved.
     */

    private function resolveExistingDestinationDepartment(
        string $departmentName
    ): int {
        $departmentName = trim($departmentName);

        if (
            $departmentName === ''
            || mb_strlen($departmentName) > 150
        ) {
            throw new RuntimeException(
                'Enter a destination department name of no more than 150 characters.'
            );
        }

        $findDepartment = $this->db->prepare(
            'SELECT id
         FROM departments
         WHERE name = :name
         LIMIT 1
         FOR UPDATE'
        );

        $findDepartment->execute([
            'name' => $departmentName,
        ]);

        $departmentId = $findDepartment->fetchColumn();

        if ($departmentId !== false) {
            return (int) $departmentId;
        }

        try {
            $createDepartment = $this->db->prepare(
                'INSERT INTO departments (name, code, description, is_active)
             VALUES (:name, NULL, NULL, 1)'
            );

            $createDepartment->execute([
                'name' => $departmentName,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            /*
             * Another request may have created the same department
             * between our lookup and insert. Reuse that record.
             */
            $findDepartment->execute([
                'name' => $departmentName,
            ]);

            $departmentId = $findDepartment->fetchColumn();

            if ($departmentId !== false) {
                return (int) $departmentId;
            }

            throw $e;
        }
    }

    /**
     * Prevent ownership transfer for assets that are already
     * lost, retired, disposed, or pending disposal.
     */
    private function assertAssetCanTransfer(
        array $asset
    ): void {
        $statusName = strtolower(
            trim((string) ($asset['status_name'] ?? ''))
        );

        if (
            in_array(
                $statusName,
                [
                    'lost',
                    'retired',
                    'disposed',
                    'for disposal',
                    'pending disposal',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'An asset that is lost, retired, disposed, or pending disposal cannot be transferred to another department.'
            );
        }

        if (!empty($asset['assigned_to_user_id'])) {
            throw new RuntimeException(
                'Return the asset before requesting a department ownership transfer.'
            );
        }
    }
}