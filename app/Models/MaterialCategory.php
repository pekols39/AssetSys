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