<?php

declare(strict_types=1);

namespace App\\Services;

use App\\Core\\Database;
use PDO;
use RuntimeException;
use Throwable;

class DatabaseBackupService
{
    private PDO $db;
    private string $backupDirectory;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->backupDirectory = ASSETSYS_ROOT . '/storage/backups';
    }

    /**
     * Create a compressed, line-oriented JSON backup using PDO.
     * The format is streamed so the complete database is not held in memory.
     */
    public function createBackup(): array
    {
        if (!extension_loaded('zlib')) {
            throw new RuntimeException('PHP zlib extension is required for database backups.');
        }

        if (
            !is_dir($this->backupDirectory)
            && !mkdir($this->backupDirectory, 0700, true)
            && !is_dir($this->backupDirectory)
        ) {
            throw new RuntimeException('Unable to create the protected backup directory.');
        }

        if (!is_writable($this->backupDirectory)) {
            throw new RuntimeException('The backup directory is not writable.');
        }

        $filename = 'assetsys-db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl.gz';
        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $filename;
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the backup file.');
        }

        $bytesWritten = 0;

        try {
            $this->writeRecord($handle, [
                'record' => 'header',
                'format' => 'assetsys-pdo-backup',
                'format_version' => 1,
                'database' => DB_DATABASE,
                'created_at' => date(DATE_ATOM),
                'php_version' => PHP_VERSION,
                'server_version' => (string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION),
            ], $bytesWritten);

            $tables = $this->db->query(
                "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_NUM);

            foreach ($tables as $tableRow) {
                $table = (string) ($tableRow[0] ?? '');

                if ($table === '') {
                    continue;
                }

                $quotedTable = $this->quoteIdentifier($table);
                $create = $this->db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);

                if (!is_array($create) || !isset($create['Create Table'])) {
                    throw new RuntimeException('Unable to read table definition: ' . $table);
                }

                $this->writeRecord($handle, [
                    'record' => 'table',
                    'name' => $table,
                    'create_sql' => $create['Create Table'],
                ], $bytesWritten);

                $select = $this->db->query('SELECT * FROM ' . $quotedTable);

                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $this->writeRecord($handle, [
                        'record' => 'row',
                        'table' => $table,
                        'data' => $row,
                    ], $bytesWritten);
                }
            }

            $triggers = $this->db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);

            foreach ($triggers as $trigger) {
                $triggerName = (string) ($trigger['Trigger'] ?? '');

                if ($triggerName === '') {
                    continue;
                }

                $quotedTrigger = $this->quoteIdentifier($triggerName);
                $definition = $this->db->query('SHOW CREATE TRIGGER ' . $quotedTrigger)->fetch(PDO::FETCH_ASSOC);
                $createSql = $definition['SQL Original Statement'] ?? null;

                if (!is_string($createSql) || trim($createSql) === '') {
                    throw new RuntimeException('Unable to read trigger definition: ' . $triggerName);
                }

                $this->writeRecord($handle, [
                    'record' => 'trigger',
                    'name' => $triggerName,
                    'create_sql' => $createSql,
                ], $bytesWritten);
            }

            $this->writeRecord($handle, ['record' => 'end'], $bytesWritten);

            if (!gzclose($handle)) {
                throw new RuntimeException('Unable to finalize the compressed backup.');
            }

            $handle = null;
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                gzclose($handle);
            }

            if (is_file($path)) {
                @unlink($path);
            }

            throw $e;
        }

        clearstatcache(true, $path);

        return [
            'filename' => $filename,
            'path' => $path,
            'size' => (int) filesize($path),
            'created_at' => date(DATE_ATOM),
        ];
    }

    /**
     * Return backup metadata without exposing filesystem paths.
     */
    public function getBackups(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return [];
        }

        $items = [];

        foreach (glob($this->backupDirectory . DIRECTORY_SEPARATOR . 'assetsys-db-*.jsonl.gz') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $items[] = [
                'filename' => basename($path),
                'size' => (int) filesize($path),
                'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $items;
    }

    /**
     * Resolve only generated backup filenames inside the protected directory.
     */
    public function resolveBackup(string $filename): string
    {
        if (!preg_match('/\\Aassetsys-db-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\\.jsonl\\.gz\\z/', $filename)) {
            throw new RuntimeException('Invalid backup filename.');
        }

        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('The requested backup file is unavailable.');
        }

        return $path;
    }

    private function writeRecord($handle, array $record, int &$bytesWritten): void
    {
        $json = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $written = gzwrite($handle, $json . "\\n");

        if ($written === false) {
            throw new RuntimeException('A backup record could not be written.');
        }

        $bytesWritten += $written;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
