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

        return (int) $this->db->lastInsertId();
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

        return $stmt->execute([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'id' => $categoryId,
        ]);
    }

    public function deactivate(int $categoryId): bool
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Invalid material category.'
            );
        }

        if (!$this->categoryModel->findById($categoryId)) {
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

        return $stmt->execute([
            'id' => $categoryId,
        ]);
    }

    public function activate(int $categoryId): bool
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Invalid material category.'
            );
        }

        if (!$this->categoryModel->findById($categoryId)) {
            throw new RuntimeException(
                'Material category not found.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE material_categories
             SET is_active = 1
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $categoryId,
        ]);
    }

    public function get(int $categoryId): array|false
    {
        $this->authorizeManagement();

        if ($categoryId <= 0) {
            return false;
        }

        return $this->categoryModel->findById(
            $categoryId
        );
    }

    public function getAll(): array
    {
        $this->authorizeManagement();

        return $this->categoryModel->getAll();
    }

    public function getActive(): array
    {
        $this->authorizeManagement();

        return $this->categoryModel->getActive();
    }

    private function authorizeManagement(): void
    {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (!$this->authorization->canAccessAssetMaterialAreas()) {
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