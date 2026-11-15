<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Generator;
use PDO;
use RuntimeException;
use Throwable;

class DatabaseRestoreService
{
    private PDO $db;
    private DatabaseBackupService $backupService;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->backupService = new DatabaseBackupService();
    }

    /**
     * Restore one of AssetSys' own SQL backups.
     * A fresh safety backup is created before any destructive statement runs.
     */
    public function restoreBackup(string $filename): array
    {
        $path = $this->backupService->resolveBackup($filename);
        $this->validateBackup($path);

        // Validate every statement before creating the safety copy or changing data.
        $statementCount = 0;
        $tableCount = 0;

        foreach ($this->statements($path) as $statement) {
            $statement = $this->removeLeadingComments($statement);

            if ($statement === '') {
                continue;
            }

            $this->assertAllowedStatement($statement);
            $statementCount++;

            if (preg_match('/\ACREATE\s+TABLE\b/i', $statement)) {
                $tableCount++;
            }
        }

        if ($statementCount === 0 || $tableCount === 0) {
            throw new RuntimeException('The SQL backup does not contain restorable table definitions.');
        }

        $safetyBackup = $this->backupService->createBackup();
        $executed = 0;

        try {
            foreach ($this->statements($path) as $statement) {
                $statement = $this->removeLeadingComments($statement);

                if ($statement === '') {
                    continue;
                }

                $this->assertAllowedStatement($statement);
                $this->db->exec($statement);
                $executed++;
            }

            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try {
                $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $resetError) {
                error_log('Unable to re-enable foreign key checks after restore failure: ' . $resetError->getMessage());
            }

            throw new RuntimeException(
                'Restore stopped after ' . $executed . ' SQL statements. The database may be partially restored. '
                . 'A safety backup was created: ' . $safetyBackup['filename'],
                0,
                $e
            );
        }

        return [
            'filename' => $filename,
            'safety_backup' => $safetyBackup['filename'],
            'statements' => $executed,
            'tables' => $tableCount,
        ];
    }

    private function validateBackup(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the selected SQL backup.');
        }

        try {
            $signature = fgets($handle);
            $databaseLine = fgets($handle);
        } finally {
            fclose($handle);
        }

        if (trim((string) $signature) !== '-- AssetSys database backup') {
            throw new RuntimeException('This file is not an AssetSys-generated SQL backup.');
        }

        if (!is_string($databaseLine) || !preg_match('/\A-- Database: [A-Za-z0-9_$-]+\s*\z/', $databaseLine)) {
            throw new RuntimeException('The SQL backup database header is invalid.');
        }
    }

    /**
     * Read SQL statements while honoring the DELIMITER directives used for triggers.
     * The parser tracks quoted strings and SQL comments so embedded semicolons are retained.
     */
    private function statements(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the SQL backup.');
        }

        $delimiter = ';';
        $buffer = '';
        $quote = null;
        $blockComment = false;

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($buffer) === '' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', rtrim($line, "\r\n"), $match)) {
                    $buffer = '';
                    $delimiter = $match[1];
                    continue;
                }

                $length = strlen($line);
                $lineComment = false;

                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];
                    $next = $i + 1 < $length ? $line[$i + 1] : '';

                    if ($lineComment) {
                        $buffer .= substr($line, $i);
                        break;
                    }

                    if ($blockComment) {
                        $buffer .= $char;

                        if ($char === '*' && $next === '/') {
                            $buffer .= '/';
                            $i++;
                            $blockComment = false;
                        }

                        continue;
                    }

                    if ($quote !== null) {
                        $buffer .= $char;

                        if ($char === '\\') {
                            if ($i + 1 < $length) {
                                $buffer .= $line[++$i];
                            }
                            continue;
                        }

                        if ($char === $quote) {
                            if ($next === $quote) {
                                $buffer .= $line[++$i];
                            } else {
                                $quote = null;
                            }
                        }

                        continue;
                    }

                    if ($char === "'" || $char === '"' || $char === chr(96)) {
                        $quote = $char;
                        $buffer .= $char;
                        continue;
                    }

                    if ($char === '/' && $next === '*') {
                        $blockComment = true;
                        $buffer .= '/*';
                        $i++;
                        continue;
                    }

                    if ($char === '#') {
                        $lineComment = true;
                        $buffer .= $char;
                        continue;
                    }

                    if (
                        $char === '-'
                        && $next === '-'
                        && ($i + 2 >= $length || ord($line[$i + 2]) <= 32)
                    ) {
                        $lineComment = true;
                        $buffer .= '--';
                        $i++;
                        continue;
                    }

                    if (substr($line, $i, strlen($delimiter)) === $delimiter) {
                        $statement = trim($buffer);

                        if ($statement !== '') {
                            yield $statement;
                        }

                        $buffer = '';
                        $i += strlen($delimiter) - 1;
                        continue;
                    }

                    $buffer .= $char;
                }

                $buffer .= "\n";
            }

            $remaining = trim($buffer);

            if ($remaining !== '') {
                yield $remaining;
            }
        } finally {
            fclose($handle);
        }
    }

    private function removeLeadingComments(string $statement): string
    {
        $statement = preg_replace(
            '/\A(?:(?:--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\/)\s*)+/s',
            '',
            trim($statement)
        );

        return trim((string) $statement);
    }

    private function assertAllowedStatement(string $statement): void
    {
        $tick = chr(96);
        $quotedIdentifier = $tick . '(?:' . $tick . $tick . '|[^' . $tick . '])+' . $tick;

        $allowed =
            preg_match('/\ASET\s+NAMES\s+utf8mb4\z/i', $statement)
            || preg_match('/\ASET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\z/i', $statement)
            || preg_match('/\ASET\s+SQL_MODE\s*=\s*\'(?:[A-Z0-9_, -]*)\'\z/i', $statement)
            || preg_match('/\ADROP\s+TABLE\s+IF\s+EXISTS\s+' . $quotedIdentifier . '\z/i', $statement)
            || preg_match('/\ACREATE\s+TABLE\s+' . $quotedIdentifier . '\s*\(/i', $statement)
            || preg_match('/\AALTER\s+TABLE\s+' . $quotedIdentifier . '\s+ADD\s+CONSTRAINT\s+' . $quotedIdentifier . '\s+FOREIGN\s+KEY\s*\(.+\)\s+REFERENCES\s+' . $quotedIdentifier . '\s*\(.+\)(?:\s+ON\s+DELETE\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION))?(?:\s+ON\s+UPDATE\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION))?\z/is', $statement)
            || preg_match('/\AINSERT\s+INTO\s+' . $quotedIdentifier . '\s*\(.*\)\s+VALUES\s*\(/is', $statement)
            || preg_match('/\ADROP\s+TRIGGER\s+IF\s+EXISTS\s+' . $quotedIdentifier . '\z/i', $statement)
            || preg_match('/\ACREATE\s+TRIGGER\b/i', $statement);

        if (!$allowed) {
            throw new RuntimeException('The SQL backup contains a statement type that AssetSys restore does not permit.');
        }
    }
}
