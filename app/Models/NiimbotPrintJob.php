<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

class NiimbotPrintJob
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Add an authorized asset label request to the station-specific queue.
     */
    public function create(
        int $assetId,
        int $qrCodeId,
        int $requestedBy,
        string $targetDeviceId,
        string $assetName,
        string $assetCode,
        ?string $assetTag,
        ?string $financeFfeNumber
    ): int {
        if (
            $assetId <= 0
            || $qrCodeId <= 0
            || $requestedBy <= 0
            || !in_array($targetDeviceId, ['property-custodian-b1', 'it-laptop-b1'], true)
        ) {
            throw new \InvalidArgumentException(
                'Valid asset, QR code, requester, and printer station are required.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO niimbot_print_jobs (
                asset_id,
                asset_qr_code_id,
                requested_by,
                target_device_id,
                asset_name,
                asset_code,
                asset_tag,
                finance_ffe_number,
                status
            ) VALUES (
                :asset_id,
                :qr_code_id,
                :requested_by,
                :target_device_id,
                :asset_name,
                :asset_code,
                :asset_tag,
                :finance_ffe_number,
                \'pending\'
            )'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'qr_code_id' => $qrCodeId,
            'requested_by' => $requestedBy,
            'target_device_id' => $targetDeviceId,
            'asset_name' => $assetName,
            'asset_code' => $assetCode,
            'asset_tag' => $assetTag,
            'finance_ffe_number' => $financeFfeNumber,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a print job belonging to the requesting user.
     */
    public function findForUser(int $jobId, int $userId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                asset_id,
                asset_name,
                asset_code,
                asset_tag,
                status,
                attempts,
                error_message,
                created_at,
                completed_at
             FROM niimbot_print_jobs
             WHERE id = :id
               AND requested_by = :user_id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $jobId,
            'user_id' => $userId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically claim up to five jobs assigned to this authenticated device.
     * Expired jobs are failed for operator review, never automatically requeued:
     * the physical printer may have printed before the agent lost contact.
     */
    public function claimBatch(string $deviceId, int $limit = 5): array
    {
        if (!in_array($deviceId, ['property-custodian-b1', 'it-laptop-b1'], true)) {
            throw new \InvalidArgumentException('Invalid printer device ID.');
        }

        $limit = max(1, min(5, $limit));
        $this->db->beginTransaction();

        try {
            // Only reconcile expired jobs belonging to this station.
            // Never auto-requeue an expired physical print: its outcome is unknown
            // and automatic retry could create a duplicate label.
            $recover = $this->db->prepare(
                'UPDATE niimbot_print_jobs
                 SET status = \'failed\',
                     error_message = \'Printer lease expired; physical print outcome is unknown. Verify the label before manually requesting another print.\',
                     lease_expires_at = NULL
                 WHERE status = \'processing\'
                   AND lease_expires_at IS NOT NULL
                   AND lease_expires_at < NOW()
                   AND target_device_id = :target_device_id
                   AND device_id = :device_id'
            );
            $recover->execute([
                'target_device_id' => $deviceId,
                'device_id' => $deviceId,
            ]);

            $select = $this->db->prepare(
                'SELECT id
                 FROM niimbot_print_jobs
                 WHERE status = \'pending\'
                   AND attempts < 3
                   AND target_device_id = :device_id
                 ORDER BY created_at ASC, id ASC
                 LIMIT ' . $limit . '
                 FOR UPDATE'
            );
            $select->execute(['device_id' => $deviceId]);
            $rows = $select->fetchAll(PDO::FETCH_COLUMN);

            if (!$rows) {
                $this->db->commit();
                return [];
            }

            $ids = array_map('intval', $rows);
            $idPlaceholders = [];
            $params = [
                'device_id' => $deviceId,
                'target_device_id' => $deviceId,
            ];

            foreach ($ids as $index => $id) {
                $key = 'id_' . $index;
                $idPlaceholders[] = ':' . $key;
                $params[$key] = $id;
            }

            $update = $this->db->prepare(
                'UPDATE niimbot_print_jobs
                 SET status = \'processing\',
                     device_id = :device_id,
                     claimed_at = NOW(),
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                     attempts = attempts + 1,
                     error_message = NULL
                 WHERE status = \'pending\'
                   AND target_device_id = :target_device_id
                   AND id IN (' . implode(', ', $idPlaceholders) . ')'
            );
            $update->execute($params);

            if ($update->rowCount() !== count($ids)) {
                throw new RuntimeException('Unable to claim the complete print batch.');
            }

            $fetchParams = [];
            $fetchPlaceholders = [];

            foreach ($ids as $index => $id) {
                $key = 'fetch_id_' . $index;
                $fetchPlaceholders[] = ':' . $key;
                $fetchParams[$key] = $id;
            }

            $result = $this->db->prepare(
                'SELECT
                    j.id,
                    j.asset_id,
                    j.asset_qr_code_id,
                    j.asset_name,
                    j.asset_code,
                    j.asset_tag,
                    j.finance_ffe_number,
                    a.finance_owner_department,
                    j.attempts
                 FROM niimbot_print_jobs j
                 LEFT JOIN assets a
                    ON a.id = j.asset_id
                 WHERE j.id IN (' . implode(', ', $fetchPlaceholders) . ')
                   AND j.device_id = :device_id
                   AND j.status = \'processing\'
                 ORDER BY j.created_at ASC, j.id ASC'
            );
            $fetchParams['device_id'] = $deviceId;
            $result->execute($fetchParams);
            $claimedJobs = $result->fetchAll(PDO::FETCH_ASSOC);

            $this->db->commit();

            return $claimedJobs;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Extend all active leases held by this authenticated printer station.
     */
    public function renewActiveLeases(string $deviceId): int
    {
        if (!in_array($deviceId, ['property-custodian-b1', 'it-laptop-b1'], true)) {
            throw new \InvalidArgumentException('Invalid printer device ID.');
        }

        $stmt = $this->db->prepare(
            'UPDATE niimbot_print_jobs
             SET lease_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
             WHERE status = \'processing\'
               AND device_id = :device_id'
        );
        $stmt->execute(['device_id' => $deviceId]);

        return $stmt->rowCount();
    }

    /**
     * Return jobs that have not started printing to the pending queue.
     * Only IDs in the current batch and still owned by this station are released.
     */
    public function releaseUnstarted(array $jobIds, string $deviceId): int
    {
        if (!in_array($deviceId, ['property-custodian-b1', 'it-laptop-b1'], true)) {
            throw new \InvalidArgumentException('Invalid printer device ID.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return 0;
        }

        $placeholders = [];
        $params = ['device_id' => $deviceId];

        foreach ($ids as $index => $id) {
            $key = 'release_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $this->db->prepare(
            'UPDATE niimbot_print_jobs
             SET status = \'pending\',
                 device_id = NULL,
                 claimed_at = NULL,
                 lease_expires_at = NULL,
                 attempts = GREATEST(attempts - 1, 0),
                 error_message = NULL
             WHERE status = \'processing\'
               AND device_id = :device_id
               AND target_device_id = :device_id
               AND id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function complete(int $jobId, string $deviceId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE niimbot_print_jobs
             SET status = \'completed\',
                 completed_at = COALESCE(completed_at, NOW()),
                 lease_expires_at = NULL,
                 error_message = NULL
             WHERE id = :id
               AND device_id = :device_id
               AND status IN (\'processing\', \'completed\')'
        );

        $stmt->execute([
            'id' => $jobId,
            'device_id' => $deviceId,
        ]);

        if ($stmt->rowCount() === 1) {
            return true;
        }

        // MySQL may report zero changed rows for an already-completed job.
        $check = $this->db->prepare(
            'SELECT 1
             FROM niimbot_print_jobs
             WHERE id = :id
               AND device_id = :device_id
               AND status = \'completed\'
             LIMIT 1'
        );
        $check->execute([
            'id' => $jobId,
            'device_id' => $deviceId,
        ]);

        return (bool) $check->fetchColumn();
    }

    public function fail(
        int $jobId,
        string $deviceId,
        string $errorMessage
    ): bool {
        $errorMessage = trim($errorMessage);

        if ($errorMessage === '') {
            $errorMessage = 'The local printer agent reported an error.';
        }

        $errorMessage = mb_substr($errorMessage, 0, 500);

        $stmt = $this->db->prepare(
            'UPDATE niimbot_print_jobs
             SET status = \'failed\',
                 error_message = :error_message,
                 lease_expires_at = NULL
             WHERE id = :id
               AND status = \'processing\'
               AND device_id = :device_id'
        );

        $stmt->execute([
            'error_message' => $errorMessage,
            'id' => $jobId,
            'device_id' => $deviceId,
        ]);

        return $stmt->rowCount() === 1;
    }
}
