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