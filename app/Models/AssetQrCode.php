<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetQrCode
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Find the QR record associated with an asset.
     */
    public function findByAssetId(int $assetId): array|false
    {
        if ($assetId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT
                id,
                asset_id,
                qr_token,
                is_active,
                created_by,
                created_at,
                updated_at
             FROM asset_qr_codes
             WHERE asset_id = :asset_id
             LIMIT 1'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find a QR record by its opaque token.
     *
     * Inactive tokens are not returned.
     */
    public function findActiveByToken(string $token): array|false
    {
        if (
            strlen($token) !== 64
            || !ctype_xdigit($token)
        ) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT
                id,
                asset_id,
                qr_token,
                is_active,
                created_by,
                created_at,
                updated_at
             FROM asset_qr_codes
             WHERE qr_token = :qr_token
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'qr_token' => $token,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a QR record for an asset.
     *
     * Token generation and authorization belong in the service.
     */
    public function create(
        int $assetId,
        string $token,
        int $createdBy
    ): int {
        if ($assetId <= 0 || $createdBy <= 0) {
            throw new \InvalidArgumentException(
                'A valid asset and creator are required.'
            );
        }

        if (
            strlen($token) !== 64
            || !ctype_xdigit($token)
        ) {
            throw new \InvalidArgumentException(
                'The QR token format is invalid.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO asset_qr_codes (
                asset_id,
                qr_token,
                is_active,
                created_by
            ) VALUES (
                :asset_id,
                :qr_token,
                1,
                :created_by
            )'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'qr_token' => strtolower($token),
            'created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
