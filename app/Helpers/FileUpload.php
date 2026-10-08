<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

class FileUpload
{
    public static function store(
        array $file,
        string $directory,
        array $allowedMimeTypes
    ): array {
        self::validateUpload($file);

        $maxSize = defined('UPLOAD_MAX_SIZE')
            ? UPLOAD_MAX_SIZE
            : 10485760;

        if ((int) $file['size'] > $maxSize) {
            throw new RuntimeException(
                'The uploaded file exceeds the maximum allowed size.'
            );
        }

        $tmpName = (string) $file['tmp_name'];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mimeType = $finfo->file($tmpName);

        if (
            $mimeType === false
            || !isset($allowedMimeTypes[$mimeType])
        ) {
            throw new RuntimeException(
                'The uploaded file type is not allowed.'
            );
        }

        $extension = $allowedMimeTypes[$mimeType];

        $filename = bin2hex(random_bytes(32))
            . '.'
            . $extension;

        $allowedDirectories = [
            'assets',
            'documents',
        ];

        if (!in_array($directory, $allowedDirectories, true)) {
            throw new RuntimeException(
                'Invalid upload directory.'
            );
        }

        $destinationDirectory =
            ASSETSYS_ROOT
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'uploads'
            . DIRECTORY_SEPARATOR
            . $directory;

        if (!is_dir($destinationDirectory)) {
            if (!mkdir($destinationDirectory, 0750, true)) {
                throw new RuntimeException(
                    'Unable to create the upload directory.'
                );
            }
        }

        $destination = $destinationDirectory
            . DIRECTORY_SEPARATOR
            . $filename;

        if (
            !move_uploaded_file(
                $tmpName,
                $destination
            )
        ) {
            throw new RuntimeException(
                'Unable to save the uploaded file.'
            );
        }

        @chmod($destination, 0640);

        return [
            'filename' => $filename,
            'original_name' => (string) $file['name'],
            'mime_type' => $mimeType,
            'size' => (int) $file['size'],
            'path' => 'storage/uploads/'
                . $directory
                . '/'
                . $filename,
        ];
    }

    private static function validateUpload(
        array $file
    ): void {
        if (
            !isset(
            $file['error'],
            $file['tmp_name'],
            $file['name'],
            $file['size']
        )
        ) {
            throw new RuntimeException(
                'Invalid upload data.'
            );
        }

        if (
            (int) $file['error']
            !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException(
                self::uploadErrorMessage(
                    (int) $file['error']
                )
            );
        }

        if (
            !is_uploaded_file(
                (string) $file['tmp_name']
            )
        ) {
            throw new RuntimeException(
                'Invalid uploaded file.'
            );
        }

        if ((int) $file['size'] <= 0) {
            throw new RuntimeException(
                'The uploaded file is empty.'
            );
        }
    }

    private static function uploadErrorMessage(
        int $error
    ): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'The uploaded file is too large.',

            UPLOAD_ERR_PARTIAL =>
                'The uploaded file was only partially uploaded.',

            UPLOAD_ERR_NO_FILE =>
                'No file was uploaded.',

            UPLOAD_ERR_NO_TMP_DIR =>
                'The server upload directory is missing.',

            UPLOAD_ERR_CANT_WRITE =>
                'The server could not save the uploaded file.',

            UPLOAD_ERR_EXTENSION =>
                'The upload was blocked by a server extension.',

            default =>
                'The file upload failed.',
        };
    }
}