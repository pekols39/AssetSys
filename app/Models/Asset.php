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
                a.assigned_to_name,
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
                a.finance_item_id,
                a.finance_item_name,
                a.finance_ffe_number,
                a.finance_code,
                a.finance_main_location,
                a.finance_owner_department,
                a.finance_status,
                a.finance_supplier,
                a.finance_po_number,
                a.finance_location,
                a.finance_price,
                a.finance_price_text,
                a.finance_date_purchase_text,

                c.name AS category_name,
                s.name AS status_name,
                ac.name AS condition_name,
                d.name AS department_name,
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

            LEFT JOIN departments d
                ON d.id = a.department_id

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
                a.finance_item_id,
                a.finance_item_name,
                a.finance_ffe_number,
                a.finance_code,
                a.finance_main_location,
                a.finance_owner_department,
                a.finance_status,
                a.finance_supplier,
                a.finance_po_number,
                a.finance_location,
                a.finance_price,
                a.finance_price_text,
                a.finance_date_purchase_text,
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


    /**
     * Get a searchable and paginated asset register.
     */
    public function getPage(
        array $filters = [],
        ?int $departmentId = null,
        int $page = 1,
        int $perPage = 25
    ): array {
        $allowedPageSizes = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPageSizes, true)) {
            $perPage = 25;
        }

        $page = max(1, $page);

        $where = [];
        $params = [];

        if ($departmentId !== null) {
            $where[] = 'a.department_id = :department_id';
            $params['department_id'] = $departmentId;
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $where[] = '(
                a.asset_code LIKE :search_code
                OR a.asset_tag LIKE :search_tag
                OR a.serial_number LIKE :search_serial
                OR a.asset_name LIKE :search_name
                OR c.name LIKE :search_category
                OR s.name LIKE :search_status
                OR ac.name LIKE :search_condition
                OR l.name LIKE :search_location
                OR a.finance_item_id LIKE :search_item_id
                OR a.finance_item_name LIKE :search_finance_item_name
                OR a.finance_ffe_number LIKE :search_ffe
                OR a.finance_code LIKE :search_finance_code
                OR a.finance_main_location LIKE :search_main_location
                OR a.finance_owner_department LIKE :search_owner_department
                OR a.finance_status LIKE :search_finance_status
                OR a.finance_supplier LIKE :search_supplier
                OR a.finance_po_number LIKE :search_po
                OR a.finance_location LIKE :search_finance_location
                OR a.finance_price_text LIKE :search_finance_price
                OR a.finance_date_purchase_text LIKE :search_finance_date
                OR assigned.first_name LIKE :search_first
                OR assigned.last_name LIKE :search_last
                OR assigned.employee_number LIKE :search_employee
            )';

            $term = '%' . $search . '%';

            foreach ([
                'search_code',
                'search_tag',
                'search_serial',
                'search_name',
                'search_category',
                'search_status',
                'search_condition',
                'search_location',
                'search_item_id',
                'search_finance_item_name',
                'search_ffe',
                'search_finance_code',
                'search_main_location',
                'search_owner_department',
                'search_finance_status',
                'search_supplier',
                'search_po',
                'search_finance_location',
                'search_finance_price',
                'search_finance_date',
                'search_first',
                'search_last',
                'search_employee',
            ] as $key) {
                $params[$key] = $term;
            }
        }

        $filterColumns = [
            'category_id' => 'a.category_id',
            'status_id' => 'a.status_id',
            'condition_id' => 'a.condition_id',
            'location_id' => 'a.location_id',
        ];

        foreach ($filterColumns as $key => $column) {
            $value = $filters[$key] ?? '';

            if (
                $value !== ''
                && filter_var($value, FILTER_VALIDATE_INT) !== false
                && (int) $value > 0
            ) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = (int) $value;
            }
        }

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $fromSql = '
            FROM assets a
            INNER JOIN asset_categories c
                ON c.id = a.category_id
            INNER JOIN asset_statuses s
                ON s.id = a.status_id
            LEFT JOIN asset_conditions ac
                ON ac.id = a.condition_id
            LEFT JOIN departments d
                ON d.id = a.department_id
            LEFT JOIN locations l
                ON l.id = a.location_id
            LEFT JOIN vendors v
                ON v.id = a.supplier_id
            LEFT JOIN users assigned
                ON assigned.id = a.assigned_to_user_id
        ';

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             ' . $fromSql . $whereSql
        );

        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($total / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sortColumns = [
            'asset_code' => 'a.asset_code',
            'asset_name' => 'a.asset_name',
            'category' => 'c.name',
            'status' => 's.name',
            'condition' => 'ac.name',
            'location' => 'l.name',
            'purchase_date' => 'a.purchase_date',
            'purchase_cost' => 'a.purchase_cost',
            'book_value' => 'a.current_book_value',
            'created_at' => 'a.created_at',
        ];

        $sortKey = (string) ($filters['sort'] ?? 'created_at');
        $sortColumn = $sortColumns[$sortKey] ?? 'a.created_at';

        $direction = strtolower(
            (string) ($filters['direction'] ?? 'desc')
        ) === 'asc' ? 'ASC' : 'DESC';

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
                a.assigned_to_name,
                a.finance_item_id,
                a.finance_item_name,
                a.finance_ffe_number,
                a.finance_code,
                a.finance_main_location,
                a.finance_owner_department,
                a.finance_status,
                a.finance_supplier,
                a.finance_po_number,
                a.finance_location,
                a.finance_price,
                a.finance_price_text,
                a.finance_date_purchase_text,
                c.name AS category_name,
                s.name AS status_name,
                ac.name AS condition_name,
                d.name AS department_name,
                l.name AS location_name,
                v.name AS supplier_name,
                assigned.first_name AS assigned_first_name,
                assigned.last_name AS assigned_last_name
            ' . $fromSql . $whereSql . '
            ORDER BY ' . $sortColumn . ' ' . $direction . ',
                     a.id DESC
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
                assigned_to_name,
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
                :assigned_to_name,
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
            'assigned_to_name' =>
                $data['assigned_to_name'] ?? null,
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
                assigned_to_user_id = :assigned_to_user_id,
                assigned_to_name = :assigned_to_name
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
            'assigned_to_name' =>
                $data['assigned_to_name'] ?? null,
        ]);
    }
}