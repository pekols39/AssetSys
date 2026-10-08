<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class AssetReportService
{
    private PDO $db;
    private int $departmentId;

    public function __construct(int $departmentId)
    {
        if ($departmentId <= 0) {
            throw new \InvalidArgumentException(
                'A valid department is required for report access.'
            );
        }

        $this->db = Database::connection();
        $this->departmentId = $departmentId;
    }

    /**
     * Retrieve asset inventory records with optional filters.
     *
     * Supported filters:
     * - category_id
     * - status_id
     * - condition_id
     * - location_id
     * - search
     */
    public function getAssetInventory(array $filters = []): array
    {
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

            WHERE a.department_id = :department_id
        ';

        $params = [
            'department_id' => $this->departmentId,
        ];

        $filterColumns = [
            'category_id' => 'a.category_id',
            'status_id' => 'a.status_id',
            'condition_id' => 'a.condition_id',
            'location_id' => 'a.location_id',
        ];

        foreach ($filterColumns as $filter => $column) {
            $value = $filters[$filter] ?? '';

            if (
                $value !== ''
                && $value !== null
                && filter_var($value, FILTER_VALIDATE_INT) !== false
                && (int) $value > 0
            ) {
                $sql .= " AND {$column} = :{$filter}";
                $params[$filter] = (int) $value;
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $sql .= '
                AND (
                    a.asset_code LIKE :search_code
                    OR a.asset_tag LIKE :search_tag
                    OR a.serial_number LIKE :search_serial
                    OR a.asset_name LIKE :search_name
                    OR a.manufacturer LIKE :search_manufacturer
                    OR a.model LIKE :search_model
                    OR v.name LIKE :search_supplier
                    OR assigned.first_name LIKE :search_first_name
                    OR assigned.last_name LIKE :search_last_name
                )
            ';

            $searchTerm = '%' . $search . '%';

            $params['search_code'] = $searchTerm;
            $params['search_tag'] = $searchTerm;
            $params['search_serial'] = $searchTerm;
            $params['search_name'] = $searchTerm;
            $params['search_manufacturer'] = $searchTerm;
            $params['search_model'] = $searchTerm;
            $params['search_supplier'] = $searchTerm;
            $params['search_first_name'] = $searchTerm;
            $params['search_last_name'] = $searchTerm;
        }

        $sql .= ' ORDER BY a.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}