<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\MaterialReplenishment;
use PDO;
use RuntimeException;

class MaterialReplenishmentService
{
    private PDO $db;

    private MaterialReplenishment $replenishmentModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->replenishmentModel = new MaterialReplenishment();

        $this->authorization = new AuthorizationService();
    }

    /**
     * Record a history-only material replenishment.
     *
     * Does not modify material_stocks or stock_movements.
     */
    public function create(
        int $materialId,
        float $quantity,
        string $replenishedAt,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): int {
        $departmentId = $this->authorizeReplenishment();

        if ($materialId <= 0) {
            throw new RuntimeException('Invalid material.');
        }

        if (!is_finite($quantity) || $quantity <= 0) {
            throw new RuntimeException(
                'Replenishment quantity must be greater than zero.'
            );
        }

        if (round($quantity, 3) != $quantity) {
            throw new RuntimeException(
                'Replenishment quantity cannot have more than three decimal places.'
            );
        }

        $quantity = round($quantity, 3);

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $replenishedAt
        );

        if (
            $date === false
            || $date->format('Y-m-d H:i:s') !== $replenishedAt
        ) {
            throw new RuntimeException(
                'Invalid replenishment date and time.'
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id
     FROM materials
     WHERE id = :material_id
       AND is_active = 1
     LIMIT 1'
        );

        $stmt->execute([
            'material_id' => $materialId,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                'Active material not found.'
            );
        }

        $referenceNumber = $this->normalizeOptionalString(
            $referenceNumber
        );

        $notes = $this->normalizeOptionalString($notes);

        $recordedBy = Auth::id();

        if ($recordedBy === null || $recordedBy <= 0) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $replenishmentId = $this->replenishmentModel->create(
            $materialId,
            $departmentId,
            $quantity,
            $replenishedAt,
            $referenceNumber,
            $notes,
            $recordedBy
        );

        (new AuditLogService())->log(
            action: 'create_material_replenishment',
            module: 'materials',
            entityType: 'material_replenishment',
            entityId: $replenishmentId,
            description: 'Material replenishment history recorded.',
            newValues: [
                'material_id' => $materialId,
                'department_id' => $departmentId,
                'quantity' => $quantity,
                'replenished_at' => $replenishedAt,
                'reference_number' => $referenceNumber,
                'recorded_by' => $recordedBy,
            ]
        );

        return $replenishmentId;
    }
    /**
     * Get active material options for the authenticated department.
     */
    public function getMaterialOptions(): array
    {
        $this->authorizeReplenishment();

        $materials = $this->replenishmentModel->getMaterialOptions();

        (new AuditLogService())->log(
            action: 'view_replenishment_material_options',
            module: 'materials',
            entityType: 'material_replenishment',
            description: 'Material options for replenishment viewed.',
            newValues: [
                'department_id' => $this->authorization->departmentId(),
                'result_count' => count($materials),
            ]
        );

        return $materials;
    }
    /**
     * Retrieve replenishment history for the authenticated department.
     */
    public function getHistory(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $departmentId = $this->authorizeReplenishment();

        $result = $this->replenishmentModel->getPage(
            $departmentId,
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_material_replenishment_history',
            module: 'materials',
            entityType: 'material_replenishment',
            description: 'Material replenishment history viewed.',
            newValues: [
                'department_id' => $departmentId,
                'filters' => $filters,
                'page' => max(1, $page),
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    private function authorizeReplenishment(): int
    {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException('Authentication required.');
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to manage replenishment history.'
            );
        }

        $departmentId = $this->authorization->departmentId();

        if ($departmentId === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }

        return $departmentId;
    }

    private function normalizeOptionalString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}