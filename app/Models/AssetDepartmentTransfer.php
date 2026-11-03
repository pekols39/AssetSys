<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetDepartmentTransfer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Create a pending department-transfer request.
     *
     * This method records the request only. It does not update the asset.
     * The calling service must validate the asset and run this insert
     * inside its transaction.
     */
    public function createPendingRequest(
        int $assetId,
        int $sourceDepartmentId,
        int $destinationDepartmentId,
        string $destinationDepartmentName,
        int $requestedBy,
        string $requestReason
    ): int {
        if (
            $assetId <= 0
            || $sourceDepartmentId <= 0
            || $destinationDepartmentId <= 0
            || $sourceDepartmentId === $destinationDepartmentId
            || $requestedBy <= 0
            || trim($destinationDepartmentName) === ''
            || trim($requestReason) === ''
        ) {
            throw new \InvalidArgumentException(
                'Invalid department-transfer request details.'
            );
        }

        $stmt = $this->db->prepare(
            "INSERT INTO asset_department_transfers (
                asset_id,
                source_department_id,
                destination_department_id,
                destination_department_name,
                requested_by,
                request_reason,
                request_status,
                reviewed_by,
                reviewed_at,
                review_notes
            ) VALUES (
                :asset_id,
                :source_department_id,
                :destination_department_id,
                :destination_department_name,
                :requested_by,
                :request_reason,
                'pending',
                NULL,
                NULL,
                NULL
            )"
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'source_department_id' => $sourceDepartmentId,
            'destination_department_id' => $destinationDepartmentId,
            'destination_department_name' => trim(
                $destinationDepartmentName
            ),
            'requested_by' => $requestedBy,
            'request_reason' => trim($requestReason),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Retrieve pending requests for Asset Manager review.
     */
    public function getPendingRequests(): array
    {
        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . " WHERE t.request_status = 'pending'
                ORDER BY t.requested_at ASC, t.id ASC"
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve requests submitted by one user.
     */
    public function getRequestsByRequester(
        int $requesterId
    ): array {
        if ($requesterId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' WHERE t.requested_by = :requester_id
                ORDER BY t.requested_at DESC, t.id DESC'
        );

        $stmt->execute([
            'requester_id' => $requesterId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record an approval or rejection.
     *
     * The calling service must run this within the same transaction
     * as any corresponding asset update.
     */
    public function updateDecision(
        int $requestId,
        int $reviewedBy,
        string $status,
        ?string $reviewNotes = null
    ): bool {
        if (
            $requestId <= 0
            || $reviewedBy <= 0
            || !in_array($status, ['approved', 'rejected'], true)
        ) {
            throw new \InvalidArgumentException(
                'Invalid department-transfer decision.'
            );
        }

        if (
            $status === 'rejected'
            && trim((string) $reviewNotes) === ''
        ) {
            throw new \InvalidArgumentException(
                'A reason is required when rejecting a transfer request.'
            );
        }

        $stmt = $this->db->prepare(
            "UPDATE asset_department_transfers
             SET request_status = :request_status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP,
                 review_notes = :review_notes,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :request_id
               AND request_status = 'pending'"
        );

        $stmt->execute([
            'request_status' => $status,
            'reviewed_by' => $reviewedBy,
            'review_notes' => $reviewNotes !== null
                ? trim($reviewNotes)
                : null,
            'request_id' => $requestId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Record a completed department transfer.
     *
     * The calling service must update the asset and insert this
     * history record within the same database transaction.
     */
    public function createCompletedTransfer(
        int $assetId,
        int $sourceDepartmentId,
        int $destinationDepartmentId,
        string $destinationDepartmentName,
        int $transferredBy,
        string $transferReason
    ): int {
        if (
            $assetId <= 0
            || $sourceDepartmentId <= 0
            || $destinationDepartmentId <= 0
            || $sourceDepartmentId === $destinationDepartmentId
            || $transferredBy <= 0
        ) {
            throw new \InvalidArgumentException(
                'Invalid completed department transfer details.'
            );
        }

        $stmt = $this->db->prepare(
            "INSERT INTO asset_department_transfers (
                asset_id,
                source_department_id,
                destination_department_id,
                destination_department_name,
                requested_by,
                request_reason,
                request_status,
                reviewed_by,
                reviewed_at,
                review_notes
            ) VALUES (
                :asset_id,
                :source_department_id,
                :destination_department_id,
                :destination_department_name,
                :transferred_by,
                :transfer_reason,
                'completed',
                :reviewed_by,
                CURRENT_TIMESTAMP,
                NULL
            )"
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'source_department_id' => $sourceDepartmentId,
            'destination_department_id' => $destinationDepartmentId,
            'destination_department_name' => $destinationDepartmentName,
            'transferred_by' => $transferredBy,
            'transfer_reason' => $transferReason,
            'reviewed_by' => $transferredBy,
        ]);

        return (int) $this->db->lastInsertId();
    }


    /**
     * Find a request for transactional updates.
     *
     * Call inside a transaction when locking is required.
     */
    public function findByIdForUpdate(
        int $id
    ): array|false {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM asset_department_transfers
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find a request with asset, department, requester,
     * and reviewer details.
     */
    public function findById(
        int $id
    ): ?array {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' WHERE t.id = :id
                LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        return $request !== false
            ? $request
            : null;
    }

    /**
     * Retrieve filtered department-transfer history with pagination.
     *
     * Search and status filters are applied before pagination.
     */
    public function getPaginatedRequests(
        ?int $requesterId,
        string $search,
        string $status,
        int $page,
        int $perPage
    ): array {
        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 10;

        $where = [];
        $params = [];

        if ($requesterId !== null) {
            $where[] = 't.requested_by = :requester_id';
            $params['requester_id'] = $requesterId;
        }

        if (
            $status !== '' && in_array(
                $status,
                ['pending', 'approved', 'rejected', 'cancelled', 'completed'],
                true
            )
        ) {
            $where[] = 't.request_status = :request_status';
            $params['request_status'] = $status;
        }

        $search = trim($search);

        if ($search !== '') {
            $searchFields = [
                'a.asset_code',
                'a.asset_tag',
                'a.asset_name',
                'source_department.name',
                'destination_department.name',
                't.destination_department_name',
                't.request_reason',
                'requester.first_name',
                'requester.last_name',
                'requester.username',
            ];

            $searchConditions = [];

            foreach ($searchFields as $index => $field) {
                $placeholder = ':search_' . $index;

                $searchConditions[] = $field . ' LIKE ' . $placeholder;

                $params['search_' . $index] = '%' . $search . '%';
            }

            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        $whereSql = $where !== []
            ? ' WHERE ' . implode(' AND ', $where)
            : '';

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM asset_department_transfers t
             INNER JOIN assets a ON a.id = t.asset_id
             LEFT JOIN departments source_department
                ON source_department.id = t.source_department_id
             LEFT JOIN departments destination_department
                ON destination_department.id = t.destination_department_id
             LEFT JOIN users requester
                ON requester.id = t.requested_by'
            . $whereSql
        );

        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($totalRecords / $perPage)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . $whereSql
            . ' ORDER BY t.requested_at DESC, t.id DESC
                LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                $name === 'requester_id'
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $totalRecords,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
    /**
     * Retrieve transfer-history summary counts.
     *
     * Optionally restrict counts to one requesting user.
     */
    public function getRequestSummary(?int $requesterId = null): array
    {
        $sql = "
        SELECT
            COUNT(*) AS total_transfers,
            COALESCE(SUM(request_status = 'completed'), 0) AS completed_count,
            COALESCE(SUM(request_status = 'approved'), 0) AS approved_count,
            COALESCE(SUM(request_status = 'rejected'), 0) AS rejected_count
        FROM asset_department_transfers
    ";

        $params = [];

        if ($requesterId !== null) {
            $sql .= ' WHERE requested_by = :requester_id';
            $params['requester_id'] = $requesterId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_transfers' => (int) ($summary['total_transfers'] ?? 0),
            'completed_count' => (int) ($summary['completed_count'] ?? 0),
            'approved_count' => (int) ($summary['approved_count'] ?? 0),
            'rejected_count' => (int) ($summary['rejected_count'] ?? 0),
        ];
    }

    /**
     * Retrieve all department-transfer requests for the Finance asset register.
     */
    public function getAllRequests(): array
    {
        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' ORDER BY t.requested_at DESC, t.id DESC'
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve the department-transfer history for an asset.
     *
     * The calling service must authorize access before returning it.
     */
    public function getHistoryByAssetId(
        int $assetId
    ): array {
        if ($assetId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            $this->detailsSelect()
            . ' WHERE t.asset_id = :asset_id
                ORDER BY t.requested_at ASC, t.id ASC'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Shared query for displaying request details.
     */
    private function detailsSelect(): string
    {
        return '
            SELECT
                t.*,

                a.asset_code,
                a.asset_tag,
                a.asset_name,

                source_department.name
                    AS source_department_name,

                COALESCE(
                    destination_department.name,
                    t.destination_department_name
                ) AS destination_department_name,

                requester.first_name
                    AS requester_first_name,

                requester.last_name
                    AS requester_last_name,

                requester.username
                    AS requester_username,

                reviewer.first_name
                    AS reviewer_first_name,

                reviewer.last_name
                    AS reviewer_last_name,

                reviewer.username
                    AS reviewer_username

            FROM asset_department_transfers t

            INNER JOIN assets a
                ON a.id = t.asset_id

            LEFT JOIN departments source_department
                ON source_department.id = t.source_department_id

            LEFT JOIN departments destination_department
                ON destination_department.id = t.destination_department_id

            LEFT JOIN users requester
                ON requester.id = t.requested_by

            LEFT JOIN users reviewer
                ON reviewer.id = t.reviewed_by
        ';
    }
}