<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class StockMovement
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
                sm.id,
                sm.material_stock_id,
                sm.movement_type,
                sm.quantity,
                sm.quantity_delta,
                sm.balance_after,
                sm.vendor_id,
                sm.issued_to_user_id,
                sm.issued_to_name,
                sm.reference_number,
                sm.purpose,
                sm.reason,
                sm.notes,
                sm.moved_at,
                sm.recorded_by,
                sm.created_at,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                d.id AS department_id,
                d.name AS department_name,
                v.name AS vendor_name,
                COALESCE(
                    NULLIF(sm.issued_to_name, \'\'),
                    NULLIF(
                        CONCAT(
                            COALESCE(u.first_name, \'\'),
                            CASE
                                WHEN u.first_name IS NOT NULL
                                 AND u.last_name IS NOT NULL
                                THEN \' \'
                                ELSE \'\'
                            END,
                            COALESCE(u.last_name, \'\')
                        ),
                        \'\'
                    )
                ) AS recipient_name,
                CONCAT(
                    COALESCE(r.first_name, \'\'),
                    CASE
                        WHEN r.first_name IS NOT NULL
                         AND r.last_name IS NOT NULL
                        THEN \' \'
                        ELSE \'\'
                    END,
                    COALESCE(r.last_name, \'\')
                ) AS recorded_by_name
            FROM stock_movements sm
            INNER JOIN material_stocks ms
                ON ms.id = sm.material_stock_id
            INNER JOIN materials m
                ON m.id = ms.material_id
            INNER JOIN departments d
                ON d.id = ms.department_id
            LEFT JOIN vendors v
                ON v.id = sm.vendor_id
            LEFT JOIN users u
                ON u.id = sm.issued_to_user_id
            INNER JOIN users r
                ON r.id = sm.recorded_by
            WHERE sm.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function getByStock(int $materialStockId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                sm.id,
                sm.material_stock_id,
                sm.movement_type,
                sm.quantity,
                sm.quantity_delta,
                sm.balance_after,
                sm.vendor_id,
                sm.issued_to_user_id,
                sm.issued_to_name,
                sm.reference_number,
                sm.purpose,
                sm.reason,
                sm.notes,
                sm.moved_at,
                sm.recorded_by,
                sm.created_at,
                v.name AS vendor_name,
                COALESCE(
                    NULLIF(sm.issued_to_name, \'\'),
                    NULLIF(
                        CONCAT(
                            COALESCE(u.first_name, \'\'),
                            CASE
                                WHEN u.first_name IS NOT NULL
                                 AND u.last_name IS NOT NULL
                                THEN \' \'
                                ELSE \'\'
                            END,
                            COALESCE(u.last_name, \'\')
                        ),
                        \'\'
                    )
                ) AS recipient_name,
                CONCAT(
                    COALESCE(r.first_name, \'\'),
                    CASE
                        WHEN r.first_name IS NOT NULL
                         AND r.last_name IS NOT NULL
                        THEN \' \'
                        ELSE \'\'
                    END,
                    COALESCE(r.last_name, \'\')
                ) AS recorded_by_name
            FROM stock_movements sm
            LEFT JOIN vendors v
                ON v.id = sm.vendor_id
            LEFT JOIN users u
                ON u.id = sm.issued_to_user_id
            INNER JOIN users r
                ON r.id = sm.recorded_by
            WHERE sm.material_stock_id = :material_stock_id
            ORDER BY sm.moved_at DESC, sm.id DESC'
        );

        $stmt->execute([
            'material_stock_id' => $materialStockId,
        ]);

        return $stmt->fetchAll();
    }

    public function getByDepartment(int $departmentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                sm.id,
                sm.material_stock_id,
                sm.movement_type,
                sm.quantity,
                sm.quantity_delta,
                sm.balance_after,
                sm.vendor_id,
                sm.issued_to_user_id,
                sm.issued_to_name,
                sm.reference_number,
                sm.purpose,
                sm.reason,
                sm.notes,
                sm.moved_at,
                sm.recorded_by,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                \'Organization-wide\' AS department_name,
                v.name AS vendor_name,
                COALESCE(
                    NULLIF(sm.issued_to_name, \'\'),
                    NULLIF(
                        CONCAT(
                            COALESCE(u.first_name, \'\'),
                            CASE
                                WHEN u.first_name IS NOT NULL
                                AND u.last_name IS NOT NULL
                                THEN \' \'
                                ELSE \'\'
                            END,
                            COALESCE(u.last_name, \'\')
                        ),
                        \'\'
                    )
                ) AS recipient_name,
                CONCAT(
                    COALESCE(r.first_name, \'\'),
                    CASE
                        WHEN r.first_name IS NOT NULL
                        AND r.last_name IS NOT NULL
                        THEN \' \'
                        ELSE \'\'
                    END,
                    COALESCE(r.last_name, \'\')
                ) AS recorded_by_name
            FROM stock_movements sm
            INNER JOIN material_stocks ms
                ON ms.id = sm.material_stock_id
            INNER JOIN materials m
                ON m.id = ms.material_id
            LEFT JOIN vendors v
                ON v.id = sm.vendor_id
            LEFT JOIN users u
                ON u.id = sm.issued_to_user_id
            INNER JOIN users r
                ON r.id = sm.recorded_by
            WHERE ms.department_id IS NULL
            ORDER BY sm.moved_at DESC, sm.id DESC'
        );

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get paginated stock movement history for one department.
     *
     * Search and filters are applied before pagination.
     */
    public function getPage(
        int $departmentId,
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        if ($departmentId <= 0) {
            throw new \InvalidArgumentException(
                'A valid department is required.'
            );
        }

        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 10;

        $search = trim((string) ($filters['search'] ?? ''));
        $movementType = (string) ($filters['movement_type'] ?? 'all');
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        $allowedTypes = [
            'all',
            'in',
            'out',
            'return',
            'adjustment',
        ];

        if (!in_array($movementType, $allowedTypes, true)) {
            $movementType = 'all';
        }

        $where = ['ms.department_id IS NULL'];
        $params = [];

        if ($search !== '') {
            $where[] = "(
                m.code LIKE :search_code
                OR m.name LIKE :search_name
                OR sm.reference_number LIKE :search_reference
                OR sm.issued_to_name LIKE :search_recipient
                OR sm.purpose LIKE :search_purpose
                OR sm.reason LIKE :search_reason
                OR sm.notes LIKE :search_notes
            )";

            $term = '%' . $search . '%';
            $params['search_code'] = $term;
            $params['search_name'] = $term;
            $params['search_reference'] = $term;
            $params['search_recipient'] = $term;
            $params['search_purpose'] = $term;
            $params['search_reason'] = $term;
            $params['search_notes'] = $term;
        }

        if ($movementType !== 'all') {
            $where[] = 'sm.movement_type = :movement_type';
            $params['movement_type'] = $movementType;
        }

        $validDate = static function (string $value): bool {
            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

            return $date !== false
                && $date->format('Y-m-d') === $value;
        };

        if ($dateFrom !== '' && $validDate($dateFrom)) {
            $where[] = 'sm.moved_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '' && $validDate($dateTo)) {
            $where[] = 'sm.moved_at < :date_to';
            $params['date_to'] = (new \DateTimeImmutable($dateTo))
                ->modify('+1 day')
                ->format('Y-m-d 00:00:00');
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM stock_movements sm
             INNER JOIN material_stocks ms
                ON ms.id = sm.material_stock_id
             INNER JOIN materials m
                ON m.id = ms.material_id
             WHERE {$whereSql}"
        );

        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT
                sm.id,
                sm.material_stock_id,
                sm.movement_type,
                sm.quantity,
                sm.quantity_delta,
                sm.balance_after,
                sm.vendor_id,
                sm.issued_to_user_id,
                sm.issued_to_name,
                sm.reference_number,
                sm.purpose,
                sm.reason,
                sm.notes,
                sm.moved_at,
                sm.recorded_by,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                'Organization-wide' AS department_name,
                v.name AS vendor_name,
                COALESCE(
                    NULLIF(sm.issued_to_name, ''),
                    NULLIF(
                        CONCAT(
                            COALESCE(u.first_name, ''),
                            CASE
                                WHEN u.first_name IS NOT NULL
                                 AND u.last_name IS NOT NULL
                                THEN ' '
                                ELSE ''
                            END,
                            COALESCE(u.last_name, '')
                        ),
                        ''
                    )
                ) AS recipient_name,
                CONCAT(
                    COALESCE(r.first_name, ''),
                    CASE
                        WHEN r.first_name IS NOT NULL
                         AND r.last_name IS NOT NULL
                        THEN ' '
                        ELSE ''
                    END,
                    COALESCE(r.last_name, '')
                ) AS recorded_by_name
             FROM stock_movements sm
             INNER JOIN material_stocks ms
                ON ms.id = sm.material_stock_id
             INNER JOIN materials m
                ON m.id = ms.material_id
             LEFT JOIN vendors v
                ON v.id = sm.vendor_id
             LEFT JOIN users u
                ON u.id = sm.issued_to_user_id
             INNER JOIN users r
                ON r.id = sm.recorded_by
             WHERE {$whereSql}
             ORDER BY sm.moved_at DESC, sm.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );

        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Get stock-out transactions with quantity still available to return.
     *
     * Results are restricted to the authenticated user's department.
     */
    public function getReturnableStockOutsByDepartment(
        int $departmentId
    ): array {
        $stmt = $this->db->prepare(
                'SELECT
                sm.id AS movement_id,
                sm.material_stock_id,
                ms.material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                sm.quantity AS issued_quantity,
                COALESCE(SUM(ret.quantity), 0) AS returned_quantity,
                (
                    sm.quantity - COALESCE(SUM(ret.quantity), 0)
                ) AS remaining_quantity,
                sm.issued_to_name,
                sm.purpose,
                sm.reference_number,
                sm.moved_at
            FROM stock_movements sm
            INNER JOIN material_stocks ms
                ON ms.id = sm.material_stock_id
            INNER JOIN materials m
                ON m.id = ms.material_id
            LEFT JOIN stock_movements ret
                ON ret.related_movement_id = sm.id
            AND ret.movement_type = \'return\'
            WHERE ms.department_id IS NULL
            AND sm.movement_type = \'out\'
            AND m.is_active = 1
            AND m.is_returnable = 1
            GROUP BY
                sm.id,
                sm.material_stock_id,
                ms.material_id,
                m.code,
                m.name,
                m.unit,
                sm.quantity,
                sm.issued_to_name,
                sm.purpose,
                sm.reference_number,
                sm.moved_at
            HAVING remaining_quantity > 0
            ORDER BY sm.moved_at DESC, sm.id DESC'
            );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}