<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Models\AssetDocument;
use App\Models\AssetPhoto;
use App\Services\AuthorizationService;

try {
    AuthMiddleware::handle();

    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$id || $id <= 0) {
        http_response_code(400);
        exit('Invalid file ID.');
    }

    if (!in_array($type, ['photo', 'document'], true)) {
        http_response_code(400);
        exit('Invalid file type.');
    }

    $file = null;

    if ($type === 'photo') {
        $model = new AssetPhoto();
        $file = $model->findById((int) $id);
    } else {
        $model = new AssetDocument();
        $file = $model->findById((int) $id);
    }

    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }

    $authorization = new AuthorizationService();

    if (
        !$authorization->canAccessAsset(
            (int) ($file['asset_id'] ?? 0)
        )
    ) {
        http_response_code(403);
        exit('Access denied.');
    }

    $storedName = basename((string) ($file['stored_name'] ?? ''));

    if ($storedName === '') {
        http_response_code(404);
        exit('File not found.');
    }

    $subdirectory = $type === 'photo'
        ? 'assets'
        : 'documents';

    $storageRoot = realpath(
        ASSETSYS_ROOT
        . DIRECTORY_SEPARATOR
        . 'storage'
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . $subdirectory
    );

    if ($storageRoot === false) {
        http_response_code(404);
        exit('File storage is unavailable.');
    }

    $filePath = $storageRoot
        . DIRECTORY_SEPARATOR
        . $storedName;

    if (!is_file($filePath)) {
        http_response_code(404);
        exit('File not found.');
    }

    $realFilePath = realpath($filePath);

    if (
        $realFilePath === false
        || !str_starts_with(
            $realFilePath,
            $storageRoot . DIRECTORY_SEPARATOR
        )
    ) {
        http_response_code(403);
        exit('Access denied.');
    }

    $mimeType = (string) ($file['mime_type'] ?? '');

    if ($mimeType === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($realFilePath);

        if ($detectedMime === false) {
            $mimeType = 'application/octet-stream';
        } else {
            $mimeType = $detectedMime;
        }
    }

    $disposition = $type === 'photo'
        ? 'inline'
        : 'attachment';

    $originalName = (string) (
        $file['original_name'] ?? $storedName
    );

    $originalName = str_replace(
        ["\r", "\n", '"'],
        '',
        $originalName
    );

    header('Content-Type: ' . $mimeType);
    header(
        'Content-Length: '
        . (string) filesize($realFilePath)
    );
    $preview =
        isset($_GET['preview'])
        && (string) $_GET['preview'] === '1';

    $downloadName =
        trim((string) $file['original_name']);

    if ($downloadName === '') {
        $downloadName = 'AssetSys File';
    }

    $downloadName =
        preg_replace(
            '/[\x00-\x1F\x7F"\\\\]/',
            '',
            $downloadName
        );

    if ($downloadName === '') {
        $downloadName = 'AssetSys File';
    }

    if ($type === 'photo') {

        header(
            'Content-Type: '
            . $mimeType
        );

        header(
            'Content-Disposition: inline; filename="'
            . $downloadName
            . '"'
        );

    } elseif (
        $preview
        && $mimeType === 'application/pdf'
    ) {

        header(
            'Content-Type: application/pdf'
        );

        header(
            'Content-Disposition: inline; filename="'
            . $downloadName
            . '"'
        );

    } else {

        header(
            'Content-Type: '
            . $mimeType
        );

        header(
            'Content-Disposition: attachment; filename="'
            . $downloadName
            . '"'
        );
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    readfile($realFilePath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to access the requested file.');
}