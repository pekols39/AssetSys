<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class Asset
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
                a.id,
                a.asset_code,
                a.asset_tag,
                a.serial_number,
                a.category_id,
                a.department_id,
                a.asset_name,
                a.description,
                a.manufacturer,
                a.model,
                a.model_number,
                a.purchase_date,
                a.purchase_cost,
                a.supplier_id,
                a.invoice_number,
                a.purchase_order_number,
                a.warranty_start,
                a.warranty_end,
                a.useful_life_years,
                a.salvage_value,
                a.depreciation_method,
                a.current_book_value,
                a.status_id,
                a.location_id,
                a.condition_id,
                a.assigned_to_user_id,
                a.created_by,
                a.created_at,
                a.updated_at,

                c.name AS category_name,
                s.name AS status_name,
                ac.name AS condition_name,
                l.name AS location_name,
                v.name AS supplier_name,

                assigned.first_name AS assigned_first_name,
                assigned.last_name AS assigned_last_name,
                assigned.username AS assigned_username,

                creator.first_name AS created_by_first_name,
                creator.last_name AS created_by_last_name,
                creator.username AS created_by_username

            FROM assets a

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            INNER JOIN asset_statuses s
                ON s.id = a.status_id

            LEFT JOIN asset_conditions ac
                ON ac.id = a.condition_id

            LEFT JOIN locations l
                ON l.id = a.location_id

            LEFT JOIN vendors v
                ON v.id = a.supplier_id

            LEFT JOIN users assigned
                ON assigned.id = a.assigned_to_user_id

            INNER JOIN users creator
                ON creator.id = a.created_by

            WHERE a.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByIdForUpdate(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
            a.id,
            a.asset_code,
            a.asset_tag,
            a.serial_number,
            a.category_id,
            a.department_id,
            a.asset_name,
            a.description,
            a.manufacturer,
            a.model,
            a.model_number,
            a.purchase_date,
            a.purchase_cost,
            a.supplier_id,
            a.invoice_number,
            a.purchase_order_number,
            a.warranty_start,
            a.warranty_end,
            a.useful_life_years,
            a.salvage_value,
            a.depreciation_method,
            a.current_book_value,
            a.status_id,
            a.location_id,
            a.condition_id,
            a.assigned_to_user_id,
            a.created_by,
            a.created_at,
            a.updated_at,

            c.name AS category_name,
            s.name AS status_name,
            ac.name AS condition_name,
            l.name AS location_name,
            v.name AS supplier_name,

            assigned.first_name AS assigned_first_name,
            assigned.last_name AS assigned_last_name,
            assigned.username AS assigned_username,

            creator.first_name AS created_by_first_name,
            creator.last_name AS created_by_last_name,
            creator.username AS created_by_username

        FROM assets a

        INNER JOIN asset_categories c
            ON c.id = a.category_id

        INNER JOIN asset_statuses s
            ON s.id = a.status_id

        LEFT JOIN asset_conditions ac
            ON ac.id = a.condition_id

        LEFT JOIN locations l
            ON l.id = a.location_id

        LEFT JOIN vendors v
            ON v.id = a.supplier_id

        LEFT JOIN users assigned
            ON assigned.id = a.assigned_to_user_id

        INNER JOIN users creator
            ON creator.id = a.created_by

        WHERE a.id = :id
        LIMIT 1
        FOR UPDATE'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByAssetCode(
        string $assetCode
    ): array|false {
        $stmt = $this->db->prepare(
            'SELECT
                a.id,
                a.asset_code,
                a.asset_tag,
                a.serial_number,
                a.category_id,
                a.asset_name,
                a.description,
                a.manufacturer,
                a.model,
                a.model_number,
                a.purchase_date,
                a.purchase_cost,
                a.supplier_id,
                a.invoice_number,
                a.purchase_order_number,
                a.warranty_start,
                a.warranty_end,
                a.useful_life_years,
                a.salvage_value,
                a.depreciation_method,
                a.current_book_value,
                a.status_id,
                a.location_id,
                a.condition_id,
                a.assigned_to_user_id,
                a.created_by,
                a.created_at,
                a.updated_at,

                c.name AS category_name,
                s.name AS status_name,
                ac.name AS condition_name,
                l.name AS location_name,
                v.name AS supplier_name,

                assigned.first_name AS assigned_first_name,
                assigned.last_name AS assigned_last_name,
                assigned.username AS assigned_username,

                creator.first_name AS created_by_first_name,
                creator.last_name AS created_by_last_name,
                creator.username AS created_by_username

            FROM assets a

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            INNER JOIN asset_statuses s
                ON s.id = a.status_id

            LEFT JOIN asset_conditions ac
                ON ac.id = a.condition_id

            LEFT JOIN locations l
                ON l.id = a.location_id

            LEFT JOIN vendors v
                ON v.id = a.supplier_id

            LEFT JOIN users assigned
                ON assigned.id = a.assigned_to_user_id

            INNER JOIN users creator
                ON creator.id = a.created_by

            WHERE a.asset_code = :asset_code
            LIMIT 1'
        );

        $stmt->execute([
            'asset_code' => trim($assetCode),
        ]);

        return $stmt->fetch();
    }

    public function getAll(
        ?int $departmentId = null,
        ?int $assignedToUserId = null
    ): array {
        $sql = '
            SELECT
                a.id,
                a.asset_code,
                a.asset_tag,
                a.serial_number,
                a.asset_name,
                a.manufacturer,
                a.model,
                a.model_number,
                a.purchase_date,
                a.purchase_cost,
                a.current_book_value,
                a.department_id,
                a.assigned_to_user_id,

                c.name AS category_name,
                s.name AS status_name,
                ac.name AS condition_name,
                l.name AS location_name,
                v.name AS supplier_name,

                assigned.first_name AS assigned_first_name,
                assigned.last_name AS assigned_last_name

            FROM assets a

            INNER JOIN asset_categories c
                ON c.id = a.category_id

            INNER JOIN asset_statuses s
                ON s.id = a.status_id

            LEFT JOIN asset_conditions ac
                ON ac.id = a.condition_id

            LEFT JOIN locations l
                ON l.id = a.location_id

            LEFT JOIN vendors v
                ON v.id = a.supplier_id

            LEFT JOIN users assigned
                ON assigned.id = a.assigned_to_user_id
        ';

        $conditions = [];
        $params = [];

        if ($departmentId !== null) {
            $conditions[] = 'a.department_id = :department_id';
            $params['department_id'] = $departmentId;
        }

        if ($assignedToUserId !== null) {
            $conditions[] = 'a.assigned_to_user_id = :assigned_to_user_id';
            $params['assigned_to_user_id'] = $assignedToUserId;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO assets (
                asset_code,
                asset_tag,
                serial_number,
                category_id,
                department_id,
                asset_name,
                description,
                manufacturer,
                model,
                model_number,
                purchase_date,
                purchase_cost,
                supplier_id,
                invoice_number,
                purchase_order_number,
                warranty_start,
                warranty_end,
                useful_life_years,
                salvage_value,
                depreciation_method,
                current_book_value,
                status_id,
                location_id,
                condition_id,
                assigned_to_user_id,
                created_by
            ) VALUES (
                :asset_code,
                :asset_tag,
                :serial_number,
                :category_id,
                :department_id,
                :asset_name,
                :description,
                :manufacturer,
                :model,
                :model_number,
                :purchase_date,
                :purchase_cost,
                :supplier_id,
                :invoice_number,
                :purchase_order_number,
                :warranty_start,
                :warranty_end,
                :useful_life_years,
                :salvage_value,
                :depreciation_method,
                :current_book_value,
                :status_id,
                :location_id,
                :condition_id,
                :assigned_to_user_id,
                :created_by
            )'
        );

        $stmt->execute([
            'asset_code' => trim((string) $data['asset_code']),
            'asset_tag' => $data['asset_tag'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'category_id' => (int) $data['category_id'],
            'department_id' => $data['department_id'] ?? null,
            'asset_name' => trim((string) $data['asset_name']),
            'description' => $data['description'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'model' => $data['model'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_cost' => $data['purchase_cost'] ?? 0,
            'supplier_id' => $data['supplier_id'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'purchase_order_number' => $data['purchase_order_number'] ?? null,
            'warranty_start' => $data['warranty_start'] ?? null,
            'warranty_end' => $data['warranty_end'] ?? null,
            'useful_life_years' => $data['useful_life_years'] ?? null,
            'salvage_value' => $data['salvage_value'] ?? 0,
            'depreciation_method' =>
                $data['depreciation_method'] ?? 'straight_line',
            'current_book_value' =>
                $data['current_book_value']
                ?? ($data['purchase_cost'] ?? 0),
            'status_id' => (int) $data['status_id'],
            'location_id' => $data['location_id'] ?? null,
            'condition_id' => $data['condition_id'] ?? null,
            'assigned_to_user_id' =>
                $data['assigned_to_user_id'] ?? null,
            'created_by' => (int) $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        array $data
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE assets
             SET
                asset_code = :asset_code,
                asset_tag = :asset_tag,
                serial_number = :serial_number,
                category_id = :category_id,
                department_id = :department_id,
                asset_name = :asset_name,
                description = :description,
                manufacturer = :manufacturer,
                model = :model,
                model_number = :model_number,
                purchase_date = :purchase_date,
                purchase_cost = :purchase_cost,
                supplier_id = :supplier_id,
                invoice_number = :invoice_number,
                purchase_order_number = :purchase_order_number,
                warranty_start = :warranty_start,
                warranty_end = :warranty_end,
                useful_life_years = :useful_life_years,
                salvage_value = :salvage_value,
                depreciation_method = :depreciation_method,
                current_book_value = :current_book_value,
                status_id = :status_id,
                location_id = :location_id,
                condition_id = :condition_id,
                assigned_to_user_id = :assigned_to_user_id
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
            'asset_code' => trim((string) $data['asset_code']),
            'asset_tag' => $data['asset_tag'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'category_id' => (int) $data['category_id'],
            'department_id' => $data['department_id'] ?? null,
            'asset_name' => trim((string) $data['asset_name']),
            'description' => $data['description'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'model' => $data['model'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_cost' => $data['purchase_cost'] ?? 0,
            'supplier_id' => $data['supplier_id'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'purchase_order_number' => $data['purchase_order_number'] ?? null,
            'warranty_start' => $data['warranty_start'] ?? null,
            'warranty_end' => $data['warranty_end'] ?? null,
            'useful_life_years' => $data['useful_life_years'] ?? null,
            'salvage_value' => $data['salvage_value'] ?? 0,
            'depreciation_method' =>
                $data['depreciation_method'] ?? 'straight_line',
            'current_book_value' =>
                $data['current_book_value'] ?? 0,
            'status_id' => (int) $data['status_id'],
            'location_id' => $data['location_id'] ?? null,
            'condition_id' => $data['condition_id'] ?? null,
            'assigned_to_user_id' =>
                $data['assigned_to_user_id'] ?? null,
        ]);
    }
}