<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class MaterialReplenishment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Get active materials for the replenishment dropdown.
     */
    public function getMaterialOptions(): array
    {
        $stmt = $this->db->query(
            'SELECT
                id AS material_id,
                code AS material_code,
                name AS material_name,
                unit AS material_unit
             FROM materials
             WHERE is_active = 1
             ORDER BY name ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record a replenishment history entry only.
     *
     * Does not modify material_stocks or stock_movements.
     */
    public function create(
        int $materialId,
        int $departmentId,
        float $quantity,
        string $replenishedAt,
        ?string $referenceNumber,
        ?string $notes,
        int $recordedBy
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO material_replenishments (
                material_id,
                department_id,
                quantity,
                replenished_at,
                reference_number,
                notes,
                recorded_by
            ) VALUES (
                :material_id,
                :department_id,
                :quantity,
                :replenished_at,
                :reference_number,
                :notes,
                :recorded_by
            )'
        );

        $stmt->execute([
            'material_id' => $materialId,
            'department_id' => $departmentId,
            'quantity' => $quantity,
            'replenished_at' => $replenishedAt,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'recorded_by' => $recordedBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get replenishment history for one department.
     */
    public function getPage(
        int $departmentId,
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $page = max(1, $page);

        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        $where = ['1 = 1'];

        $params = [];

        if ($search !== '') {
            $where[] = '(
                m.code LIKE :search_code
                OR m.name LIKE :search_name
                OR mr.reference_number LIKE :search_reference
                OR mr.notes LIKE :search_notes
            )';

            $term = '%' . $search . '%';

            $params['search_code'] = $term;
            $params['search_name'] = $term;
            $params['search_reference'] = $term;
            $params['search_notes'] = $term;
        }

        $validDate = static function (string $value): bool {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false
                && $date->format('Y-m-d') === $value;
        };

        if ($dateFrom !== '' && $validDate($dateFrom)) {
            $where[] = 'mr.replenished_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '' && $validDate($dateTo)) {
            $where[] = 'mr.replenished_at < :date_to';

            $params['date_to'] = (
                new \DateTimeImmutable($dateTo)
            )->modify('+1 day')->format('Y-m-d 00:00:00');
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM material_replenishments mr
             INNER JOIN materials m
                ON m.id = mr.material_id
             WHERE {$whereSql}"
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT
                mr.id,
                mr.material_id,
                mr.department_id,
                mr.quantity,
                mr.replenished_at,
                mr.reference_number,
                mr.notes,
                mr.recorded_by,
                mr.created_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                COALESCE(d.name, 'Unknown Department') AS department_name,
                CONCAT(
                    COALESCE(u.first_name, ''),
                    CASE
                        WHEN u.first_name IS NOT NULL
                         AND u.last_name IS NOT NULL
                        THEN ' '
                        ELSE ''
                    END,
                    COALESCE(u.last_name, '')
                ) AS recorded_by_name
             FROM material_replenishments mr
             INNER JOIN materials m
                ON m.id = mr.material_id
             INNER JOIN departments d
                ON d.id = mr.department_id
             INNER JOIN users u
                ON u.id = mr.recorded_by
             WHERE {$whereSql}
             ORDER BY mr.replenished_at DESC, mr.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );

        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
}
