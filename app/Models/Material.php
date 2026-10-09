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