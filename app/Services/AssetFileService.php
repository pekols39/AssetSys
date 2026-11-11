<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\FileUpload;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetPhoto;
use RuntimeException;
use App\Services\AuthorizationService;

class AssetFileService
{
    private \PDO $db;
    private Asset $assetModel;
    private AssetPhoto $photoModel;
    private AssetDocument $documentModel;
    private AuthorizationService $authorization;

    private const PHOTO_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const DOCUMENT_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->photoModel = new AssetPhoto();
        $this->documentModel = new AssetDocument();
        $this->authorization = new AuthorizationService();
    }

    public function uploadPhoto(
        int $assetId,
        int $uploadedBy,
        array $file,
        ?string $caption = null,
        bool $isPrimary = false
    ): int {
        $this->validateAsset($assetId);
        $this->validateUser($uploadedBy);

        if (\App\Core\Auth::id() !== $uploadedBy) {
            throw new RuntimeException(
                'The uploader must match the authenticated user.'
            );
        }

        $upload = FileUpload::store(
            $file,
            'assets',
            self::PHOTO_MIME_TYPES
        );

        try {
            $this->db->beginTransaction();

            if ($isPrimary) {
                $this->clearPrimaryPhoto($assetId);
            }

            if (!$this->photoModel->getPrimaryByAssetId($assetId)) {
                $isPrimary = true;
            }

            $normalizedCaption = $this->normalizeOptionalString($caption);

            $photoId = $this->photoModel->create([
                'asset_id' => $assetId,
                'original_name' => $upload['original_name'],
                'stored_name' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size'],
                'caption' => $normalizedCaption,
                'is_primary' => $isPrimary ? 1 : 0,
                'uploaded_by' => $uploadedBy,
            ]);

            (new AuditLogService())->log(
                action: 'upload_photo',
                module: 'assets',
                entityType: 'asset_photo',
                entityId: $photoId,
                description: 'Asset photo uploaded.',
                newValues: [
                    'asset_id' => $assetId,
                    'original_name' => $upload['original_name'],
                    'mime_type' => $upload['mime_type'],
                    'file_size' => $upload['size'],
                    'is_primary' => $isPrimary,
                    'caption' => $normalizedCaption,
                ]
            );

            $this->db->commit();

            return $photoId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->deleteStoredFile($upload['path']);

            throw $e;
        }
    }

    public function uploadDocument(
        int $assetId,
        int $uploadedBy,
        array $file,
        string $documentType,
        ?string $description = null
    ): int {
        $this->validateAsset($assetId);
        $this->validateUser($uploadedBy);

        if (\App\Core\Auth::id() !== $uploadedBy) {
            throw new RuntimeException(
                'The uploader must match the authenticated user.'
            );
        }

        $documentType = trim($documentType);

        if ($documentType === '') {
            throw new RuntimeException(
                'Document type is required.'
            );
        }

        if (mb_strlen($documentType) > 100) {
            throw new RuntimeException(
                'Document type must not exceed 100 characters.'
            );
        }

        $upload = FileUpload::store(
            $file,
            'documents',
            self::DOCUMENT_MIME_TYPES
        );

        try {
            $this->db->beginTransaction();

            $normalizedDescription =
                $this->normalizeOptionalString($description);

            $documentId = $this->documentModel->create([
                'asset_id' => $assetId,
                'document_type' => $documentType,
                'original_name' => $upload['original_name'],
                'stored_name' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size'],
                'description' => $normalizedDescription,
                'uploaded_by' => $uploadedBy,
            ]);

            (new AuditLogService())->log(
                action: 'upload_document',
                module: 'assets',
                entityType: 'asset_document',
                entityId: $documentId,
                description: 'Asset document uploaded.',
                newValues: [
                    'asset_id' => $assetId,
                    'document_type' => $documentType,
                    'original_name' => $upload['original_name'],
                    'mime_type' => $upload['mime_type'],
                    'file_size' => $upload['size'],
                    'description' => $normalizedDescription,
                ]
            );

            $this->db->commit();

            return $documentId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->deleteStoredFile($upload['path']);

            throw $e;
        }
    }

    public function deletePhoto(int $photoId): void
    {
        if ($photoId <= 0) {
            throw new RuntimeException('Invalid photo.');
        }

        $photo = $this->photoModel->findById($photoId);

        if (!$photo) {
            throw new RuntimeException('Photo not found.');
        }

        $assetId = (int) $photo['asset_id'];

        if (!$this->authorization->canAccessAsset($assetId)) {
            throw new RuntimeException('Access denied.');
        }

        $storedName = basename((string) $photo['stored_name']);

        if ($storedName === '') {
            throw new RuntimeException('Invalid stored photo.');
        }

        $photoPath =
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . $storedName;

        $wasPrimary = (int) $photo['is_primary'] === 1;

        $this->db->beginTransaction();

        try {
            $deleted = $this->photoModel->delete($photoId);

            if (!$deleted) {
                throw new RuntimeException('Unable to delete photo record.');
            }

            if ($wasPrimary) {
                $remainingPhotos = $this->photoModel->getByAssetId($assetId);

                if (!empty($remainingPhotos)) {
                    $nextPrimary = $remainingPhotos[0];

                    $this->photoModel->setPrimary(
                        (int) $nextPrimary['id'],
                        $assetId
                    );
                }
            }

            (new AuditLogService())->log(
                action: 'delete_photo',
                module: 'assets',
                entityType: 'asset_photo',
                entityId: $photoId,
                description: 'Asset photo deleted.',
                oldValues: [
                    'asset_id' => $assetId,
                    'original_name' => $photo['original_name'] ?? null,
                    'mime_type' => $photo['mime_type'] ?? null,
                    'file_size' => $photo['file_size'] ?? null,
                    'is_primary' => $wasPrimary,
                    'caption' => $photo['caption'] ?? null,
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        if (is_file($photoPath)) {
            @unlink($photoPath);
        }
    }

    public function deleteDocument(int $documentId): void
    {
        if ($documentId <= 0) {
            throw new RuntimeException('Invalid document.');
        }

        $document = $this->documentModel->findById($documentId);

        if (!$document) {
            throw new RuntimeException('Document not found.');
        }

        $assetId = (int) $document['asset_id'];

        if (!$this->authorization->canAccessAsset($assetId)) {
            throw new RuntimeException('Access denied.');
        }

        $storedName = basename((string) $document['stored_name']);

        if ($storedName === '') {
            throw new RuntimeException('Invalid stored document.');
        }

        $documentPath =
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'documents'
            . DIRECTORY_SEPARATOR . $storedName;

        $this->db->beginTransaction();

        try {
            $deleted = $this->documentModel->delete($documentId);

            if (!$deleted) {
                throw new RuntimeException('Unable to delete document record.');
            }

            (new AuditLogService())->log(
                action: 'delete_document',
                module: 'assets',
                entityType: 'asset_document',
                entityId: $documentId,
                description: 'Asset document deleted.',
                oldValues: [
                    'asset_id' => $assetId,
                    'document_type' => $document['document_type'] ?? null,
                    'original_name' => $document['original_name'] ?? null,
                    'mime_type' => $document['mime_type'] ?? null,
                    'file_size' => $document['file_size'] ?? null,
                    'description' => $document['description'] ?? null,
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        if (is_file($documentPath)) {
            @unlink($documentPath);
        }
    }
    private function validateAsset(int $assetId): void
    {
        if ($assetId <= 0) {
            throw new RuntimeException(
                'Invalid asset.'
            );
        }

        if (!$this->assetModel->findById($assetId)) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        if (!$this->authorization->canAccessAsset($assetId)) {
            throw new RuntimeException(
                'Access denied.'
            );
        }
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException(
                'Invalid user.'
            );
        }

        $stmt = $this->db->prepare(
            "SELECT id
         FROM users
         WHERE id = :id
           AND account_status = 'active'
           AND is_active = 1
         LIMIT 1"
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'User account is not active.'
            );
        }
    }

    private function clearPrimaryPhoto(int $assetId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE asset_photos
             SET is_primary = 0
             WHERE asset_id = :asset_id"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);
    }

    private function normalizeOptionalString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function deleteStoredFile(string $relativePath): void
    {
        $relativePath = ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath),
            DIRECTORY_SEPARATOR
        );

        $root = realpath(ASSETSYS_ROOT);

        if ($root === false) {
            return;
        }

        $fullPath = $root
            . DIRECTORY_SEPARATOR
            . $relativePath;

        $realDirectory = realpath(dirname($fullPath));

        if (
            $realDirectory === false
            || !is_file($fullPath)
        ) {
            return;
        }

        $allowedDirectory = realpath(
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'uploads'
        );

        if (
            $allowedDirectory === false
            || !str_starts_with(
                $realDirectory . DIRECTORY_SEPARATOR,
                $allowedDirectory . DIRECTORY_SEPARATOR
            )
        ) {
            return;
        }

        @unlink($fullPath);
    }
}