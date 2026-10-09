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
            WHERE ms.department_id = :department_id
            ORDER BY sm.moved_at DESC, sm.id DESC'
        );

        $stmt->execute([
            'department_id' => $departmentId,
        ]);

        return $stmt->fetchAll();
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
             WHERE ms.department_id = :department_id
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

        $stmt->execute([
            'department_id' => $departmentId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}