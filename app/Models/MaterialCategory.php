<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class MaterialCategory
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                code,
                description,
                is_active,
                created_at,
                updated_at
            FROM material_categories
            WHERE id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByName(string $name): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                code,
                description,
                is_active,
                created_at,
                updated_at
            FROM material_categories
            WHERE name = :name
            LIMIT 1'
        );

        $stmt->execute([
            'name' => $name,
        ]);

        return $stmt->fetch();
    }

    public function findByCode(string $code): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                code,
                description,
                is_active,
                created_at,
                updated_at
            FROM material_categories
            WHERE code = :code
            LIMIT 1'
        );

        $stmt->execute([
            'code' => $code,
        ]);

        return $stmt->fetch();
    }

    public function getActive(): array
    {
        $stmt = $this->db->query(
            'SELECT
                id,
                name,
                code,
                description,
                is_active,
                created_at,
                updated_at
            FROM material_categories
            WHERE is_active = 1
            ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }
    public function getPaginated(
        string $search = '',
        string $status = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $page = max(1, $page);

        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 10;

        $where = [];
        $params = [];

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = $status === 'active' ? 1 : 0;
        }

        $search = trim($search);

        if ($search !== '') {
            $where[] = '(
            name LIKE :search_name
            OR code LIKE :search_code
            OR description LIKE :search_description
        )';

            $searchTerm = '%' . $search . '%';

            $params['search_name'] = $searchTerm;
            $params['search_code'] = $searchTerm;
            $params['search_description'] = $searchTerm;
        }

        $whereSql = $where !== []
            ? ' WHERE ' . implode(' AND ', $where)
            : '';

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
         FROM material_categories'
            . $whereSql
        );

        $countStmt->execute($params);

        $totalRecords = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($totalRecords / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT
            id,
            name,
            code,
            description,
            is_active,
            created_at,
            updated_at
         FROM material_categories'
            . $whereSql
            . ' ORDER BY name ASC
            LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                $name === 'is_active'
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $totalRecords,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT
                id,
                name,
                code,
                description,
                is_active,
                created_at,
                updated_at
            FROM material_categories
            ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }
}