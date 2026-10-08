<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetPhoto
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                ap.*,
                a.asset_code,
                a.asset_name,
                u.employee_number AS uploaded_by_employee_id
             FROM asset_photos ap
             INNER JOIN assets a
                ON a.id = ap.asset_id
             INNER JOIN users u
                ON u.id = ap.uploaded_by
             WHERE ap.id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function getByAssetId(int $assetId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                ap.*,
                u.employee_number AS uploaded_by_employee_id
             FROM asset_photos ap
             INNER JOIN users u
                ON u.id = ap.uploaded_by
             WHERE ap.asset_id = :asset_id
             ORDER BY ap.is_primary DESC, ap.created_at DESC, ap.id DESC"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrimaryByAssetId(int $assetId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                ap.*,
                u.employee_number AS uploaded_by_employee_id
             FROM asset_photos ap
             INNER JOIN users u
                ON u.id = ap.uploaded_by
             WHERE ap.asset_id = :asset_id
               AND ap.is_primary = 1
             ORDER BY ap.id DESC
             LIMIT 1"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO asset_photos (
                asset_id,
                original_name,
                stored_name,
                mime_type,
                file_size,
                caption,
                is_primary,
                uploaded_by
             ) VALUES (
                :asset_id,
                :original_name,
                :stored_name,
                :mime_type,
                :file_size,
                :caption,
                :is_primary,
                :uploaded_by
             )"
        );

        $stmt->execute([
            'asset_id' => $data['asset_id'],
            'original_name' => $data['original_name'],
            'stored_name' => $data['stored_name'],
            'mime_type' => $data['mime_type'],
            'file_size' => $data['file_size'],
            'caption' => $data['caption'] ?? null,
            'is_primary' => $data['is_primary'] ?? 0,
            'uploaded_by' => $data['uploaded_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setPrimary(int $id, int $assetId): bool
    {
        $this->db->beginTransaction();

        try {
            $clearPrimary = $this->db->prepare(
                "UPDATE asset_photos
                 SET is_primary = 0
                 WHERE asset_id = :asset_id"
            );

            $clearPrimary->execute([
                'asset_id' => $assetId,
            ]);

            $setPrimary = $this->db->prepare(
                "UPDATE asset_photos
                 SET is_primary = 1
                 WHERE id = :id
                   AND asset_id = :asset_id"
            );

            $setPrimary->execute([
                'id' => $id,
                'asset_id' => $assetId,
            ]);

            $updated = $setPrimary->rowCount() > 0;

            $this->db->commit();

            return $updated;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM asset_photos
             WHERE id = :id"
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}