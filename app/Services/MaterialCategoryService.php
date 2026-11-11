<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\MaterialCategory;
use PDO;
use RuntimeException;

class MaterialCategoryService
{
    private PDO $db;

    private MaterialCategory $categoryModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->categoryModel = new MaterialCategory();

        $this->authorization = new AuthorizationService();
    }

    public function create(
        string $name,
        ?string $code = null,
        ?string $description = null
    ): int {
        $this->authorizeManagement();

        $name = $this->normalizeRequiredString(
            $name,
            'Category name is required.'
        );

        $code = $this->normalizeOptionalString($code);

        $description = $this->normalizeOptionalString(
            $description
        );

        if ($this->categoryModel->findByName($name)) {
            throw new RuntimeException(
                'A material category with this name already exists.'
            );
        }

        if (
            $code !== null
            && $this->categoryModel->findByCode($code)
        ) {
            throw new RuntimeException(
                'A material category with this code already exists.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO material_categories (
                name,
                code,
                description,
                is_active
            )
            VALUES (
                :name,
                :code,
                :description,
                1
            )'
        );

        $stmt->execute([
            'name' => $name,
            'code' => $code,
            'description' => $description,
        ]);

        $categoryId = (int) $this->db->lastInsertId();

        (new AuditLogService())->log(
            action: 'create_material_category',
            module: 'materials',
            entityType: 'material_category',
            entityId: $categoryId,
            description: 'Material category created.',
            newValues: [
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'is_active' => 1,
            ]
        );

        return $categoryId;
    }

    public function update(
        int $categoryId,
        string $name,
        ?string $code = null,
        ?string $description = null
    ): bool {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Invalid material category.'
            );
        }

        $existing = $this->categoryModel->findById(
            $categoryId
        );

        if (!is_array($existing)) {
            throw new RuntimeException(
                'Material category not found.'
            );
        }

        $name = $this->normalizeRequiredString(
            $name,
            'Category name is required.'
        );

        $code = $this->normalizeOptionalString($code);

        $description = $this->normalizeOptionalString(
            $description
        );

        $sameName = $this->categoryModel->findByName(
            $name
        );

        if (
            is_array($sameName)
            && (int) $sameName['id'] !== $categoryId
        ) {
            throw new RuntimeException(
                'A material category with this name already exists.'
            );
        }

        if ($code !== null) {
            $sameCode = $this->categoryModel->findByCode(
                $code
            );

            if (
                is_array($sameCode)
                && (int) $sameCode['id'] !== $categoryId
            ) {
                throw new RuntimeException(
                    'A material category with this code already exists.'
                );
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE material_categories
             SET
                name = :name,
                code = :code,
                description = :description
             WHERE id = :id'
        );

        $success = $stmt->execute([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'id' => $categoryId,
        ]);

        if ($success) {
            (new AuditLogService())->log(
                action: 'update_material_category',
                module: 'materials',
                entityType: 'material_category',
                entityId: $categoryId,
                description: 'Material category updated.',
                oldValues: [
                    'name' => $existing['name'] ?? null,
                    'code' => $existing['code'] ?? null,
                    'description' => $existing['description'] ?? null,
                ],
                newValues: [
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                ]
            );
        }

        return $success;
    }

    public function deactivate(int $categoryId): bool
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Invalid material category.'
            );
        }

        $existing = $this->categoryModel->findById($categoryId);

        if (!$existing) {
            throw new RuntimeException(
                'Material category not found.'
            );
        }

        /*
         * Do not physically delete categories.
         * Existing materials may depend on them.
         */
        $stmt = $this->db->prepare(
            'UPDATE material_categories
     SET is_active = 0
     WHERE id = :id'
        );

        $success = $stmt->execute([
            'id' => $categoryId,
        ]);

        if ($success) {
            (new AuditLogService())->log(
                action: 'deactivate_material_category',
                module: 'materials',
                entityType: 'material_category',
                entityId: $categoryId,
                description: 'Material category deactivated.',
                oldValues: [
                    'name' => $existing['name'] ?? null,
                    'is_active' => $existing['is_active'] ?? null,
                ],
                newValues: [
                    'name' => $existing['name'] ?? null,
                    'is_active' => 0,
                ]
            );
        }

        return $success;
    }

    public function activate(int $categoryId): bool
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Invalid material category.'
            );
        }

        $existing = $this->categoryModel->findById($categoryId);

        if (!$existing) {
            throw new RuntimeException(
                'Material category not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE material_categories
     SET is_active = 1
     WHERE id = :id'
        );

        $success = $stmt->execute([
            'id' => $categoryId,
        ]);

        if ($success) {
            (new AuditLogService())->log(
                action: 'activate_material_category',
                module: 'materials',
                entityType: 'material_category',
                entityId: $categoryId,
                description: 'Material category activated.',
                oldValues: [
                    'name' => $existing['name'] ?? null,
                    'is_active' => $existing['is_active'] ?? null,
                ],
                newValues: [
                    'name' => $existing['name'] ?? null,
                    'is_active' => 1,
                ]
            );
        }

        return $success;
    }

    public function get(int $categoryId): array|false
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            return false;
        }

        $category = $this->categoryModel->findById(
            $categoryId
        );

        if (is_array($category)) {
            (new AuditLogService())->log(
                action: 'view_material_category_details',
                module: 'materials',
                entityType: 'material_category',
                entityId: $categoryId,
                description: 'Material category details viewed.'
            );
        }

        return $category;
    }
    public function getPaginated(
        string $search = '',
        string $status = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $this->authorizeManagement();

        $result = $this->categoryModel->getPaginated(
            trim($search),
            $status,
            max(1, $page),
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_material_category_list',
            module: 'materials',
            entityType: 'material_category',
            description: 'Paginated material category list viewed.',
            newValues: [
                'search' => trim($search),
                'status' => $status,
                'page' => max(1, $page),
                'per_page' => $perPage,
                'result_count' => count($result['data'] ?? []),
                'total' => $result['total'] ?? null,
            ]
        );

        return $result;
    }
    public function getAll(): array
    {
        $this->authorizeManagement();

        $categories = $this->categoryModel->getAll();

        (new AuditLogService())->log(
            action: 'view_all_material_categories',
            module: 'materials',
            entityType: 'material_category',
            description: 'All material categories viewed.',
            newValues: [
                'result_count' => count($categories),
            ]
        );

        return $categories;
    }

    public function getActive(): array
    {
        $this->authorizeManagement();

        $categories = $this->categoryModel->getActive();

        (new AuditLogService())->log(
            action: 'view_active_material_categories',
            module: 'materials',
            entityType: 'material_category',
            description: 'Active material categories viewed.',
            newValues: [
                'result_count' => count($categories),
            ]
        );

        return $categories;
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
                'You do not have permission to manage material categories.'
            );
        }

        if ($this->authorization->departmentId() === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }
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