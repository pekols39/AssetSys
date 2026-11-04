<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class MaterialBorrowing
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function getBorrowableMaterials(): array
    {
        $stmt = $this->db->query(
            'SELECT
                m.id AS material_id,
                m.code,
                m.name,
                m.unit,
                ms.id AS material_stock_id,
                ms.quantity AS available_quantity
             FROM materials m
             INNER JOIN material_stocks ms
                ON ms.material_id = m.id
                AND ms.department_id IS NULL
             WHERE m.is_active = 1
               AND m.is_returnable = 1
             ORDER BY m.name ASC'
        );

        return $stmt->fetchAll();
    }

    public function getOutstandingPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        return $this->getPage($filters, $page, $perPage, true);
    }

    public function getHistoryPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        return $this->getPage($filters, $page, $perPage, false);
    }

    private function getPage(
        array $filters,
        int $page,
        int $perPage,
        bool $outstandingOnly
    ): array {
        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(mb.borrower_name LIKE :borrower OR m.name LIKE :material OR m.code LIKE :code OR mb.reference_number LIKE :reference)';
            $term = '%' . $search . '%';
            $params['borrower'] = $term;
            $params['material'] = $term;
            $params['code'] = $term;
            $params['reference'] = $term;
        }

        if ($outstandingOnly) {
            $where[] = '(mb.quantity - COALESCE(ret.returned_quantity, 0)) > 0';
            if ($status === 'overdue') {
                $where[] = 'mb.expected_return_date < CURDATE()';
            } elseif ($status === 'not_overdue') {
                $where[] = 'mb.expected_return_date >= CURDATE()';
            }
        }

        $whereSql = implode(' AND ', $where);
        $baseFrom = '
            FROM material_borrowings mb
            INNER JOIN material_stocks ms ON ms.id = mb.material_stock_id
            INNER JOIN materials m ON m.id = ms.material_id
            LEFT JOIN users u ON u.id = mb.recorded_by
            LEFT JOIN (
                SELECT borrowing_id, SUM(quantity) AS returned_quantity
                FROM material_borrowing_returns
                GROUP BY borrowing_id
            ) ret ON ret.borrowing_id = mb.id
        ';

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) {$baseFrom} WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT
                mb.id,
                mb.borrower_name,
                mb.quantity AS borrowed_quantity,
                COALESCE(ret.returned_quantity, 0) AS returned_quantity,
                (mb.quantity - COALESCE(ret.returned_quantity, 0)) AS outstanding_quantity,
                mb.location,
                mb.purpose,
                mb.borrowed_at,
                mb.expected_return_date,
                mb.reference_number,
                mb.notes,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                u.first_name AS recorder_first_name,
                u.last_name AS recorder_last_name,
                CASE
                    WHEN (mb.quantity - COALESCE(ret.returned_quantity, 0)) <= 0 THEN 'returned'
                    WHEN mb.expected_return_date < CURDATE() THEN 'overdue'
                    ELSE 'outstanding'
                END AS borrowing_status
             {$baseFrom}
             WHERE {$whereSql}
             ORDER BY mb.borrowed_at DESC, mb.id DESC
             LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
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

    public function findDetails(int $borrowingId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                mb.id,
                mb.material_stock_id,
                mb.borrower_name,
                mb.quantity AS borrowed_quantity,
                COALESCE(ret.returned_quantity, 0) AS returned_quantity,
                (mb.quantity - COALESCE(ret.returned_quantity, 0)) AS outstanding_quantity,
                mb.location,
                mb.purpose,
                mb.borrowed_at,
                mb.expected_return_date,
                mb.reference_number,
                mb.notes,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit
             FROM material_borrowings mb
             INNER JOIN material_stocks ms ON ms.id = mb.material_stock_id
             INNER JOIN materials m ON m.id = ms.material_id
             LEFT JOIN (
                SELECT borrowing_id, SUM(quantity) AS returned_quantity
                FROM material_borrowing_returns
                GROUP BY borrowing_id
             ) ret ON ret.borrowing_id = mb.id
             WHERE mb.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $borrowingId]);
        $borrowing = $stmt->fetch();

        if (!$borrowing) {
            return false;
        }

        $returns = $this->db->prepare(
            'SELECT
                r.id,
                r.quantity,
                r.returned_at,
                r.item_condition,
                r.condition_notes,
                r.notes,
                r.created_at,
                u.first_name AS recorder_first_name,
                u.last_name AS recorder_last_name
             FROM material_borrowing_returns r
             LEFT JOIN users u ON u.id = r.recorded_by
             WHERE r.borrowing_id = :borrowing_id
             ORDER BY r.returned_at DESC, r.id DESC'
        );
        $returns->execute(['borrowing_id' => $borrowingId]);
        $borrowing['returns'] = $returns->fetchAll();

        return $borrowing;
    }
}
