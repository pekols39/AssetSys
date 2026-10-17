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
            if ($isPrimary) {
                $this->clearPrimaryPhoto($assetId);
            }

            if (!$this->photoModel->getPrimaryByAssetId($assetId)) {
                $isPrimary = true;
            }

            return $this->photoModel->create([
                'asset_id' => $assetId,
                'original_name' => $upload['original_name'],
                'stored_name' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size'],
                'caption' => $this->normalizeOptionalString($caption),
                'is_primary' => $isPrimary ? 1 : 0,
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
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
            return $this->documentModel->create([
                'asset_id' => $assetId,
                'document_type' => $documentType,
                'original_name' => $upload['original_name'],
                'stored_name' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size'],
                'description' => $this->normalizeOptionalString($description),
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            $this->deleteStoredFile($upload['path']);

            throw $e;
        }
    }

    public function deletePhoto(int $photoId): void
    {
        if ($photoId <= 0) {
            throw new RuntimeException(
                'Invalid photo.'
            );
        }

        $photo = $this->photoModel->findById($photoId);

        if (!$photo) {
            throw new RuntimeException(
                'Photo not found.'
            );
        }

        $assetId = (int) $photo['asset_id'];

        if (!$this->authorization->canAccessAsset($assetId)) {
            throw new RuntimeException(
                'Access denied.'
            );
        }

        $storedName = basename(
            (string) $photo['stored_name']
        );

        if ($storedName === '') {
            throw new RuntimeException(
                'Invalid stored photo.'
            );
        }

        $photoPath =
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'uploads'
            . DIRECTORY_SEPARATOR
            . 'assets'
            . DIRECTORY_SEPARATOR
            . $storedName;

        $wasPrimary =
            (int) $photo['is_primary'] === 1;

        $this->photoModel->delete($photoId);

        if (is_file($photoPath)) {
            @unlink($photoPath);
        }

        if ($wasPrimary) {
            $remainingPhotos =
                $this->photoModel->getByAssetId($assetId);

            if (!empty($remainingPhotos)) {
                $nextPrimary = $remainingPhotos[0];

                $this->photoModel->setPrimary(
                    (int) $nextPrimary['id'],
                    $assetId
                );
            }
        }
    }

    public function deleteDocument(int $documentId): void
    {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Invalid document.'
            );
        }

        $document =
            $this->documentModel->findById($documentId);

        if (!$document) {
            throw new RuntimeException(
                'Document not found.'
            );
        }

        $assetId = (int) $document['asset_id'];

        if (!$this->authorization->canAccessAsset($assetId)) {
            throw new RuntimeException(
                'Access denied.'
            );
        }

        $storedName = basename(
            (string) $document['stored_name']
        );

        if ($storedName === '') {
            throw new RuntimeException(
                'Invalid stored document.'
            );
        }

        $documentPath =
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'uploads'
            . DIRECTORY_SEPARATOR
            . 'documents'
            . DIRECTORY_SEPARATOR
            . $storedName;

        $this->documentModel->delete(
            $documentId
        );

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