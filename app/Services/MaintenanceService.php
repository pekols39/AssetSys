<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Services\AuthorizationService;
use PDO;
use RuntimeException;

class MaintenanceService
{
    private PDO $db;
    private Asset $assetModel;
    private MaintenanceRecord $maintenanceModel;
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->maintenanceModel = new MaintenanceRecord();
        $this->authorization = new AuthorizationService();
    }

    public function findById(int $id): array|false
    {
        if ($id <= 0) {
            return false;
        }

        return $this->maintenanceModel->findById($id);
    }

    public function getByAssetId(int $assetId): array
    {
        if ($assetId <= 0) {
            return [];
        }

        return $this->maintenanceModel->getByAssetId($assetId);
    }

    public function create(
        array $data,
        int $createdBy
    ): int {
        if ($createdBy <= 0) {
            throw new RuntimeException(
                'Invalid creator.'
            );
        }

        $data = $this->validateAndNormalize(
            $data
        );

        $asset = $this->assetModel->findById(
            (int) $data['asset_id']
        );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess($asset);

        $data['created_by'] = $createdBy;
        $data['completed_by'] = null;

        if (
            $data['maintenance_status'] === 'completed'
        ) {
            $data['completed_by'] = $createdBy;

            if ($data['completed_at'] === null) {
                $data['completed_at'] = date(
                    'Y-m-d H:i:s'
                );
            }

            if ($data['started_at'] === null) {
                $data['started_at'] = $data['completed_at'];
            }
        }

        $this->db->beginTransaction();

        try {
            $this->validateMaintenanceStartStatus(
                $asset,
                $data['maintenance_status']
            );

            if ($data['maintenance_status'] === 'in_progress') {
                $this->ensureNoActiveMaintenance((int) $data['asset_id']);
            }

            $maintenanceId =
                $this->maintenanceModel->create(
                    $data
                );

            if (
                $data['maintenance_status']
                === 'in_progress'
            ) {
                $this->setAssetUnderMaintenance(
                    (int) $asset['id']
                );
            }

            $this->db->commit();

            return $maintenanceId;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function update(
        int $id,
        array $data
    ): bool {
        if ($id <= 0) {
            throw new RuntimeException(
                'Invalid maintenance record.'
            );
        }

        $existing = $this->maintenanceModel->findById(
            $id
        );

        if (!$existing) {
            throw new RuntimeException(
                'Maintenance record not found.'
            );
        }

        $asset = $this->assetModel->findById(
            (int) $existing['asset_id']
        );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess($asset);

        $data = $this->validateAndNormalize(
            $data
        );

        if (
            (int) $data['asset_id']
            !== (int) $existing['asset_id']
        ) {
            throw new RuntimeException(
                'Maintenance asset cannot be changed.'
            );
        }

        if (
            $data['maintenance_status'] === 'completed'
        ) {
            if ($data['completed_at'] === null) {
                $data['completed_at'] =
                    $existing['completed_at']
                    ?? date('Y-m-d H:i:s');
            }

            if ($data['started_at'] === null) {
                $data['started_at'] =
                    $existing['started_at']
                    ?? $data['completed_at'];
            }

            $data['completed_by'] =
                (int) ($existing['completed_by'] ?? 0);

            if ($data['completed_by'] <= 0) {
                /*
                 * The current authenticated user should
                 * be supplied by the caller for a completion
                 * transition. This is handled by the
                 * updateWithUser() method below.
                 */
                throw new RuntimeException(
                    'Completed-by user is required.'
                );
            }
        } else {
            $data['completed_by'] = null;
            $data['completed_at'] = null;
        }

        return $this->maintenanceModel->update(
            $id,
            $data
        );
    }

    public function updateWithUser(
        int $id,
        array $data,
        int $updatedBy
    ): bool {
        if ($updatedBy <= 0) {
            throw new RuntimeException(
                'Invalid user.'
            );
        }

        if ($id <= 0) {
            throw new RuntimeException(
                'Invalid maintenance record.'
            );
        }

        $existing = $this->maintenanceModel->findById(
            $id
        );

        if (!$existing) {
            throw new RuntimeException(
                'Maintenance record not found.'
            );
        }

        $data = $this->validateAndNormalize(
            $data
        );

        $assetId = (int) $existing['asset_id'];

        if (
            (int) $data['asset_id'] !== $assetId
        ) {
            throw new RuntimeException(
                'Maintenance asset cannot be changed.'
            );
        }

        $oldStatus = strtolower(
            trim(
                (string) (
                    $existing['maintenance_status']
                    ?? ''
                )
            )
        );

        $newStatus = $data['maintenance_status'];

        /*
         * Completing a maintenance record automatically
         * records the current user as the completer.
         */
        if ($newStatus === 'completed') {
            if ($data['completed_at'] === null) {
                $data['completed_at'] =
                    $existing['completed_at']
                    ?? date('Y-m-d H:i:s');
            }

            if ($data['started_at'] === null) {
                $data['started_at'] =
                    $existing['started_at']
                    ?? $data['completed_at'];
            }

            $data['completed_by'] = $updatedBy;
        } else {
            $data['completed_by'] = null;
            $data['completed_at'] = null;
        }

        $asset = $this->assetModel->findById(
            $assetId
        );

        if (!$asset) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetAccess($asset);

        $this->db->beginTransaction();

        try {
            /*
             * Starting maintenance:
             * only Available or Assigned assets may
             * transition into Under Maintenance.
             */
            if (
                $oldStatus !== 'in_progress'
                && $newStatus === 'in_progress'
            ) {
                $this->validateMaintenanceStartStatus(
                    $asset,
                    $newStatus
                );

                /*
                 * Prevent multiple active maintenance
                 * records for the same asset.
                 */
                $this->ensureNoActiveMaintenance(
                    $assetId,
                    $id
                );
            }

            $updated =
                $this->maintenanceModel->update(
                    $id,
                    $data
                );

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to update maintenance record.'
                );
            }

            /*
             * Maintenance started:
             * Available / Assigned → Under Maintenance.
             */
            if (
                $oldStatus !== 'in_progress'
                && $newStatus === 'in_progress'
            ) {
                $this->setAssetUnderMaintenance(
                    $assetId
                );
            }

            /*
             * Maintenance completed:
             * Under Maintenance → Assigned / Available.
             */
            if (
                $oldStatus === 'in_progress'
                && $newStatus === 'completed'
            ) {
                $this->restoreAssetOperationalStatus(
                    $assetId,
                    2,
                    1
                );
            }

            /*
             * Maintenance cancelled:
             * Under Maintenance → Assigned / Available.
             */
            if (
                $oldStatus === 'in_progress'
                && $newStatus === 'cancelled'
            ) {
                $this->restoreAssetOperationalStatus(
                    $assetId,
                    2,
                    1
                );
            }

            $this->db->commit();

            return true;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function authorizeAssetAccess(
        array $asset
    ): void {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (
            !$this->authorization->isAssetManager()
            && !$this->authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to manage maintenance records.'
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
                'You may only manage maintenance records for assets belonging to your department.'
            );
        }
    }

    private function validateMaintenanceStartStatus(
        array $asset,
        string $maintenanceStatus
    ): void {
        if ($maintenanceStatus !== 'in_progress') {
            return;
        }

        $statusId = (int) (
            $asset['status_id'] ?? 0
        );

        $allowedStartStatuses = [
            1, // Available
            2, // Assigned
        ];

        if (
            !in_array(
                $statusId,
                $allowedStartStatuses,
                true
            )
        ) {
            throw new RuntimeException(
                'Maintenance can only be started for an Available or Assigned asset.'
            );
        }
    }

    private function ensureNoActiveMaintenance(
        int $assetId,
        ?int $excludeMaintenanceId = null
    ): void {
        $sql = '
        SELECT id
        FROM maintenance_records
        WHERE asset_id = ?
          AND maintenance_status = ?
    ';

        $params = [$assetId, 'in_progress'];

        if ($excludeMaintenanceId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeMaintenanceId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            throw new RuntimeException(
                'This asset already has an active maintenance record.'
            );
        }
    }

    private function setAssetUnderMaintenance(
        int $assetId
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE assets
             SET status_id = 3
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $assetId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to update asset maintenance status.'
            );
        }
    }

    private function restoreAssetOperationalStatus(
        int $assetId,
        int $assignedStatusId,
        int $availableStatusId
    ): void {
        $stmt = $this->db->prepare('
            SELECT assigned_to_user_id, status_id
            FROM assets
            WHERE id = ?
            LIMIT 1
        ');

        $stmt->execute([$assetId]);

        $asset = $stmt->fetch();

        if (!$asset) {
            throw new RuntimeException(
                'Unable to restore asset status.'
            );
        }

        /*
         * Only restore an asset that is still marked
         * Under Maintenance.
         *
         * If another legitimate operation already changed
         * the asset status, leave that newer status untouched.
         */
        if ((int) $asset['status_id'] !== 3) {
            return;
        }

        $newStatusId = $asset['assigned_to_user_id'] !== null
            ? $assignedStatusId
            : $availableStatusId;

        $update = $this->db->prepare('
            UPDATE assets
            SET status_id = ?
            WHERE id = ?
            AND status_id = 3
        ');

        $update->execute([
            $newStatusId,
            $assetId,
        ]);

        if ($update->rowCount() === 0) {
            throw new RuntimeException(
                'Unable to restore asset status.'
            );
        }
    }

    private function validateAndNormalize(
        array $data
    ): array {
        $assetId = (int) ($data['asset_id'] ?? 0);

        if ($assetId <= 0) {
            throw new RuntimeException(
                'Asset is required.'
            );
        }

        $maintenanceType = trim(
            (string) ($data['maintenance_type'] ?? '')
        );

        if ($maintenanceType === '') {
            throw new RuntimeException(
                'Maintenance type is required.'
            );
        }

        $title = trim(
            (string) ($data['title'] ?? '')
        );

        if ($title === '') {
            throw new RuntimeException(
                'Maintenance title is required.'
            );
        }

        $status = strtolower(
            trim(
                (string) (
                    $data['maintenance_status']
                    ?? 'scheduled'
                )
            )
        );

        $allowedStatuses = [
            'scheduled',
            'in_progress',
            'completed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid maintenance status.'
            );
        }

        $cost = $this->normalizeMoney(
            $data['cost'] ?? 0
        );

        $scheduledDate = $this->normalizeDate(
            $data['scheduled_date'] ?? null,
            'Scheduled date'
        );

        $startedAt = $this->normalizeDateTime(
            $data['started_at'] ?? null,
            'Started date'
        );

        $completedAt = $this->normalizeDateTime(
            $data['completed_at'] ?? null,
            'Completed date'
        );

        $nextMaintenanceDate = $this->normalizeDate(
            $data['next_maintenance_date'] ?? null,
            'Next maintenance date'
        );

        if (
            $completedAt !== null
            && $startedAt !== null
            && strtotime($completedAt)
            < strtotime($startedAt)
        ) {
            throw new RuntimeException(
                'Completed date cannot be earlier than the started date.'
            );
        }

        if (
            $nextMaintenanceDate !== null
            && $scheduledDate !== null
            && $nextMaintenanceDate < $scheduledDate
        ) {
            throw new RuntimeException(
                'Next maintenance date cannot be earlier than the scheduled date.'
            );
        }

        $serviceProviderId =
            $this->normalizeOptionalId(
                $data['service_provider_id'] ?? null
            );

        if ($serviceProviderId !== null) {
            $this->validateVendor(
                $serviceProviderId
            );
        }

        return [
            'asset_id' => $assetId,
            'maintenance_type' => $maintenanceType,
            'title' => $title,
            'description' => $this->normalizeNullableString(
                $data['description'] ?? null
            ),
            'service_provider_id' => $serviceProviderId,
            'scheduled_date' => $scheduledDate,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'maintenance_status' => $status,
            'cost' => $cost,
            'reference_number' => $this->normalizeNullableString(
                $data['reference_number'] ?? null
            ),
            'findings' => $this->normalizeNullableString(
                $data['findings'] ?? null
            ),
            'actions_taken' => $this->normalizeNullableString(
                $data['actions_taken'] ?? null
            ),
            'recommendations' => $this->normalizeNullableString(
                $data['recommendations'] ?? null
            ),
            'next_maintenance_date' => $nextMaintenanceDate,
            'completed_by' => null,
        ];
    }

    private function validateVendor(
        int $vendorId
    ): void {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM vendors
             WHERE id = :id
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $vendorId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'Selected service provider is invalid.'
            );
        }
    }

    private function normalizeOptionalId(
        mixed $value
    ): ?int {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new RuntimeException(
                'Invalid reference ID.'
            );
        }

        return $id;
    }

    private function normalizeMoney(
        mixed $value
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return '0.00';
        }

        if (!is_numeric($value)) {
            throw new RuntimeException(
                'Invalid maintenance cost.'
            );
        }

        $amount = (float) $value;

        if ($amount < 0) {
            throw new RuntimeException(
                'Maintenance cost cannot be negative.'
            );
        }

        return number_format(
            $amount,
            2,
            '.',
            ''
        );
    }

    private function normalizeDate(
        mixed $value,
        string $label
    ): ?string {
        if (
            $value === null
            || trim((string) $value) === ''
        ) {
            return null;
        }

        $value = trim((string) $value);

        $date = \DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

        if (
            !$date
            || $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException(
                $label . ' is invalid.'
            );
        }

        return $value;
    }

    private function normalizeDateTime(
        mixed $value,
        string $label
    ): ?string {
        if (
            $value === null
            || trim((string) $value) === ''
        ) {
            return null;
        }

        $value = trim((string) $value);

        $dateTime = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $value
        );

        if (
            !$dateTime
            || $dateTime->format('Y-m-d H:i:s') !== $value
        ) {
            throw new RuntimeException(
                $label . ' is invalid.'
            );
        }

        return $value;
    }

    private function normalizeNullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}