<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use App\Models\RepairRecord;
use App\Services\AuthorizationService;
use PDO;
use RuntimeException;
use Throwable;

class RepairService
{
    private PDO $db;
    private Asset $assetModel;
    private RepairRecord $repairModel;
    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->repairModel = new RepairRecord();
        $this->authorization = new AuthorizationService();
    }

    public function create(
        int $assetId,
        int $createdBy,
        array $data
    ): int {
        $this->validateUser($createdBy);

        if ($assetId <= 0) {
            throw new RuntimeException('Invalid asset.');
        }

        $asset = $this->assetModel->findById($assetId);

        if (!$asset) {
            throw new RuntimeException('Asset not found.');
        }

        $this->authorizeAssetAccess($asset);

        $normalized = $this->normalizeData($data);

        $normalized['asset_id'] = $assetId;
        $normalized['created_by'] = $createdBy;
        $normalized['completed_by'] = null;

        if ($normalized['repair_status'] === 'reported') {
            $normalized['started_at'] = null;
            $normalized['completed_at'] = null;
        }

        if ($normalized['repair_status'] === 'diagnosed') {
            $normalized['started_at'] = null;
            $normalized['completed_at'] = null;
        }

        if ($normalized['repair_status'] === 'in_progress') {
            if (empty($normalized['started_at'])) {
                $normalized['started_at'] = date('Y-m-d H:i:s');
            }

            $normalized['completed_at'] = null;
        }

        if ($normalized['repair_status'] === 'completed') {
            if (empty($normalized['started_at'])) {
                $normalized['started_at'] = $normalized['reported_at'];
            }

            if (empty($normalized['completed_at'])) {
                $normalized['completed_at'] = date('Y-m-d H:i:s');
            }

            $normalized['completed_by'] = $createdBy;
        }

        if ($normalized['repair_status'] === 'cancelled') {
            $normalized['completed_at'] = null;
            $normalized['completed_by'] = null;
        }

        try {
            $this->db->beginTransaction();

            $repairId = $this->repairModel->create($normalized);

            if ($normalized['repair_status'] === 'in_progress') {
                $this->setAssetUnderRepair($assetId);
            }

            if (
                $normalized['repair_status'] === 'completed'
                || $normalized['repair_status'] === 'cancelled'
            ) {
                $this->restoreAssetOperationalStatus($assetId);
            }

            $this->db->commit();

            return $repairId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function update(
        int $id,
        int $updatedBy,
        array $data
    ): bool {
        $this->validateUser($updatedBy);

        if ($id <= 0) {
            throw new RuntimeException('Invalid repair record.');
        }

        $existing = $this->repairModel->findById($id);

        if (!$existing) {
            throw new RuntimeException('Repair record not found.');
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

        $normalized = $this->normalizeData($data);

        /*
         * Asset ownership of a repair record is immutable.
         */
        $normalized['asset_id'] = (int) $existing['asset_id'];
        $normalized['completed_by'] = $existing['completed_by'];

        $oldStatus = (string) ($existing['repair_status'] ?? '');
        $newStatus = $normalized['repair_status'];

        if ($newStatus === 'reported') {
            $normalized['started_at'] = null;
            $normalized['completed_at'] = null;
            $normalized['completed_by'] = null;
        }

        if ($newStatus === 'diagnosed') {
            $normalized['started_at'] = null;
            $normalized['completed_at'] = null;
            $normalized['completed_by'] = null;
        }

        if ($newStatus === 'in_progress') {
            if (empty($normalized['started_at'])) {
                $normalized['started_at'] = !empty($existing['started_at'])
                    ? $existing['started_at']
                    : date('Y-m-d H:i:s');
            }

            $normalized['completed_at'] = null;
            $normalized['completed_by'] = null;
        }

        if ($newStatus === 'completed') {
            if (empty($normalized['started_at'])) {
                $normalized['started_at'] = !empty($existing['started_at'])
                    ? $existing['started_at']
                    : $normalized['reported_at'];
            }

            if (empty($normalized['completed_at'])) {
                $normalized['completed_at'] = date('Y-m-d H:i:s');
            }

            $normalized['completed_by'] = $updatedBy;
        }

        if ($newStatus === 'cancelled') {
            $normalized['completed_at'] = null;
            $normalized['completed_by'] = null;
        }

        try {
            $this->db->beginTransaction();

            $updated = $this->repairModel->update($id, $normalized);

            if (!$updated) {
                throw new RuntimeException('Unable to update repair record.');
            }

            /*
             * Entering active repair.
             */
            if (
                $newStatus === 'in_progress'
                && $oldStatus !== 'in_progress'
            ) {
                $this->setAssetUnderRepair(
                    (int) $existing['asset_id']
                );
            }

            /*
             * Leaving active repair.
             */
            if (
                in_array($oldStatus, ['in_progress'], true)
                && in_array($newStatus, ['completed', 'cancelled'], true)
            ) {
                $this->restoreAssetOperationalStatus(
                    (int) $existing['asset_id']
                );
            }

            /*
             * If an existing completed/cancelled record is
             * edited back into active repair, put the asset
             * back under repair.
             */
            if (
                $oldStatus !== 'in_progress'
                && $newStatus === 'in_progress'
            ) {
                $this->setAssetUnderRepair(
                    (int) $existing['asset_id']
                );
            }

            $this->db->commit();

            return true;
        } catch (Throwable $e) {
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
                'You do not have permission to manage repair records.'
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
                'You may only manage repair records for assets belonging to your department.'
            );
        }
    }

    private function normalizeData(array $data): array
    {
        $status = trim((string) ($data['repair_status'] ?? 'reported'));

        $allowedStatuses = [
            'reported',
            'diagnosed',
            'in_progress',
            'completed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid repair status.');
        }

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Repair title is required.');
        }

        $repairType = trim((string) ($data['repair_type'] ?? ''));

        if ($repairType === '') {
            throw new RuntimeException('Repair type is required.');
        }

        $problemDescription = trim(
            (string) ($data['problem_description'] ?? '')
        );

        if ($problemDescription === '') {
            throw new RuntimeException(
                'Problem description is required.'
            );
        }

        $reportedAt = trim(
            (string) ($data['reported_at'] ?? '')
        );

        if ($reportedAt === '') {
            $reportedAt = date('Y-m-d H:i:s');
        }

        $cost = $data['cost'] ?? null;

        if ($cost === '' || $cost === null) {
            $cost = 0;
        }

        if (!is_numeric($cost) || (float) $cost < 0) {
            throw new RuntimeException('Invalid repair cost.');
        }

        $serviceProviderId = $data['service_provider_id'] ?? null;

        if ($serviceProviderId === '' || $serviceProviderId === null) {
            $serviceProviderId = null;
        } else {
            $serviceProviderId = (int) $serviceProviderId;

            if ($serviceProviderId <= 0) {
                $serviceProviderId = null;
            }
        }

        $warrantyClaim = !empty($data['warranty_claim']) ? 1 : 0;

        return [
            'repair_type' => $repairType,
            'title' => $title,
            'problem_description' => $problemDescription,
            'service_provider_id' => $serviceProviderId,
            'reported_at' => $reportedAt,
            'started_at' => $this->nullableDateTime(
                $data['started_at'] ?? null
            ),
            'completed_at' => $this->nullableDateTime(
                $data['completed_at'] ?? null
            ),
            'repair_status' => $status,
            'cost' => number_format((float) $cost, 2, '.', ''),
            'reference_number' => $this->nullableString(
                $data['reference_number'] ?? null
            ),
            'diagnosis' => $this->nullableString(
                $data['diagnosis'] ?? null
            ),
            'repair_actions' => $this->nullableString(
                $data['repair_actions'] ?? null
            ),
            'parts_replaced' => $this->nullableString(
                $data['parts_replaced'] ?? null
            ),
            'recommendations' => $this->nullableString(
                $data['recommendations'] ?? null
            ),
            'warranty_claim' => $warrantyClaim,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $stmt = $this->db->prepare(
            "SELECT id
             FROM users
             WHERE id = :id
               AND account_status = 'active'
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('User not found or inactive.');
        }
    }

    private function setAssetUnderRepair(int $assetId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE assets
             SET status_id = 4,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :asset_id"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);
    }

    private function restoreAssetOperationalStatus(int $assetId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE assets
             SET
                status_id = CASE
                    WHEN assigned_to_user_id IS NOT NULL THEN 2
                    ELSE 1
                END,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :asset_id"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);
    }
}