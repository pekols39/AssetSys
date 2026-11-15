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

                $this->write($handle, "-- --------------------------------------------------------\n");
                $this->write($handle, "-- Table structure for " . $this->safeComment($table) . "\n");
                $this->write($handle, "-- --------------------------------------------------------\n");
                $this->write($handle, "DROP TABLE IF EXISTS " . $quotedTable . ";\n");
                $this->write($handle, $create['Create Table'] . ";\n\n");

                $columns = $this->db->query('SHOW FULL COLUMNS FROM ' . $quotedTable)
                    ->fetchAll(PDO::FETCH_ASSOC);
                $binaryColumns = [];

                foreach ($columns as $column) {
                    $type = strtolower((string) ($column['Type'] ?? ''));
                    $name = (string) ($column['Field'] ?? '');

                    if ($name !== '' && preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob)/', $type)) {
                        $binaryColumns[$name] = true;
                    }
                }

                $select = $this->db->query('SELECT * FROM ' . $quotedTable);
                $columnNames = array_map(
                    fn (array $column): string => $this->quoteIdentifier((string) $column['Field']),
                    $columns
                );

                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];

                    foreach ($row as $column => $value) {
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
