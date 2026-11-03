<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class PhysicalStockAudit
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Get active consumable materials and their organization-wide
     * stock balances.
     *
     * Materials without a stock row are included with
     * a system quantity of zero.
     */
    public function getConsumableCandidates(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                COALESCE(ms.quantity, 0) AS system_quantity,
                ms.id AS material_stock_id
             FROM materials m
             LEFT JOIN material_stocks ms
                ON ms.material_id = m.id
               AND ms.department_id IS NULL
             WHERE m.is_active = 1
               AND m.is_returnable = 0
             ORDER BY m.name ASC'
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a consumable physical count record and its items.
     *
     * This is an audit-history operation only.
     * It does not modify material stock quantities.
     */
    public function createAudit(
        string $auditReference,
        ?int $departmentId,
        string $auditType,
        string $auditDate,
        ?string $auditNotes,
        int $createdBy,
        array $items
    ): int {
        if ($auditType !== 'consumable') {
            throw new \InvalidArgumentException(
                'Only consumable materials can be physically audited.'
            );
        }

        if ($items === []) {
            throw new \InvalidArgumentException(
                'A physical count must contain at least one item.'
            );
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO physical_stock_audits (
                    audit_reference,
                    department_id,
                    audit_type,
                    audit_status,
                    audit_date,
                    audit_notes,
                    created_by
                ) VALUES (
                    :audit_reference,
                    :department_id,
                    "consumable",
                    "recorded",
                    :audit_date,
                    :audit_notes,
                    :created_by
                )'
            );

            $stmt->execute([
                'audit_reference' => $auditReference,
                'department_id' => $departmentId,
                'audit_date' => $auditDate,
                'audit_notes' => $auditNotes,
                'created_by' => $createdBy,
            ]);

            $auditId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                'INSERT INTO physical_stock_audit_items (
                    audit_id,
                    material_id,
                    system_quantity,
                    physical_quantity,
                    variance,
                    item_notes
                ) VALUES (
                    :audit_id,
                    :material_id,
                    :system_quantity,
                    :physical_quantity,
                    :variance,
                    :item_notes
                )'
            );

            foreach ($items as $item) {
                $itemStmt->execute([
                    'audit_id' => $auditId,
                    'material_id' => (int) $item['material_id'],
                    'system_quantity' => $item['system_quantity'],
                    'physical_quantity' => $item['physical_quantity'],
                    'variance' => $item['variance'],
                    'item_notes' => $item['item_notes'] ?? null,
                ]);
            }

            $this->db->commit();

            return $auditId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Get an audit header with its creator, optional department
     * metadata, and optional legacy reviewer details.
     *
     * Department is not required for viewing audit history.
     */
    public function getAudit(int $auditId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                a.*,
                d.name AS department_name,
                creator.first_name AS creator_first_name,
                creator.last_name AS creator_last_name,
                creator.username AS creator_username,
                reviewer.first_name AS reviewer_first_name,
                reviewer.last_name AS reviewer_last_name,
                reviewer.username AS reviewer_username
             FROM physical_stock_audits a
             LEFT JOIN departments d
                ON d.id = a.department_id
             INNER JOIN users creator
                ON creator.id = a.created_by
             LEFT JOIN users reviewer
                ON reviewer.id = a.reviewed_by
             WHERE a.id = :audit_id
             LIMIT 1'
        );

        $stmt->execute([
            'audit_id' => $auditId,
        ]);

        $audit = $stmt->fetch(PDO::FETCH_ASSOC);

        return $audit ?: null;
    }

    /**
     * Retrieve audit items for consumable or legacy returnable records.
     */
    public function getAuditItems(
        int $auditId,
        string $auditType = 'consumable'
    ): array {
        if (!in_array($auditType, ['consumable', 'returnable'], true)) {
            throw new \InvalidArgumentException(
                'Invalid physical audit type.'
            );
        }

        if ($auditType === 'returnable') {
            $stmt = $this->db->prepare(
                'SELECT
                    ai.id,
                    ai.audit_id,
                    ms.material_id,
                    ai.system_remaining_quantity AS system_quantity,
                    ai.physical_quantity,
                    ai.variance,
                    ai.item_notes,
                    m.code AS material_code,
                    m.name AS material_name,
                    m.unit AS material_unit,
                    m.is_returnable
                 FROM physical_stock_audit_returnable_items ai
                 INNER JOIN stock_movements sm
                    ON sm.id = ai.original_movement_id
                 INNER JOIN material_stocks ms
                    ON ms.id = sm.material_stock_id
                 INNER JOIN materials m
                    ON m.id = ms.material_id
                 WHERE ai.audit_id = :audit_id
                 ORDER BY m.name ASC'
            );

            $stmt->execute([
                'audit_id' => $auditId,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->db->prepare(
            'SELECT
                ai.*,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                m.is_returnable
             FROM physical_stock_audit_items ai
             INNER JOIN materials m
                ON m.id = ai.material_id
             WHERE ai.audit_id = :audit_id
             ORDER BY m.name ASC'
        );

        $stmt->execute([
            'audit_id' => $auditId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the individual return transactions for returnable audit items.
     */
    public function getReturnHistory(int $auditId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                ai.id AS audit_item_id,
                ret.id AS return_movement_id,
                ret.quantity AS returned_quantity,
                ret.moved_at AS returned_at,
                ret.recorded_by AS received_by,
                ret.notes AS return_notes,
                CONCAT(
                    COALESCE(receiver.first_name, ""),
                    CASE
                        WHEN receiver.first_name IS NOT NULL
                        AND receiver.last_name IS NOT NULL
                        THEN " "
                        ELSE ""
                    END,
                    COALESCE(receiver.last_name, "")
                ) AS received_by_name
            FROM physical_stock_audit_returnable_items ai
            INNER JOIN stock_movements original
                ON original.id = ai.original_movement_id
            INNER JOIN stock_movements ret
                ON ret.related_movement_id = original.id
            AND ret.movement_type = "return"
            LEFT JOIN users receiver
                ON receiver.id = ret.recorded_by
            WHERE ai.audit_id = :audit_id
            ORDER BY ret.moved_at ASC, ret.id ASC'
        );

        $stmt->execute([
            'audit_id' => $auditId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get paginated organization-wide physical audit history.
     *
     * The department argument remains for compatibility with
     * existing callers, but it is no longer an access filter.
     *
     * Legacy audit statuses and returnable records remain
     * available in history.
     */
    public function getPaginatedAudits(
        ?int $departmentId,
        string $search = '',
        string $status = '',
        string $auditType = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $page = max(1, $page);

        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        if (
            !in_array(
                $status,
                ['', 'recorded', 'draft', 'pending', 'approved', 'rejected'],
                true
            )
        ) {
            $status = '';
        }

        if (!in_array($auditType, ['', 'consumable', 'returnable'], true)) {
            $auditType = '';
        }

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'a.audit_status = :audit_status';
            $params['audit_status'] = $status;
        }

        if ($auditType !== '') {
            $where[] = 'a.audit_type = :audit_type';
            $params['audit_type'] = $auditType;
        }

        $search = trim($search);

        if ($search !== '') {
            $where[] = '(
                a.audit_reference LIKE :search_reference
                OR d.name LIKE :search_department
                OR a.audit_notes LIKE :search_notes
                OR creator.first_name LIKE :search_first_name
                OR creator.last_name LIKE :search_last_name
                OR creator.username LIKE :search_username
            )';

            $searchValue = '%' . $search . '%';

            $params['search_reference'] = $searchValue;
            $params['search_department'] = $searchValue;
            $params['search_notes'] = $searchValue;
            $params['search_first_name'] = $searchValue;
            $params['search_last_name'] = $searchValue;
            $params['search_username'] = $searchValue;
        }

        $whereSql = $where !== []
            ? 'WHERE ' . implode(' AND ', $where)
            : '';

        $fromSql = '
            FROM physical_stock_audits a
            LEFT JOIN departments d
                ON d.id = a.department_id
            INNER JOIN users creator
                ON creator.id = a.created_by
            LEFT JOIN users reviewer
                ON reviewer.id = a.reviewed_by
        ';

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             ' . $fromSql . '
             ' . $whereSql
        );

        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = (int) ceil($total / $perPage);
        $page = $totalPages > 0
            ? min($page, $totalPages)
            : 1;

        $offset = ($page - 1) * $perPage;

        $dataStmt = $this->db->prepare(
            'SELECT
                a.*,
                d.name AS department_name,
                creator.first_name AS creator_first_name,
                creator.last_name AS creator_last_name,
                creator.username AS creator_username,
                reviewer.first_name AS reviewer_first_name,
                reviewer.last_name AS reviewer_last_name,
                reviewer.username AS reviewer_username
             ' . $fromSql . '
             ' . $whereSql . '
             ORDER BY a.audit_date DESC, a.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $dataStmt->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $dataStmt->execute();

        return [
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
}
