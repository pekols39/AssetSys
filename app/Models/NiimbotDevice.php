<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class NiimbotDevice
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Authenticate a printer station using its individual registry credentials.
     * The legacy .env credential is enrolled automatically on first use.
     */
    public function authenticate(string $deviceId, string $token): bool
    {
        $deviceId = trim($deviceId);

        if (
            $deviceId !== 'property-custodian-b1'
            || !preg_match('/\A[a-f0-9]{64}\z/i', $token)
        ) {
            return false;
        }

        $device = $this->findByDeviceId($deviceId);

        if (!$device) {
            $legacyDeviceId = defined('NIIMBOT_AGENT_DEVICE_ID')
                ? NIIMBOT_AGENT_DEVICE_ID
                : '';
            $legacyHash = defined('NIIMBOT_AGENT_TOKEN_HASH')
                ? NIIMBOT_AGENT_TOKEN_HASH
                : '';

            // Enroll the existing production agent without changing its token.
            if (
                $legacyDeviceId === ''
                || !hash_equals($legacyDeviceId, $deviceId)
                || !preg_match('/\A[a-f0-9]{64}\z/i', $legacyHash)
                || !hash_equals(strtolower($legacyHash), hash('sha256', $token))
            ) {
                return false;
            }

            $insert = $this->db->prepare(
                'INSERT IGNORE INTO niimbot_devices (
                    device_id,
                    display_name,
                    token_hash,
                    is_enabled
                 ) VALUES (
                    :device_id,
                    :display_name,
                    :token_hash,
                    1
                 )'
            );

            $insert->execute([
                'device_id' => $deviceId,
                'display_name' => 'Property Custodian B1',
                'token_hash' => strtolower($legacyHash),
            ]);

            $device = $this->findByDeviceId($deviceId);
        }

        if (
            !$device
            || (int) ($device['is_enabled'] ?? 0) !== 1
            || !is_string($device['token_hash'] ?? null)
        ) {
            return false;
        }

        $providedHash = hash('sha256', $token);

        if (!hash_equals(strtolower($device['token_hash']), $providedHash)) {
            return false;
        }

        $seen = $this->db->prepare(
            'UPDATE niimbot_devices
             SET last_seen_at = NOW()
             WHERE id = :id
             LIMIT 1'
        );
        $seen->execute(['id' => (int) $device['id']]);

        return true;
    }

    private function findByDeviceId(string $deviceId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, token_hash, is_enabled
             FROM niimbot_devices
             WHERE device_id = :device_id
             LIMIT 1'
        );

        $stmt->execute(['device_id' => $deviceId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
