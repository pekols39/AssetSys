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
     * Add an authorized asset label request to the print queue.
     */
    public function create(
        int $assetId,
        int $qrCodeId,
        int $requestedBy,
        string $assetName,
        string $assetCode,
        ?string $assetTag,
        ?string $financeFfeNumber
    ): int {
        if ($assetId <= 0 || $qrCodeId <= 0 || $requestedBy <= 0) {
            throw new \InvalidArgumentException(
                'Valid asset, QR code, and requester IDs are required.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO niimbot_print_jobs (
                asset_id,
                asset_qr_code_id,
                requested_by,
                asset_name,
                asset_code,
                asset_tag,
                finance_ffe_number,
                status
            ) VALUES (
                :asset_id,
                :qr_code_id,
                :requested_by,
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
     * Requeue expired jobs and claim the oldest pending job.
     *
     * The caller must authenticate the local device before calling this.
     */
    public function claimNext(string $deviceId): array|false
    {
        if ($deviceId === '' || strlen($deviceId) > 100) {
            throw new \InvalidArgumentException('Invalid device ID.');
        }

        $this->db->beginTransaction();

        try {
            // Recover jobs whose agent stopped responding.
            $recover = $this->db->prepare(
                'UPDATE niimbot_print_jobs
                 SET status = \'pending\',
                     device_id = NULL,
                     claimed_at = NULL,
                     lease_expires_at = NULL
                 WHERE status = \'processing\'
                   AND lease_expires_at < NOW()
                   AND attempts < 3'
            );
            $recover->execute();

            // Expired jobs that exhausted their attempts are failed.
            $fail = $this->db->prepare(
                'UPDATE niimbot_print_jobs
                 SET status = \'failed\',
                     error_message = \'Print job exceeded the retry limit.\',
                     lease_expires_at = NULL
                 WHERE status = \'processing\'
                   AND lease_expires_at < NOW()
                   AND attempts >= 3'
            );
            $fail->execute();

            $select = $this->db->query(
                'SELECT id
                 FROM niimbot_print_jobs
                 WHERE status = \'pending\'
                   AND attempts < 3
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );

            $job = $select->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->db->commit();
                return false;
            }

            $update = $this->db->prepare(
                'UPDATE niimbot_print_jobs
                 SET status = \'processing\',
                     device_id = :device_id,
                     claimed_at = NOW(),
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE),
                     attempts = attempts + 1,
                     error_message = NULL
                 WHERE id = :id
                   AND status = \'pending\''
            );

            $update->execute([
                'device_id' => $deviceId,
                'id' => (int) $job['id'],
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Unable to claim print job.');
            }

            $result = $this->db->prepare(
                'SELECT
                    id,
                    asset_id,
                    asset_qr_code_id,
                    asset_name,
                    asset_code,
                    asset_tag,
                    finance_ffe_number,
                    attempts
                 FROM niimbot_print_jobs
                 WHERE id = :id
                 LIMIT 1'
            );

            $result->execute(['id' => (int) $job['id']]);
            $claimedJob = $result->fetch(PDO::FETCH_ASSOC);

            $this->db->commit();

            return $claimedJob;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Complete a job claimed by the specified device.
     */
    public function complete(int $jobId, string $deviceId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE niimbot_print_jobs
             SET status = \'completed\',
                 completed_at = NOW(),
                 lease_expires_at = NULL,
                 error_message = NULL
             WHERE id = :id
               AND status = \'processing\'
               AND device_id = :device_id'
        );

        $stmt->execute([
            'id' => $jobId,
            'device_id' => $deviceId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Mark a job as failed by the device that claimed it.
     */
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
