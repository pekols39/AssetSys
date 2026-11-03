<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetDisposal
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT
                d.*,
                a.asset_code,
                a.asset_name,
                a.serial_number,

                approver.employee_number
                    AS approved_by_employee_id,
                approver.first_name
                    AS approved_by_first_name,
                approver.last_name
                    AS approved_by_last_name,

                disposer.employee_number
                    AS disposed_by_employee_id,
                disposer.first_name
                    AS disposed_by_first_name,
                disposer.last_name
                    AS disposed_by_last_name

            FROM asset_disposals d

            INNER JOIN assets a
                ON a.id = d.asset_id

            LEFT JOIN users approver
                ON approver.id = d.approved_by

            INNER JOIN users disposer
                ON disposer.id = d.disposed_by

            WHERE d.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $result = $stmt->fetch();

        return $result !== false
            ? $result
            : null;
    }

    public function getByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT
                d.*,
                a.asset_code,
                a.asset_name,
                a.serial_number,

                approver.employee_number
                    AS approved_by_employee_id,
                approver.first_name
                    AS approved_by_first_name,
                approver.last_name
                    AS approved_by_last_name,

                disposer.employee_number
                    AS disposed_by_employee_id,
                disposer.first_name
                    AS disposed_by_first_name,
                disposer.last_name
                    AS disposed_by_last_name

            FROM asset_disposals d

            INNER JOIN assets a
                ON a.id = d.asset_id

            LEFT JOIN users approver
                ON approver.id = d.approved_by

            INNER JOIN users disposer
                ON disposer.id = d.disposed_by

            WHERE d.asset_id = :asset_id
            ORDER BY
                d.disposal_date DESC,
                d.id DESC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll();
    }

    public function getLatestByAssetId(
        int $assetId
    ): ?array {
        if ($assetId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT
                d.*,
                a.asset_code,
                a.asset_name,
                a.serial_number,

                approver.employee_number
                    AS approved_by_employee_id,
                approver.first_name
                    AS approved_by_first_name,
                approver.last_name
                    AS approved_by_last_name,

                disposer.employee_number
                    AS disposed_by_employee_id,
                disposer.first_name
                    AS disposed_by_first_name,
                disposer.last_name
                    AS disposed_by_last_name

            FROM asset_disposals d

            INNER JOIN assets a
                ON a.id = d.asset_id

            LEFT JOIN users approver
                ON approver.id = d.approved_by

            INNER JOIN users disposer
                ON disposer.id = d.disposed_by

            WHERE d.asset_id = :asset_id
            ORDER BY d.id DESC
            LIMIT 1'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        $result = $stmt->fetch();

        return $result !== false
            ? $result
            : null;
    }
    public function getPaginated(
        string $search = '',
        string $status = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $allowedPerPage = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }

        $allowedStatuses = [
            'pending',
            'approved',
            'completed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $page = max(1, $page);
        $search = trim($search);

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'd.disposal_status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $searchableFields = [
                'a.asset_code',
                'a.asset_name',
                'a.serial_number',
                'd.disposal_type',
                'd.disposal_reason',
                'd.description',
                'd.disposal_reference',
                'disposer.employee_number',
                'disposer.first_name',
                'disposer.last_name',
            ];

            $searchConditions = [];

            foreach ($searchableFields as $index => $field) {
                $placeholder = ':search_' . $index;

                $searchConditions[] =
                    $field . ' LIKE ' . $placeholder;

                $params[$placeholder] = '%' . $search . '%';
            }

            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        $whereSql = $where !== []
            ? 'WHERE ' . implode(' AND ', $where)
            : '';

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
         FROM asset_disposals d
         INNER JOIN assets a
            ON a.id = d.asset_id
         INNER JOIN users disposer
            ON disposer.id = d.disposed_by
         ' . $whereSql
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($total / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT
            d.*,
            a.asset_code,
            a.asset_name,
            a.serial_number,

            approver.employee_number
                AS approved_by_employee_id,
            approver.first_name
                AS approved_by_first_name,
            approver.last_name
                AS approved_by_last_name,

            disposer.employee_number
                AS disposed_by_employee_id,
            disposer.first_name
                AS disposed_by_first_name,
            disposer.last_name
                AS disposed_by_last_name

        FROM asset_disposals d

        INNER JOIN assets a
            ON a.id = d.asset_id

        LEFT JOIN users approver
            ON approver.id = d.approved_by

        INNER JOIN users disposer
            ON disposer.id = d.disposed_by

        ' . $whereSql . '

        ORDER BY
            d.disposal_date DESC,
            d.id DESC

        LIMIT :limit
        OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
    public function getPending(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
            d.*,
            a.asset_code,
            a.asset_name,
            a.serial_number,

            approver.employee_number
                AS approved_by_employee_id,
            approver.first_name
                AS approved_by_first_name,
            approver.last_name
                AS approved_by_last_name,

            disposer.employee_number
                AS disposed_by_employee_id,
            disposer.first_name
                AS disposed_by_first_name,
            disposer.last_name
                AS disposed_by_last_name

        FROM asset_disposals d

        INNER JOIN assets a
            ON a.id = d.asset_id

        LEFT JOIN users approver
            ON approver.id = d.approved_by

        INNER JOIN users disposer
            ON disposer.id = d.disposed_by

        WHERE d.disposal_status = :status

        ORDER BY
            d.disposal_date DESC,
            d.id DESC'
        );

        $stmt->execute([
            'status' => 'pending',
        ]);

        return $stmt->fetchAll();
    }

    public function getSummary(): array
    {
        $stmt = $this->db->query(
            "SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(disposal_status = 'completed'),
                0
            ) AS completed,
            COALESCE(
                SUM(disposal_status = 'approved'),
                0
            ) AS approved,
            COALESCE(
                SUM(
                    disposal_status = 'cancelled'
                    AND COALESCE(rejection_reason, '') <> ''
                ),
                0
            ) AS rejected
        FROM asset_disposals"
        );

        return $stmt->fetch() ?: [
            'total' => 0,
            'completed' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];
    }
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT
                d.*,
                a.asset_code,
                a.asset_name,
                a.serial_number,

                approver.employee_number
                    AS approved_by_employee_id,
                approver.first_name
                    AS approved_by_first_name,
                approver.last_name
                    AS approved_by_last_name,

                disposer.employee_number
                    AS disposed_by_employee_id,
                disposer.first_name
                    AS disposed_by_first_name,
                disposer.last_name
                    AS disposed_by_last_name

            FROM asset_disposals d

            INNER JOIN assets a
                ON a.id = d.asset_id

            LEFT JOIN users approver
                ON approver.id = d.approved_by

            INNER JOIN users disposer
                ON disposer.id = d.disposed_by

            ORDER BY
                d.disposal_date DESC,
                d.id DESC'
        );

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO asset_disposals (
                asset_id,
                previous_status_id,
                disposal_type,
                disposal_reason,
                description,
                disposal_date,
                approved_by,
                approved_at,
                disposed_by,
                disposal_reference,
                disposal_value,
                accumulated_depreciation,
                book_value_at_disposal,
                proceeds,
                disposal_status,
                remarks
            ) VALUES (
                :asset_id,
                :previous_status_id,
                :disposal_type,
                :disposal_reason,
                :description,
                :disposal_date,
                :approved_by,
                :approved_at,
                :disposed_by,
                :disposal_reference,
                :disposal_value,
                :accumulated_depreciation,
                :book_value_at_disposal,
                :proceeds,
                :disposal_status,
                :remarks
            )'
        );

        $stmt->execute([
            'asset_id' =>
                (int) $data['asset_id'],

            'previous_status_id' =>
                isset($data['previous_status_id'])
                ? (int) $data['previous_status_id']
                : null,

            'disposal_type' =>
                $data['disposal_type'],

            'disposal_reason' =>
                $data['disposal_reason'] ?? null,

            'description' =>
                $data['description'] ?? null,

            'disposal_date' =>
                $data['disposal_date'],

            'approved_by' =>
                $data['approved_by'] ?? null,

            'approved_at' =>
                $data['approved_at'] ?? null,

            'disposed_by' =>
                (int) $data['disposed_by'],

            'disposal_reference' =>
                $data['disposal_reference'] ?? null,

            'disposal_value' =>
                $data['disposal_value'] ?? 0,

            'accumulated_depreciation' =>
                $data['accumulated_depreciation'] ?? 0,

            'book_value_at_disposal' =>
                $data['book_value_at_disposal'] ?? 0,

            'proceeds' =>
                $data['proceeds'] ?? 0,

            'disposal_status' =>
                $data['disposal_status'] ?? 'pending',

            'remarks' =>
                $data['remarks'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }
    public function updateRejectionStatus(
        int $id,
        string $remarks,
        string $rejectionReason
    ): bool {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE asset_disposals
         SET
            disposal_status = :disposal_status,
            remarks = :remarks,
            rejection_reason = :rejection_reason
         WHERE
            id = :id
            AND disposal_status = :current_status'
        );

        $executed = $stmt->execute([
            'id' => $id,
            'disposal_status' => 'cancelled',
            'remarks' => $remarks,
            'rejection_reason' => $rejectionReason,
            'current_status' => 'pending',
        ]);

        return $executed && $stmt->rowCount() === 1;
    }

    public function update(
        int $id,
        array $data
    ): bool {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE asset_disposals
            SET
                disposal_type = :disposal_type,
                disposal_reason = :disposal_reason,
                description = :description,
                disposal_date = :disposal_date,
                approved_by = :approved_by,
                approved_at = :approved_at,
                disposed_by = :disposed_by,
                disposal_reference = :disposal_reference,
                disposal_value = :disposal_value,
                accumulated_depreciation =
                    :accumulated_depreciation,
                book_value_at_disposal =
                    :book_value_at_disposal,
                proceeds = :proceeds,
                disposal_status = :disposal_status,
                remarks = :remarks
            WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,

            'disposal_type' =>
                $data['disposal_type'],

            'disposal_reason' =>
                $data['disposal_reason'] ?? null,

            'description' =>
                $data['description'] ?? null,

            'disposal_date' =>
                $data['disposal_date'],

            'approved_by' =>
                $data['approved_by'] ?? null,

            'approved_at' =>
                $data['approved_at'] ?? null,

            'disposed_by' =>
                (int) $data['disposed_by'],

            'disposal_reference' =>
                $data['disposal_reference'] ?? null,

            'disposal_value' =>
                $data['disposal_value'] ?? 0,

            'accumulated_depreciation' =>
                $data['accumulated_depreciation'] ?? 0,

            'book_value_at_disposal' =>
                $data['book_value_at_disposal'] ?? 0,

            'proceeds' =>
                $data['proceeds'] ?? 0,

            'disposal_status' =>
                $data['disposal_status'],

            'remarks' =>
                $data['remarks'] ?? null,
        ]);
    }
}