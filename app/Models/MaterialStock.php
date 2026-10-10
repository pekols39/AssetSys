<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class MaterialStock
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
                ms.id,
                ms.material_id,
                ms.department_id,
                ms.quantity,
                ms.created_at,
                ms.updated_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                d.name AS department_name
            FROM material_stocks ms
            INNER JOIN materials m
                ON m.id = ms.material_id
            INNER JOIN departments d
                ON d.id = ms.department_id
            WHERE ms.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByMaterialAndDepartment(
        int $materialId,
        int $departmentId
    ): array|false {
        $stmt = $this->db->prepare(
            'SELECT
                ms.id,
                ms.material_id,
                ms.department_id,
                ms.quantity,
                ms.created_at,
                ms.updated_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                d.name AS department_name
            FROM material_stocks ms
            INNER JOIN materials m
                ON m.id = ms.material_id
            INNER JOIN departments d
                ON d.id = ms.department_id
            WHERE ms.material_id = :material_id
              AND ms.department_id = :department_id
            LIMIT 1'
        );

        $stmt->execute([
            'material_id' => $materialId,
            'department_id' => $departmentId,
        ]);

        return $stmt->fetch();
    }

    public function getByDepartment(int $departmentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                ms.id,
                ms.material_id,
                ms.department_id,
                ms.quantity,
                ms.created_at,
                ms.updated_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                d.name AS department_name
            FROM material_stocks ms
            INNER JOIN materials m
                ON m.id = ms.material_id
            INNER JOIN departments d
                ON d.id = ms.department_id
            WHERE ms.department_id = :department_id
              AND m.is_active = 1
            ORDER BY m.name ASC'
        );

        $stmt->execute([
            'department_id' => $departmentId,
        ]);

        return $stmt->fetchAll();
    }


    /**
     * Get filtered and paginated stock for one department.
     *
     * Supported filters:
     * - search: material code or name
     * - category_id: material category
     * - status: all, reorder, low, or in_stock
     */
    public function getPage(
        int $departmentId,
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $page = max(1, $page);

        $allowedPageSizes = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPageSizes, true)) {
            $perPage = 10;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $status = (string) ($filters['status'] ?? 'all');

        $sortColumns = [
            'material_code' => 'm.code',
            'material_name' => 'm.name',
            'quantity' => 'ms.quantity',
            'minimum_stock_level' => 'm.minimum_stock_level',
            'reorder_level' => 'm.reorder_level',
        ];

        $sort = (string) ($filters['sort'] ?? 'material_name');
        $sortColumn = $sortColumns[$sort] ?? 'm.name';

        $direction = strtolower(
            (string) ($filters['direction'] ?? 'asc')
        );

        $direction = $direction === 'desc' ? 'DESC' : 'ASC';

        $where = [
            'ms.department_id = :department_id',
            'm.is_active = 1',
        ];

        $params = [
            'department_id' => $departmentId,
        ];

        if ($search !== '') {
            $where[] = '(
                m.code LIKE :search_code
                OR m.name LIKE :search_name
            )';

            $params['search_code'] = '%' . $search . '%';
            $params['search_name'] = '%' . $search . '%';
        }

        if ($categoryId > 0) {
            $where[] = 'm.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        if ($status === 'reorder') {
            $where[] = 'ms.quantity <= m.reorder_level';
        } elseif ($status === 'low') {
            $where[] = '(
                ms.quantity > m.reorder_level
                AND ms.quantity <= m.minimum_stock_level
            )';
        } elseif ($status === 'in_stock') {
            $where[] = 'ms.quantity > m.minimum_stock_level';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM material_stocks ms
             INNER JOIN materials m
                ON m.id = ms.material_id
             WHERE {$whereSql}"
        );

        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($total / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                ms.id,
                ms.material_id,
                ms.department_id,
                ms.quantity,
                ms.created_at,
                ms.updated_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                m.category_id,
                c.name AS category_name,
                m.minimum_stock_level,
                m.reorder_level,
                m.is_returnable,
                d.name AS department_name
            FROM material_stocks ms
            INNER JOIN materials m
                ON m.id = ms.material_id
            INNER JOIN material_categories c
                ON c.id = m.category_id
            INNER JOIN departments d
                ON d.id = ms.department_id
            WHERE {$whereSql}
            ORDER BY {$sortColumn} {$direction}, ms.id ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
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

    public function getLowStockByDepartment(int $departmentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
            ms.id,
            ms.material_id,
            ms.department_id,
            ms.quantity,
            m.code AS material_code,
            m.name AS material_name,
            m.unit AS material_unit,
            m.minimum_stock_level,
            m.reorder_level,
            d.name AS department_name
        FROM material_stocks ms
        INNER JOIN materials m
            ON m.id = ms.material_id
        INNER JOIN departments d
            ON d.id = ms.department_id
        WHERE ms.department_id = :department_id
          AND m.is_active = 1
          AND ms.quantity <= m.reorder_level
        ORDER BY ms.quantity ASC, m.name ASC'
        );

        $stmt->execute([
            'department_id' => $departmentId,
        ]);

        return $stmt->fetchAll();
    }
}