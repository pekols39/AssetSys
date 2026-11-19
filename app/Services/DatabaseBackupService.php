<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
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
     * Stream a plain SQL dump so large databases are not held in memory.
     */
    public function createBackup(): array
    {
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

        $filename = 'assetsys-db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $filename;
        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the SQL backup file.');
        }

        // SQL dumps contain sensitive company and user data. Restrict new
        // backup files to the process owner wherever filesystem permissions
        // are supported (for example, Linux production hosting).
        if (!chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('Unable to secure the SQL backup file permissions.');
        }

        $transactionStarted = false;

        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $transactionStarted = true;

            $this->write($handle, "-- AssetSys database backup\n");
            $this->write($handle, "-- Database: " . $this->safeComment((string) DB_DATABASE) . "\n");
            $this->write($handle, "-- Generated: " . date(DATE_ATOM) . "\n");
            $this->write($handle, "-- PHP: " . PHP_VERSION . "\n");
            $this->write($handle, "-- MySQL/MariaDB: " . $this->safeComment((string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION)) . "\n\n");
            $this->write($handle, "SET NAMES utf8mb4;\n");
            $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = $this->db->query(
                "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_NUM);
            $foreignKeyStatements = [];

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

                // Create every table before adding foreign keys. This avoids
                // dependency-order failures and supports circular relationships.
                $createLines = preg_split('/\r\n|\n|\r/', $create['Create Table']);
                $tableDefinitionLines = [];

                foreach ($createLines as $createLine) {
                    if (preg_match('/^\s*CONSTRAINT\s+.+\s+FOREIGN KEY\s+/i', $createLine)) {
                        $foreignKeyStatements[] = 'ALTER TABLE ' . $quotedTable
                            . ' ADD ' . rtrim(trim($createLine), ',') . ';';
                        continue;
                    }

                    $tableDefinitionLines[] = $createLine;
                }

                $tableDefinition = implode("\n", $tableDefinitionLines);
                $tableDefinition = preg_replace('/,(\s*\))/u', '$1', $tableDefinition) ?? $tableDefinition;

                $this->write($handle, "-- --------------------------------------------------------\n");
                $this->write($handle, "-- Table structure for " . $this->safeComment($table) . "\n");
                $this->write($handle, "-- --------------------------------------------------------\n");
                $this->write($handle, "DROP TABLE IF EXISTS " . $quotedTable . ";\n");
                $this->write($handle, $tableDefinition . ";\n\n");

                $columns = $this->db->query('SHOW FULL COLUMNS FROM ' . $quotedTable)
                    ->fetchAll(PDO::FETCH_ASSOC);
                $binaryColumns = [];

                foreach ($columns as $column) {
                    $type = strtolower((string) ($column['Type'] ?? ''));
                    $name = (string) ($column['Field'] ?? '');

                    if ($name !== '' && preg_match('/^(binary|varbinary|bit|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\\b/', $type)) {
                        $binaryColumns[$name] = true;
                    }
                }

                $insertableColumns = [];

                foreach ($columns as $column) {
                    $columnName = (string) ($column['Field'] ?? '');
                    $extra = strtoupper((string) ($column['Extra'] ?? ''));

                    if (
                        $columnName !== ''
                        && !str_contains($extra, 'VIRTUAL GENERATED')
                        && !str_contains($extra, 'STORED GENERATED')
                        && !str_contains($extra, 'INVISIBLE')
                    ) {
                        $insertableColumns[$columnName] = true;
                    }
                }

                $select = $this->db->query('SELECT * FROM ' . $quotedTable);
                $columnNames = array_map(
                    fn (string $column): string => $this->quoteIdentifier($column),
                    array_keys($insertableColumns)
                );

                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];

                    foreach ($row as $column => $value) {
                        if (!isset($insertableColumns[$column])) {
                            continue;
                        }

                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (isset($binaryColumns[$column])) {
                            $values[] = '0x' . bin2hex((string) $value);
                        } else {
                            $quotedValue = $this->db->quote((string) $value);

                            if ($quotedValue === false) {
                                throw new RuntimeException('Unable to encode a value in table: ' . $table);
                            }

                            $values[] = $quotedValue;
                        }
                    }

                    $this->write(
                        $handle,
                        'INSERT INTO ' . $quotedTable
                        . ' (' . implode(', ', $columnNames) . ') VALUES ('
                        . implode(', ', $values) . ");\n"
                    );
                }

                $this->write($handle, "\n");
            }

            foreach ($foreignKeyStatements as $foreignKeyStatement) {
                $this->write($handle, $foreignKeyStatement . "\n");
            }

            if ($foreignKeyStatements !== []) {
                $this->write($handle, "\n");
            }

            $triggers = $this->db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);

            foreach ($triggers as $trigger) {
                $triggerName = (string) ($trigger['Trigger'] ?? '');

                if ($triggerName === '') {
                    continue;
                }

                $definition = $this->db->query(
                    'SHOW CREATE TRIGGER ' . $this->quoteIdentifier($triggerName)
                )->fetch(PDO::FETCH_ASSOC);
                $createSql = $definition['SQL Original Statement'] ?? null;

                if (!is_string($createSql) || trim($createSql) === '') {
                    throw new RuntimeException('Unable to read trigger definition: ' . $triggerName);
                }

                // Omit the source server's DEFINER so the dump can be restored
                // by the database account available on another environment.
                $createSql = preg_replace(
                    '/\s+DEFINER\s*=\s*(?:\x60[^\x60]*\x60@\x60[^\x60]*\x60|CURRENT_USER(?:\(\))?)/i',
                    '',
                    $createSql
                ) ?? $createSql;

                $this->write($handle, "-- Trigger: " . $this->safeComment($triggerName) . "\n");
                $this->write($handle, "DROP TRIGGER IF EXISTS " . $this->quoteIdentifier($triggerName) . ";\n");
                $this->write($handle, "DELIMITER $$\n");
                $this->write($handle, rtrim($createSql, ";\r\n") . "$$\n");
                $this->write($handle, "DELIMITER ;\n\n");
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush the SQL backup file.');
            }

            if (!fclose($handle)) {
                throw new RuntimeException('Unable to finalize the SQL backup file.');
            }

            $handle = null;

            $hmac = hash_hmac_file('sha256', $path, $this->getBackupHmacKey());

            if (!is_string($hmac)) {
                throw new RuntimeException('Unable to calculate the SQL backup integrity signature.');
            }

            $signatureHandle = fopen($path, 'ab');

            if ($signatureHandle === false) {
                throw new RuntimeException('Unable to append the SQL backup integrity signature.');
            }

            try {
                $this->write($signatureHandle, "-- AssetSys HMAC-SHA256: " . $hmac . "\n");

                if (!fflush($signatureHandle)) {
                    throw new RuntimeException('Unable to flush the SQL backup integrity signature.');
                }
            } finally {
                fclose($signatureHandle);
            }

            if (!chmod($path, 0600)) {
                throw new RuntimeException('Unable to secure the signed SQL backup file permissions.');
            }

            $this->db->commit();
            $transactionStarted = false;
        } catch (Throwable $e) {
            if ($transactionStarted && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if (is_resource($handle)) {
                fclose($handle);
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
     * Verify the HMAC footer before a backup is considered safe to restore.
     * The SQL body is hashed as a stream to avoid loading large dumps into memory.
     */
    public function verifyBackupIntegrity(string $path): void
    {
        $key = $this->getBackupHmacKey();
        $size = filesize($path);

        if (!is_int($size) || $size < 80) {
            throw new RuntimeException('The SQL backup is too small or unreadable.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the selected SQL backup.');
        }

        try {
            $tailLength = min(256, $size);
            if (fseek($handle, -$tailLength, SEEK_END) !== 0) {
                throw new RuntimeException('Unable to inspect the SQL backup integrity signature.');
            }

            $tail = stream_get_contents($handle);

            if (!is_string($tail) || !preg_match('/\\n-- AssetSys HMAC-SHA256: ([a-f0-9]{64})\\r?\\n?\\z/i', $tail, $match, PREG_OFFSET_CAPTURE)) {
                throw new RuntimeException('This backup is unsigned or its integrity signature is missing.');
            }

            $footerOffset = ($size - $tailLength) + $match[0][1] + 1;
            $expected = strtolower($match[1][0]);

            if ($footerOffset <= 0 || fseek($handle, 0, SEEK_SET) !== 0) {
                throw new RuntimeException('The SQL backup integrity signature is invalid.');
            }

            $context = hash_init('sha256', HASH_HMAC, $key);
            $remaining = $footerOffset;

            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Unable to read the complete SQL backup for integrity verification.');
                }

                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }

            $actual = hash_final($context);

            if (!hash_equals($expected, $actual)) {
                throw new RuntimeException('The SQL backup integrity check failed. The file may be corrupted or modified.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function getBackupHmacKey(): string
    {
        $key = defined('BACKUP_HMAC_KEY') ? BACKUP_HMAC_KEY : '';

        if (!is_string($key) || !preg_match('/\\A[a-f0-9]{64}\\z/i', $key)) {
            throw new RuntimeException('Database backup signing is not configured. Set BACKUP_HMAC_KEY to a 64-character hexadecimal secret.');
        }

        return hex2bin($key) ?: throw new RuntimeException('The database backup signing key is invalid.');
    }

    public function getBackups(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return [];
        }

        $items = [];

        foreach (glob($this->backupDirectory . DIRECTORY_SEPARATOR . 'assetsys-db-*.sql') ?: [] as $path) {
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

    public function resolveBackup(string $filename): string
    {
        if (!preg_match('/\Aassetsys-db-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sql\z/', $filename)) {
            throw new RuntimeException('Invalid backup filename.');
        }

        $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('The requested backup file is unavailable.');
        }

        return $path;
    }

    private function write($handle, string $content): void
    {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('A SQL backup record could not be written.');
            }

            $offset += $written;
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        $tick = chr(96);
        return $tick . str_replace($tick, $tick . $tick, $identifier) . $tick;
    }

    private function safeComment(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], ' ', $value);
    }
}
