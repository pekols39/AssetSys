<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetDocument
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
                ad.*,
                a.asset_code,
                a.asset_name,
                u.employee_number AS uploaded_by_employee_id
             FROM asset_documents ad
             INNER JOIN assets a
                ON a.id = ad.asset_id
             INNER JOIN users u
                ON u.id = ad.uploaded_by
             WHERE ad.id = :id
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
                ad.*,
                u.employee_number AS uploaded_by_employee_id
             FROM asset_documents ad
             INNER JOIN users u
                ON u.id = ad.uploaded_by
             WHERE ad.asset_id = :asset_id
             ORDER BY ad.created_at DESC, ad.id DESC"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO asset_documents (
                asset_id,
                document_type,
                original_name,
                stored_name,
                mime_type,
                file_size,
                description,
                uploaded_by
             ) VALUES (
                :asset_id,
                :document_type,
                :original_name,
                :stored_name,
                :mime_type,
                :file_size,
                :description,
                :uploaded_by
             )"
        );

        $stmt->execute([
            'asset_id' => $data['asset_id'],
            'document_type' => $data['document_type'],
            'original_name' => $data['original_name'],
            'stored_name' => $data['stored_name'],
            'mime_type' => $data['mime_type'],
            'file_size' => $data['file_size'],
            'description' => $data['description'] ?? null,
            'uploaded_by' => $data['uploaded_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM asset_documents
             WHERE id = :id"
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}