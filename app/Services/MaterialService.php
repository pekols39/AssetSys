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
        bool $isReturnable = false
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

        return (int) $this->db->lastInsertId();
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
        bool $isReturnable = false
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

        if ($reorderLevel < $minimumStockLevel) {
            throw new RuntimeException(
                'Reorder level cannot be lower than the minimum stock level.'
            );
        }

        $existing = $this->materialModel->findById(
            $materialId
        );

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

        return $stmt->execute([
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
    }

    public function deactivate(int $materialId): bool
    {
        $this->authorizeManagement();

        $materialId = $this->validatePositiveId(
            $materialId,
            'Invalid material.'
        );

        if (!$this->materialModel->findById($materialId)) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE materials
             SET is_active = 0
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $materialId,
        ]);
    }

    public function activate(int $materialId): bool
    {
        $this->authorizeManagement();

        $materialId = $this->validatePositiveId(
            $materialId,
            'Invalid material.'
        );

        if (!$this->materialModel->findById($materialId)) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE materials
             SET is_active = 1
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $materialId,
        ]);
    }

    public function get(int $materialId): array|false
    {
        $this->authorizeRead();

        return $this->materialModel->findById(
            $materialId
        );
    }

    public function getAll(): array
    {
        $this->authorizeRead();

        return $this->materialModel->getAll();
    }
    /**
     * Get a paginated list of materials.
     */
    public function getPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        return $this->materialModel->getPage(
            $filters,
            $page,
            $perPage
        );
    }

    public function getActive(): array
    {
        $this->authorizeRead();

        return $this->materialModel->getActive();
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