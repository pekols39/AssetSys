<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class Material
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
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,

                c.name AS category_name,
                c.code AS category_code,

                u.first_name AS created_by_first_name,
                u.last_name AS created_by_last_name,
                u.username AS created_by_username

            FROM materials m

            INNER JOIN material_categories c
                ON c.id = m.category_id

            LEFT JOIN users u
                ON u.id = m.created_by

            WHERE m.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByCode(string $code): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,

                c.name AS category_name,
                c.code AS category_code

            FROM materials m

            INNER JOIN material_categories c
                ON c.id = m.category_id

            WHERE m.code = :code
            LIMIT 1'
        );

        $stmt->execute([
            'code' => $code,
        ]);

        return $stmt->fetch();
    }

    public function findByName(string $name): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,

                c.name AS category_name,
                c.code AS category_code

            FROM materials m

            INNER JOIN material_categories c
                ON c.id = m.category_id

            WHERE m.name = :name
            LIMIT 1'
        );

        $stmt->execute([
            'name' => $name,
        ]);

        return $stmt->fetch();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,

                c.name AS category_name,
                c.code AS category_code

            FROM materials m

            INNER JOIN material_categories c
                ON c.id = m.category_id

            ORDER BY m.name ASC'
        );

        return $stmt->fetchAll();
    }


    /**
     * Get searchable, filterable, and paginated materials.
     */
    public function getPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $allowedPageSizes = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPageSizes, true)) {
            $perPage = 10;
        }

        $page = max(1, $page);
        $where = [];
        $params = [];

        // Search by material code, name, description, or category.
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $where[] = '(
                m.code LIKE :search_code
                OR m.name LIKE :search_name
                OR m.description LIKE :search_description
                OR c.name LIKE :search_category
                OR c.code LIKE :search_category_code
            )';

            $term = '%' . $search . '%';

            $params['search_code'] = $term;
            $params['search_name'] = $term;
            $params['search_description'] = $term;
            $params['search_category'] = $term;
            $params['search_category_code'] = $term;
        }

        // Filter by category.
        $categoryId = $filters['category_id'] ?? '';

        if (
            $categoryId !== ''
            && filter_var($categoryId, FILTER_VALIDATE_INT) !== false
            && (int) $categoryId > 0
        ) {
            $where[] = 'm.category_id = :category_id';
            $params['category_id'] = (int) $categoryId;
        }

        // Filter by active/inactive status.
        $status = (string) ($filters['status'] ?? '');

        if ($status === 'active') {
            $where[] = 'm.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'm.is_active = 0';
        }

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $fromSql = '
            FROM materials m
            INNER JOIN material_categories c
                ON c.id = m.category_id
        ';

        // Count the filtered results before applying pagination.
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) ' . $fromSql . $whereSql
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($total / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // Only allow known database columns for sorting.
        $sortColumns = [
            'code' => 'm.code',
            'name' => 'm.name',
            'category' => 'c.name',
            'unit' => 'm.unit',
            'minimum_stock' => 'm.minimum_stock_level',
            'reorder_level' => 'm.reorder_level',
            'status' => 'm.is_active',
            'created_at' => 'm.created_at',
        ];

        $sortKey = (string) ($filters['sort'] ?? 'name');

        $sortColumn = $sortColumns[$sortKey] ?? 'm.name';

        $direction = strtolower(
            (string) ($filters['direction'] ?? 'asc')
        ) === 'desc' ? 'DESC' : 'ASC';

        $sql = '
            SELECT
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,
                c.name AS category_name,
                c.code AS category_code
            ' . $fromSql . $whereSql . '
            ORDER BY ' . $sortColumn . ' ' . $direction . ',
                     m.id DESC
            LIMIT :limit OFFSET :offset
        ';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    public function getActive(): array
    {
        $stmt = $this->db->query(
            'SELECT
                m.id,
                m.category_id,
                m.code,
                m.name,
                m.description,
                m.unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                m.is_active,
                m.created_by,
                m.created_at,
                m.updated_at,

                c.name AS category_name,
                c.code AS category_code

            FROM materials m

            INNER JOIN material_categories c
                ON c.id = m.category_id

            WHERE m.is_active = 1
            ORDER BY m.name ASC'
        );

        return $stmt->fetchAll();
    }
}