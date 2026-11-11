<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\AssetDepartmentTransfer;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

class AssetDepartmentTransferService
{
    private PDO $db;

    private Asset $assetModel;

    private AssetDepartmentTransfer $transferModel;

    private AuthorizationService $authorization;

    private NotificationService $notifications;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->transferModel = new AssetDepartmentTransfer();
        $this->authorization = new AuthorizationService();
        $this->notifications = new NotificationService();
    }

    /**
     * Submit a department-transfer request for manager approval.
     *
     * The asset's department is not changed when a request is submitted.
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

        $this->requireActor(
            $requestedBy,
            ['Property Custodian']
        );

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
                    'The asset must have a current responsible department before a transfer can be requested.'
                );
            }

            $sourceStmt = $this->db->prepare(
                'SELECT id
                 FROM departments
                 WHERE id = :department_id
                   AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );

            $sourceStmt->execute([
                'department_id' => $sourceDepartmentId,
            ]);

            if ($sourceStmt->fetchColumn() === false) {
                throw new RuntimeException(
                    'The asset’s current department does not exist or is inactive.'
                );
            }

            $destinationStmt = $this->db->prepare(
                'SELECT id, name
                 FROM departments
                 WHERE name = :department_name
                   AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );

            $destinationStmt->execute([
                'department_name' => $destinationDepartmentName,
            ]);

            $destination = $destinationStmt->fetch(PDO::FETCH_ASSOC);

            if (!$destination) {
                throw new RuntimeException(
                    'Select an existing active destination department.'
                );
            }

            $destinationDepartmentId = (int) $destination['id'];

            if ($destinationDepartmentId === $sourceDepartmentId) {
                throw new RuntimeException(
                    'The destination department must differ from the asset’s current department.'
                );
            }

            /*
             * Prevent multiple outstanding requests for the same asset.
             * The asset row is already locked within this transaction.
             */
            $pendingStmt = $this->db->prepare(
                "SELECT id
                 FROM asset_department_transfers
                 WHERE asset_id = :asset_id
                   AND request_status = 'pending'
                 LIMIT 1
                 FOR UPDATE"
            );

            $pendingStmt->execute([
                'asset_id' => $assetId,
            ]);

            if ($pendingStmt->fetchColumn() !== false) {
                throw new RuntimeException(
                    'This asset already has a pending department-transfer request.'
                );
            }

            /*
             * Record the request only. Do not update assets.department_id
             * until the Asset Manager approves the request.
             */
            $requestId = $this->transferModel->createPendingRequest(
                $assetId,
                $sourceDepartmentId,
                $destinationDepartmentId,
                (string) $destination['name'],
                $requestedBy,
                $requestReason
            );

            if ($requestId <= 0) {
                throw new RuntimeException(
                    'Unable to record the department-transfer request.'
                );
            }

            (new AuditLogService())->log(
                action: 'request_department_transfer',
                module: 'department_transfers',
                entityType: 'department_transfer',
                entityId: $requestId,
                description: 'Department-transfer request submitted successfully.',
                newValues: [
                    'asset_id' => $assetId,
                    'source_department_id' => $sourceDepartmentId,
                    'destination_department_id' => $destinationDepartmentId,
                    'destination_department_name' => (string) $destination['name'],
                    'requested_by' => $requestedBy,
                    'request_status' => 'pending',
                    'request_reason' => $requestReason,
                ]
            );

            $this->db->commit();

            $notificationTitle =
                'New Asset Transfer Request';

            $notificationMessage =
                'Transfer requested for '
                . (string) $asset['asset_code']
                . ' — '
                . (string) $asset['asset_name']
                . '. Destination department: '
                . (string) $destination['name']
                . '. Awaiting Asset Manager review.';

            $this->notifications->notifyActiveAssetManagers(
                'department_transfer_requested',
                $notificationTitle,
                $notificationMessage,
                'department_transfer',
                $requestId
            );

            $this->notifications->emailActiveAssetManagers(
                $notificationTitle,
                $notificationMessage
            );

            return $requestId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }



    /**
     * Approve a pending request and update the asset's department
     * within the same transaction.
     */
    public function approve(
        int $requestId,
        int $reviewedBy,
        ?string $reviewNotes = null
    ): void {
        if ($requestId <= 0) {
            throw new RuntimeException(
                'Invalid department-transfer request.'
            );
        }

        if (mb_strlen(trim((string) $reviewNotes)) > 500) {
            throw new RuntimeException(
                'Review notes must not exceed 500 characters.'
            );
        }

        $this->requireActor(
            $reviewedBy,
            ['Asset Manager']
        );

        $this->db->beginTransaction();

        try {
            $request = $this->transferModel->findByIdForUpdate(
                $requestId
            );

            if (!$request) {
                throw new RuntimeException(
                    'Department-transfer request not found.'
                );
            }

            if (($request['request_status'] ?? '') !== 'pending') {
                throw new RuntimeException(
                    'This request has already been reviewed or is no longer pending.'
                );
            }

            $assetId = (int) ($request['asset_id'] ?? 0);

            $asset = $this->assetModel->findByIdForUpdate(
                $assetId
            );

            if (!$asset) {
                throw new RuntimeException(
                    'The asset associated with this request no longer exists.'
                );
            }

            $this->assertAssetCanTransfer($asset);

            $sourceDepartmentId = (int) (
                $request['source_department_id'] ?? 0
            );

            $destinationDepartmentId = (int) (
                $request['destination_department_id'] ?? 0
            );

            $currentDepartmentId = (int) (
                $asset['department_id'] ?? 0
            );

            if (
                $sourceDepartmentId <= 0
                || $destinationDepartmentId <= 0
                || $sourceDepartmentId === $destinationDepartmentId
            ) {
                throw new RuntimeException(
                    'The transfer request contains invalid department details.'
                );
            }

            if ($currentDepartmentId !== $sourceDepartmentId) {
                throw new RuntimeException(
                    'The asset’s current department has changed since this request was submitted. The request cannot be approved.'
                );
            }

            $destinationStmt = $this->db->prepare(
                'SELECT id
                 FROM departments
                 WHERE id = :department_id
                   AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );

            $destinationStmt->execute([
                'department_id' => $destinationDepartmentId,
            ]);

            if ($destinationStmt->fetchColumn() === false) {
                throw new RuntimeException(
                    'The destination department no longer exists or is inactive.'
                );
            }

            $assetStmt = $this->db->prepare(
                'UPDATE assets
                 SET department_id = :department_id
                 WHERE id = :asset_id
                   AND department_id = :source_department_id'
            );

            $assetStmt->execute([
                'department_id' => $destinationDepartmentId,
                'asset_id' => $assetId,
                'source_department_id' => $sourceDepartmentId,
            ]);

            if ($assetStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The asset department was not updated. The request was not approved.'
                );
            }

            $updated = $this->transferModel->updateDecision(
                $requestId,
                $reviewedBy,
                'approved',
                $reviewNotes !== null && trim($reviewNotes) !== ''
                ? trim($reviewNotes)
                : null
            );

            if (!$updated) {
                throw new RuntimeException(
                    'The request could not be marked as approved.'
                );
            }

            (new AuditLogService())->log(
                action: 'approve_department_transfer',
                module: 'department_transfers',
                entityType: 'department_transfer',
                entityId: $requestId,
                description: 'Department-transfer request approved successfully.',
                oldValues: [
                    'asset_id' => $assetId,
                    'department_id' => $sourceDepartmentId,
                    'request_status' => 'pending',
                ],
                newValues: [
                    'asset_id' => $assetId,
                    'department_id' => $destinationDepartmentId,
                    'request_status' => 'approved',
                    'reviewed_by' => $reviewedBy,
                    'review_notes' => $reviewNotes !== null && trim($reviewNotes) !== ''
                        ? trim($reviewNotes)
                        : null,
                ]
            );

            $this->db->commit();

            $this->notifications->notifyUser(
                (int) ($request['requested_by'] ?? 0),
                'department_transfer_approved',
                'Department Transfer Approved',
                'The transfer request for '
                . (string) $asset['asset_code']
                . ' — '
                . (string) $asset['asset_name']
                . ' has been approved.',
                'department_transfer',
                $requestId
            );

            $this->notifications->emailUser(
                (int) ($request['requested_by'] ?? 0),
                'Department Transfer Approved',
                'The transfer request for '
                . (string) $asset['asset_code']
                . ' — '
                . (string) $asset['asset_name']
                . ' has been approved.'
            );


        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Reject a pending request without changing the asset's department.
     */
    public function reject(
        int $requestId,
        int $reviewedBy,
        ?string $reviewNotes = null
    ): void {
        if ($requestId <= 0) {
            throw new RuntimeException(
                'Invalid department-transfer request.'
            );
        }

        $reviewNotes = trim((string) $reviewNotes);

        if ($reviewNotes === '') {
            throw new RuntimeException(
                'A reason is required when rejecting a transfer request.'
            );
        }

        if (mb_strlen($reviewNotes) > 500) {
            throw new RuntimeException(
                'The rejection reason must not exceed 500 characters.'
            );
        }

        $this->requireActor(
            $reviewedBy,
            ['Asset Manager']
        );

        $this->db->beginTransaction();

        try {
            $request = $this->transferModel->findByIdForUpdate(
                $requestId
            );

            if (!$request) {
                throw new RuntimeException(
                    'Department-transfer request not found.'
                );
            }

            if (($request['request_status'] ?? '') !== 'pending') {
                throw new RuntimeException(
                    'This request has already been reviewed or is no longer pending.'
                );
            }

            $asset = $this->assetModel->findById(
                (int) ($request['asset_id'] ?? 0)
            );

            if (!$asset) {
                throw new RuntimeException(
                    'The asset associated with this transfer request could not be found.'
                );
            }

            $updated = $this->transferModel->updateDecision(
                $requestId,
                $reviewedBy,
                'rejected',
                $reviewNotes
            );

            if (!$updated) {
                throw new RuntimeException(
                    'The request could not be marked as rejected.'
                );
            }


            (new AuditLogService())->log(
                action: 'reject_department_transfer',
                module: 'department_transfers',
                entityType: 'department_transfer',
                entityId: $requestId,
                description: 'Department-transfer request rejected successfully.',
                oldValues: [
                    'asset_id' => (int) ($request['asset_id'] ?? 0),
                    'source_department_id' => (int) ($request['source_department_id'] ?? 0),
                    'destination_department_id' => (int) ($request['destination_department_id'] ?? 0),
                    'request_status' => 'pending',
                ],
                newValues: [
                    'asset_id' => (int) ($request['asset_id'] ?? 0),
                    'source_department_id' => (int) ($request['source_department_id'] ?? 0),
                    'destination_department_id' => (int) ($request['destination_department_id'] ?? 0),
                    'request_status' => 'rejected',
                    'reviewed_by' => $reviewedBy,
                    'review_notes' => $reviewNotes,
                ]
            );

            $this->db->commit();

            $this->notifications->notifyUser(
                (int) ($request['requested_by'] ?? 0),
                'department_transfer_rejected',
                'Department Transfer Rejected',
                'The transfer request for '
                . (string) $asset['asset_code']
                . ' — '
                . (string) $asset['asset_name']
                . ' has been rejected. Reason: '
                . $reviewNotes,
                'department_transfer',
                $requestId
            );

            $this->notifications->emailUser(
                (int) ($request['requested_by'] ?? 0),
                'Department Transfer Rejected',
                'The transfer request for '
                . (string) $asset['asset_code']
                . ' — '
                . (string) $asset['asset_name']
                . ' has been rejected. Reason: '
                . $reviewNotes
            );


        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }


    /**
     * Retrieve pending department-transfer requests for the
     * designated Asset Manager's approval queue.
     */
    public function getPendingRequests(): array
    {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $this->requireActor(
            (int) $actorId,
            ['Asset Manager']
        );

        return $this->transferModel->getPendingRequests();
    }

    /**
     * Get the complete department-transfer history for the
     * Asset Manager/Property Custodian.
     */
    public function getDepartmentHistory(): array
    {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $actor = $this->requireActor(
            (int) $actorId,
            ['Asset Manager', 'Property Custodian']
        );

        if (($actor['role_name'] ?? '') === 'Asset Manager') {
            return $this->transferModel->getAllRequests();
        }

        return $this->transferModel->getRequestsByRequester(
            (int) $actorId
        );
    }

    /**
     * Get filtered and paginated department-transfer history
     * for the authenticated Asset Manager or Property Custodian.
     */
    public function getPaginatedDepartmentHistory(
        string $search = '',
        string $status = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $actor = $this->requireActor(
            (int) $actorId,
            ['Asset Manager', 'Property Custodian']
        );

        $requesterId = ($actor['role_name'] ?? '') === 'Property Custodian'
            ? (int) $actorId
            : null;

        return $this->transferModel->getPaginatedRequests(
            $requesterId,
            trim($search),
            $status,
            max(1, $page),
            $perPage
        );
    }
    /**
     * Get transfer-history summary counts for the authenticated user.
     */
    public function getDepartmentHistorySummary(): array
    {
        $actorId = Auth::id();

        if ($actorId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $actor = $this->requireActor(
            (int) $actorId,
            ['Asset Manager', 'Property Custodian']
        );

        $requesterId = ($actor['role_name'] ?? '') === 'Property Custodian'
            ? (int) $actorId
            : null;

        return $this->transferModel->getRequestSummary(
            $requesterId
        );
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

        $actor = $this->requireActor(
            (int) $actorId,
            ['Asset Manager', 'Property Custodian']
        );

        $request = $this->transferModel->findById($requestId);

        if (!$request) {
            throw new RuntimeException(
                'Department transfer record not found.'
            );
        }

        if (
            ($actor['role_name'] ?? '') === 'Property Custodian'
            && (int) ($request['requested_by'] ?? 0) !== (int) $actorId
        ) {
            throw new RuntimeException(
                'You do not have permission to view this transfer request.'
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

        $this->requireActor(
            (int) $actorId,
            ['Asset Manager', 'Property Custodian']
        );

        $asset = $this->assetModel->findById($assetId);

        if (!$asset) {
            throw new RuntimeException('Asset not found.');
        }

        return $this->transferModel->getHistoryByAssetId($assetId);
    }



    /**
     * Reload the authenticated user and enforce the allowed role.
     *
     * Asset Manager accounts may review transfers.
     * Property Custodian accounts may submit requests and view their
     * own requests, but may not approve or reject transfers.
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

        $roleName = (string) ($user['role_name'] ?? '');

        if (!in_array($roleName, $allowedRoles, true)) {
            throw new RuntimeException(
                'You do not have permission to perform this department-transfer action.'
            );
        }

        $roleStatusStmt = $this->db->prepare(
            'SELECT r.is_active
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND u.is_active = 1
               AND u.account_status = :account_status
               AND r.name = :role_name
               AND r.is_active = 1
             LIMIT 1'
        );

        $roleStatusStmt->execute([
            'user_id' => $providedUserId,
            'account_status' => 'active',
            'role_name' => $roleName,
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