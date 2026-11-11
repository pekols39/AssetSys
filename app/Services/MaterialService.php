<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Material;
use PDO;
use RuntimeException;
use Throwable;

class MaterialService
{
    private PDO $db;

    private Material $materialModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->materialModel = new Material();

        $this->authorization = new AuthorizationService();
    }

    public function create(
        int $categoryId,
        string $code,
        string $name,
        string $unit,
        ?string $description = null,
        float $minimumStockLevel = 0.0,
        float $reorderLevel = 0.0,
        bool $isReturnable = false,
        float $initialStockQuantity = 0.0
    ): int {
        $this->authorizeManagement();

        $categoryId = $this->validatePositiveId(
            $categoryId,
            'Invalid material category.'
        );

        $code = $this->normalizeRequiredString(
            $code,
            'Material code is required.'
        );

        $name = $this->normalizeRequiredString(
            $name,
            'Material name is required.'
        );

        $unit = $this->normalizeRequiredString(
            $unit,
            'Unit of measure is required.'
        );

        $description = $this->normalizeOptionalString(
            $description
        );

        $minimumStockLevel = $this->validateNonNegativeQuantity(
            $minimumStockLevel,
            'Minimum stock level cannot be negative.'
        );

        $reorderLevel = $this->validateNonNegativeQuantity(
            $reorderLevel,
            'Reorder level cannot be negative.'
        );

        $initialStockQuantity = $this->validateNonNegativeQuantity(
            $initialStockQuantity,
            'Initial stock quantity cannot be negative.'
        );

        if ($reorderLevel < $minimumStockLevel) {
            throw new RuntimeException(
                'Reorder level cannot be lower than the minimum stock level.'
            );
        }

        $this->validateCategory($categoryId);

        if ($this->materialModel->findByCode($code)) {
            throw new RuntimeException(
                'A material with this code already exists.'
            );
        }

        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO materials (
                category_id,
                code,
                name,
                description,
                unit,
                minimum_stock_level,
                reorder_level,
                is_returnable,
                is_active,
                created_by
            )
            VALUES (
                :category_id,
                :code,
                :name,
                :description,
                :unit,
                :minimum_stock_level,
                :reorder_level,
                :is_returnable,
                1,
                :created_by
            )'
            );

            $stmt->execute([
                'category_id' => $categoryId,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'unit' => $unit,
                'minimum_stock_level' => $minimumStockLevel,
                'reorder_level' => $reorderLevel,
                'is_returnable' => $isReturnable ? 1 : 0,
                'created_by' => $userId,
            ]);

            $materialId = (int) $this->db->lastInsertId();

            $stockStmt = $this->db->prepare(
                'INSERT INTO material_stocks (
                material_id,
                department_id,
                quantity
            )
            VALUES (
                :material_id,
                NULL,
                :quantity
            )'
            );

            $stockStmt->execute([
                'material_id' => $materialId,
                'quantity' => $initialStockQuantity,
            ]);

            (new AuditLogService())->log(
                action: 'create_material',
                module: 'materials',
                entityType: 'material',
                entityId: $materialId,
                description: 'Material created successfully.',
                newValues: [
                    'category_id' => $categoryId,
                    'code' => $code,
                    'name' => $name,
                    'unit' => $unit,
                    'description' => $description,
                    'minimum_stock_level' => $minimumStockLevel,
                    'reorder_level' => $reorderLevel,
                    'is_returnable' => $isReturnable,
                    'is_active' => true,
                    'initial_stock_quantity' => $initialStockQuantity,
                    'created_by' => $userId,
                ]
            );

            $this->db->commit();

            return $materialId;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function update(
        int $materialId,
        int $categoryId,
        string $code,
        string $name,
        string $unit,
        ?string $description = null,
        float $minimumStockLevel = 0.0,
        float $reorderLevel = 0.0,
        bool $isReturnable = false,
        float $stockQuantity = 0.0
    ): bool {
        $this->authorizeManagement();

        $materialId = $this->validatePositiveId(
            $materialId,
            'Invalid material.'
        );

        $categoryId = $this->validatePositiveId(
            $categoryId,
            'Invalid material category.'
        );

        $code = $this->normalizeRequiredString(
            $code,
            'Material code is required.'
        );

        $name = $this->normalizeRequiredString(
            $name,
            'Material name is required.'
        );

        $unit = $this->normalizeRequiredString(
            $unit,
            'Unit of measure is required.'
        );

        $description = $this->normalizeOptionalString(
            $description
        );

        $minimumStockLevel = $this->validateNonNegativeQuantity(
            $minimumStockLevel,
            'Minimum stock level cannot be negative.'
        );

        $reorderLevel = $this->validateNonNegativeQuantity(
            $reorderLevel,
            'Reorder level cannot be negative.'
        );

        $stockQuantity = $this->validateNonNegativeQuantity(
            $stockQuantity,
            'Current stock cannot be negative.'
        );

        if ($reorderLevel < $minimumStockLevel) {
            throw new RuntimeException(
                'Reorder level cannot be lower than the minimum stock level.'
            );
        }

        $existing = $this->materialModel->findById(
            $materialId
        );

        $stockBeforeStmt = $this->db->prepare(
            'SELECT quantity
                FROM material_stocks
                WHERE material_id = :material_id
                AND department_id IS NULL
                LIMIT 1'
        );

        $stockBeforeStmt->execute([
            'material_id' => $materialId,
        ]);

        $previousStockQuantity = $stockBeforeStmt->fetchColumn();

        $previousStockQuantity = $previousStockQuantity === false
            ? 0.0
            : (float) $previousStockQuantity;

        if (!is_array($existing)) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        $this->validateCategory($categoryId);

        $sameCode = $this->materialModel->findByCode(
            $code
        );

        if (
            is_array($sameCode)
            && (int) $sameCode['id'] !== $materialId
        ) {
            throw new RuntimeException(
                'A material with this code already exists.'
            );
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE materials
                 SET
                    category_id = :category_id,
                    code = :code,
                    name = :name,
                    description = :description,
                    unit = :unit,
                    minimum_stock_level = :minimum_stock_level,
                    reorder_level = :reorder_level,
                    is_returnable = :is_returnable
                 WHERE id = :id'
            );

            $updated = $stmt->execute([
                'category_id' => $categoryId,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'unit' => $unit,
                'minimum_stock_level' => $minimumStockLevel,
                'reorder_level' => $reorderLevel,
                'is_returnable' => $isReturnable ? 1 : 0,
                'id' => $materialId,
            ]);

            if (!$updated) {
                throw new RuntimeException(
                    'Unable to update material.'
                );
            }

            $stockStmt = $this->db->prepare(
                'INSERT INTO material_stocks (
                    material_id,
                    department_id,
                    quantity
                )
                VALUES (
                    :material_id,
                    NULL,
                    :quantity
                )
                ON DUPLICATE KEY UPDATE
                    quantity = VALUES(quantity)'
            );

            $stockStmt->execute([
                'material_id' => $materialId,
                'quantity' => $stockQuantity,
            ]);

            (new AuditLogService())->log(
                action: 'update_material',
                module: 'materials',
                entityType: 'material',
                entityId: $materialId,
                description: 'Material details updated successfully.',
                oldValues: [
                    'category_id' => $existing['category_id'] ?? null,
                    'code' => $existing['code'] ?? null,
                    'name' => $existing['name'] ?? null,
                    'unit' => $existing['unit'] ?? null,
                    'description' => $existing['description'] ?? null,
                    'minimum_stock_level' => $existing['minimum_stock_level'] ?? null,
                    'reorder_level' => $existing['reorder_level'] ?? null,
                    'is_returnable' => $existing['is_returnable'] ?? null,
                    'stock_quantity' => $previousStockQuantity,
                ],
                newValues: [
                    'category_id' => $categoryId,
                    'code' => $code,
                    'name' => $name,
                    'unit' => $unit,
                    'description' => $description,
                    'minimum_stock_level' => $minimumStockLevel,
                    'reorder_level' => $reorderLevel,
                    'is_returnable' => $isReturnable,
                    'stock_quantity' => $stockQuantity,
                ]
            );

            $this->db->commit();

            return true;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function deactivate(int $materialId): bool
    {
        $this->authorizeManagement();

        $materialId = $this->validatePositiveId(
            $materialId,
            'Invalid material.'
        );

        $existing = $this->materialModel->findById($materialId);

        if (!$existing) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE materials
         SET is_active = 0
         WHERE id = :id'
        );

        $updated = $stmt->execute([
            'id' => $materialId,
        ]);

        if ($updated) {
            (new AuditLogService())->log(
                action: 'deactivate_material',
                module: 'materials',
                entityType: 'material',
                entityId: $materialId,
                description: 'Material deactivated successfully.',
                oldValues: [
                    'is_active' => $existing['is_active'] ?? null,
                ],
                newValues: [
                    'is_active' => false,
                ]
            );
        }

        return $updated;
    }

    public function activate(int $materialId): bool
    {
        $this->authorizeManagement();

        $materialId = $this->validatePositiveId(
            $materialId,
            'Invalid material.'
        );

        $existing = $this->materialModel->findById($materialId);

        if (!$existing) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE materials
         SET is_active = 1
         WHERE id = :id'
        );

        $updated = $stmt->execute([
            'id' => $materialId,
        ]);

        if ($updated) {
            (new AuditLogService())->log(
                action: 'activate_material',
                module: 'materials',
                entityType: 'material',
                entityId: $materialId,
                description: 'Material activated successfully.',
                oldValues: [
                    'is_active' => $existing['is_active'] ?? null,
                ],
                newValues: [
                    'is_active' => true,
                ]
            );
        }

        return $updated;
    }

    public function get(int $materialId): array|false
    {
        $this->authorizeRead();

        $material = $this->materialModel->findById(
            $materialId
        );

        if ($material !== false) {
            (new AuditLogService())->log(
                action: 'view_material_details',
                module: 'materials',
                entityType: 'material',
                entityId: $materialId,
                description: 'Material details viewed.'
            );
        }

        return $material;
    }

    public function getAll(): array
    {
        $this->authorizeRead();

        $materials = $this->materialModel->getAll();

        (new AuditLogService())->log(
            action: 'view_material_list',
            module: 'materials',
            entityType: 'material',
            description: 'Complete material list viewed.',
            newValues: [
                'materials_count' => count($materials),
            ]
        );

        return $materials;
    }
    /**
     * Get a paginated list of materials.
     */
    public function getPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $this->authorizeRead();

        $result = $this->materialModel->getPage(
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_material_list',
            module: 'materials',
            entityType: 'material',
            description: 'Paginated material list viewed.',
            newValues: [
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    public function getActive(): array
    {
        $this->authorizeRead();

        $materials = $this->materialModel->getActive();

        (new AuditLogService())->log(
            action: 'view_active_materials',
            module: 'materials',
            entityType: 'material',
            description: 'Active materials list viewed.',
            newValues: [
                'materials_count' => count($materials),
            ]
        );

        return $materials;
    }

    private function validateCategory(int $categoryId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM material_categories
             WHERE id = :id
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $categoryId,
        ]);

        if (!$stmt->fetch()) {
            throw new RuntimeException(
                'Material category not found or inactive.'
            );
        }
    }

    private function authorizeManagement(): void
    {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to manage materials.'
            );
        }

        if ($this->authorization->departmentId() === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }
    }

    private function authorizeRead(): void
    {
        $this->authorizeManagement();
    }

    private function validatePositiveId(
        int $id,
        string $message
    ): int {
        if ($id <= 0) {
            throw new RuntimeException($message);
        }

        return $id;
    }

    private function validateNonNegativeQuantity(
        float $quantity,
        string $message
    ): float {
        if (
            !is_finite($quantity)
            || $quantity < 0
        ) {
            throw new RuntimeException($message);
        }

        return round($quantity, 3);
    }

    private function normalizeRequiredString(
        string $value,
        string $message
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function normalizeOptionalString(
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