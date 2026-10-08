<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\AssetDisposal;
use RuntimeException;
use App\Services\AuthorizationService;

class AssetDisposalService
{
    private \PDO $db;
    private Asset $assetModel;
    private AssetDisposal $disposalModel;
    private AuthorizationService $authorization;

    private const STATUS_AVAILABLE = 1;
    private const STATUS_ASSIGNED = 2;
    private const STATUS_UNDER_MAINTENANCE = 3;
    private const STATUS_UNDER_REPAIR = 4;
    private const STATUS_LOST = 5;
    private const STATUS_DAMAGED = 6;
    private const STATUS_FOR_DISPOSAL = 7;
    private const STATUS_RETIRED = 8;
    private const STATUS_DISPOSED = 9;

    private const ALLOWED_STATUSES = [
        'pending',
        'approved',
        'completed',
        'cancelled',
    ];

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->disposalModel = new AssetDisposal();
        $this->authorization = new AuthorizationService();
    }

    public function create(
        int $assetId,
        int $createdBy,
        array $data
    ): int {
        $this->validateUser($createdBy);

        $asset = $this->assetModel->findById($assetId);

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess(
            $asset
        );

        if (
            \App\Core\Auth::id() === null
            || (int) \App\Core\Auth::id() !== $createdBy
        ) {
            throw new RuntimeException(
                'Invalid creating user.'
            );
        }

        $this->validateAssetForDisposal($asset);

        $disposalType =
            trim((string) ($data['disposal_type'] ?? ''));

        if ($disposalType === '') {
            throw new RuntimeException(
                'Disposal type is required.'
            );
        }

        if (mb_strlen($disposalType) > 100) {
            throw new RuntimeException(
                'Disposal type must not exceed 100 characters.'
            );
        }

        $disposalReason =
            trim((string) ($data['disposal_reason'] ?? ''));

        if (
            $disposalReason !== ''
            && mb_strlen($disposalReason) > 255
        ) {
            throw new RuntimeException(
                'Disposal reason must not exceed 255 characters.'
            );
        }

        $description =
            trim((string) ($data['description'] ?? ''));

        $disposalDate =
            trim((string) ($data['disposal_date'] ?? ''));

        $this->validateDate(
            $disposalDate,
            'Disposal date'
        );

        $reference =
            trim(
                (string) (
                    $data['disposal_reference'] ?? ''
                )
            );

        if (
            $reference !== ''
            && mb_strlen($reference) > 100
        ) {
            throw new RuntimeException(
                'Disposal reference must not exceed 100 characters.'
            );
        }

        $remarks =
            trim((string) ($data['remarks'] ?? ''));

        $disposalValue =
            $this->normalizeMoney(
                $data['disposal_value'] ?? 0,
                'Disposal value'
            );

        $proceeds =
            $this->normalizeMoney(
                $data['proceeds'] ?? 0,
                'Proceeds'
            );

        $bookValue =
            $this->normalizeMoney(
                $asset['current_book_value'] ?? 0,
                'Book value'
            );

        $purchaseCost =
            $this->normalizeMoney(
                $asset['purchase_cost'] ?? 0,
                'Purchase cost'
            );

        $accumulatedDepreciation =
            max(
                0,
                $purchaseCost - $bookValue
            );

        $existing =
            $this->disposalModel->getLatestByAssetId(
                $assetId
            );

        if (
            $existing
            && in_array(
                (string) $existing['disposal_status'],
                [
                    'pending',
                    'approved',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'This asset already has an active disposal request.'
            );
        }

        $this->db->beginTransaction();

        try {
            $disposalId =
                $this->disposalModel->create([
                    'asset_id' =>
                        $assetId,

                    'disposal_type' =>
                        $disposalType,

                    'disposal_reason' =>
                        $disposalReason !== ''
                        ? $disposalReason
                        : null,

                    'description' =>
                        $description !== ''
                        ? $description
                        : null,

                    'disposal_date' =>
                        $disposalDate,

                    'approved_by' =>
                        null,

                    'approved_at' =>
                        null,

                    'disposed_by' =>
                        $createdBy,

                    'disposal_reference' =>
                        $reference !== ''
                        ? $reference
                        : null,

                    'disposal_value' =>
                        $disposalValue,

                    'accumulated_depreciation' =>
                        $accumulatedDepreciation,

                    'book_value_at_disposal' =>
                        $bookValue,

                    'proceeds' =>
                        $proceeds,

                    'disposal_status' =>
                        'pending',

                    'remarks' =>
                        $remarks !== ''
                        ? $remarks
                        : null,
                ]);

            $this->setAssetStatus(
                $assetId,
                self::STATUS_FOR_DISPOSAL
            );

            $this->db->commit();

            return $disposalId;

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function approve(
        int $disposalId,
        int $approvedBy
    ): void {
        $this->validateUser($approvedBy);

        $disposal =
            $this->disposalModel->findById(
                $disposalId
            );

        if (!$disposal) {
            throw new RuntimeException(
                'Disposal record not found.'
            );
        }

        $asset =
            $this->assetModel->findById(
                (int) $disposal['asset_id']
            );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess(
            $asset,
            true
        );

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || (int) $authenticatedUserId !== $approvedBy
        ) {
            throw new RuntimeException(
                'Invalid approving user.'
            );
        }

        if (
            (string) $disposal['disposal_status']
            !== 'pending'
        ) {
            throw new RuntimeException(
                'Only pending disposal requests can be approved.'
            );
        }

        if (
            (int) $disposal['disposed_by']
            === $approvedBy
        ) {
            throw new RuntimeException(
                'The person who created the disposal request cannot approve the same request.'
            );
        }

        $this->db->beginTransaction();

        try {
            $updated =
                $this->disposalModel->update(
                    $disposalId,
                    [
                        'disposal_type' =>
                            $disposal['disposal_type'],

                        'disposal_reason' =>
                            $disposal['disposal_reason'],

                        'description' =>
                            $disposal['description'],

                        'disposal_date' =>
                            $disposal['disposal_date'],

                        'approved_by' =>
                            $approvedBy,

                        'approved_at' =>
                            date('Y-m-d H:i:s'),

                        'disposed_by' =>
                            (int) $disposal['disposed_by'],

                        'disposal_reference' =>
                            $disposal['disposal_reference'],

                        'disposal_value' =>
                            $disposal['disposal_value'],

                        'accumulated_depreciation' =>
                            $disposal['accumulated_depreciation'],

                        'book_value_at_disposal' =>
                            $disposal['book_value_at_disposal'],

                        'proceeds' =>
                            $disposal['proceeds'],

                        'disposal_status' =>
                            'approved',

                        'remarks' =>
                            $disposal['remarks'],
                    ]
                );

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to approve the disposal request.'
                );
            }

            $this->setAssetStatus(
                (int) $disposal['asset_id'],
                self::STATUS_FOR_DISPOSAL
            );

            $this->db->commit();

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function complete(
        int $disposalId,
        int $completedBy
    ): void {
        $this->validateUser($completedBy);

        $disposal =
            $this->disposalModel->findById(
                $disposalId
            );

        if (!$disposal) {
            throw new RuntimeException(
                'Disposal record not found.'
            );
        }

        $asset =
            $this->assetModel->findById(
                (int) $disposal['asset_id']
            );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess(
            $asset,
            true
        );

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || (int) $authenticatedUserId !== $completedBy
        ) {
            throw new RuntimeException(
                'Invalid completing user.'
            );
        }

        if (
            (string) $disposal['disposal_status']
            !== 'approved'
        ) {
            throw new RuntimeException(
                'Only approved disposal requests can be completed.'
            );
        }

        $this->db->beginTransaction();

        try {
            $updated =
                $this->disposalModel->update(
                    $disposalId,
                    [
                        'disposal_type' =>
                            $disposal['disposal_type'],

                        'disposal_reason' =>
                            $disposal['disposal_reason'],

                        'description' =>
                            $disposal['description'],

                        'disposal_date' =>
                            $disposal['disposal_date'],

                        'approved_by' =>
                            $disposal['approved_by'],

                        'approved_at' =>
                            $disposal['approved_at'],

                        'disposed_by' =>
                            $completedBy,

                        'disposal_reference' =>
                            $disposal['disposal_reference'],

                        'disposal_value' =>
                            $disposal['disposal_value'],

                        'accumulated_depreciation' =>
                            $disposal['accumulated_depreciation'],

                        'book_value_at_disposal' =>
                            $disposal['book_value_at_disposal'],

                        'proceeds' =>
                            $disposal['proceeds'],

                        'disposal_status' =>
                            'completed',

                        'remarks' =>
                            $disposal['remarks'],
                    ]
                );

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to complete the disposal.'
                );
            }

            $this->setAssetStatus(
                (int) $disposal['asset_id'],
                self::STATUS_DISPOSED
            );

            $this->db->commit();

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function cancel(
        int $disposalId,
        int $cancelledBy
    ): void {
        $this->validateUser($cancelledBy);

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || (int) $authenticatedUserId !== $cancelledBy
        ) {
            throw new RuntimeException(
                'Invalid cancelling user.'
            );
        }

        $disposal =
            $this->disposalModel->findById(
                $disposalId
            );

        if (!$disposal) {
            throw new RuntimeException(
                'Disposal record not found.'
            );
        }

        $currentStatus =
            (string) $disposal['disposal_status'];

        if (
            !in_array(
                $currentStatus,
                [
                    'pending',
                    'approved',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Only pending or approved disposal requests can be cancelled.'
            );
        }

        $asset =
            $this->assetModel->findById(
                (int) $disposal['asset_id']
            );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess(
            $asset
        );

        $this->db->beginTransaction();

        try {
            $updated =
                $this->disposalModel->update(
                    $disposalId,
                    [
                        'disposal_type' =>
                            $disposal['disposal_type'],

                        'disposal_reason' =>
                            $disposal['disposal_reason'],

                        'description' =>
                            $disposal['description'],

                        'disposal_date' =>
                            $disposal['disposal_date'],

                        'approved_by' =>
                            $disposal['approved_by'],

                        'approved_at' =>
                            $disposal['approved_at'],

                        'disposed_by' =>
                            (int) $disposal['disposed_by'],

                        'disposal_reference' =>
                            $disposal['disposal_reference'],

                        'disposal_value' =>
                            $disposal['disposal_value'],

                        'accumulated_depreciation' =>
                            $disposal['accumulated_depreciation'],

                        'book_value_at_disposal' =>
                            $disposal['book_value_at_disposal'],

                        'proceeds' =>
                            $disposal['proceeds'],

                        'disposal_status' =>
                            'cancelled',

                        'remarks' =>
                            $disposal['remarks'],
                    ]
                );

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to cancel the disposal request.'
                );
            }

            $this->restoreAssetStatus(
                (int) $disposal['asset_id'],
                $asset
            );

            $this->db->commit();

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function authorizeAssetAccess(
        array $asset,
        bool $managerOnly = false
    ): void {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if ($managerOnly) {
            if (!$this->authorization->isAssetManager()) {
                throw new RuntimeException(
                    'Only Asset Managers can perform this disposal action.'
                );
            }
        } elseif (
            !$this->authorization->isAssetManager()
            && !$this->authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to manage asset disposal.'
            );
        }

        $userDepartmentId =
            $this->authorization->departmentId();

        $assetDepartmentId =
            $asset['department_id'] === null
            ? null
            : (int) $asset['department_id'];

        if (
            $userDepartmentId === null
            || $assetDepartmentId === null
            || $userDepartmentId !== $assetDepartmentId
        ) {
            throw new RuntimeException(
                'You may only manage disposal for assets belonging to your department.'
            );
        }
    }

    private function validateAssetForDisposal(
        array $asset
    ): void {
        $statusId =
            (int) ($asset['status_id'] ?? 0);

        if (
            in_array(
                $statusId,
                [
                    self::STATUS_DISPOSED,
                    self::STATUS_RETIRED,
                    self::STATUS_LOST,
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'This asset cannot be submitted for disposal in its current status.'
            );
        }

        if (
            $statusId === self::STATUS_FOR_DISPOSAL
        ) {
            throw new RuntimeException(
                'This asset is already marked for disposal.'
            );
        }
    }

    private function restoreAssetStatus(
        int $assetId,
        array $asset
    ): void {
        $statusId =
            !empty($asset['assigned_to_user_id'])
            ? self::STATUS_ASSIGNED
            : self::STATUS_AVAILABLE;

        $this->setAssetStatus(
            $assetId,
            $statusId
        );
    }

    private function setAssetStatus(
        int $assetId,
        int $statusId
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE assets
            SET
                status_id = :status_id,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :asset_id'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'status_id' => $statusId,
        ]);
    }

    private function validateUser(
        int $userId
    ): void {
        if ($userId <= 0) {
            throw new RuntimeException(
                'Invalid user.'
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id
            FROM users
            WHERE id = :id
              AND is_active = 1
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        if (!$stmt->fetch()) {
            throw new RuntimeException(
                'User is not active.'
            );
        }
    }

    private function validateDate(
        string $date,
        string $label
    ): void {
        $parsed =
            \DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $date
            );

        if (
            !$parsed
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new RuntimeException(
                $label . ' must be a valid date.'
            );
        }
    }

    private function normalizeMoney(
        mixed $value,
        string $label
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return '0.00';
        }

        if (
            !is_numeric($value)
            || (float) $value < 0
        ) {
            throw new RuntimeException(
                $label . ' must be a valid non-negative amount.'
            );
        }

        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }
}