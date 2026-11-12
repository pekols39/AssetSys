<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use Throwable;

class AssetImportService
{
    private PDO $db;

    private const REQUIRED_HEADERS = [
        'LOCATION (Main)',
        'OWNER / DEPARTMENT',
        'Item ID',
        'Item name',
        'FFE NUMBER',
        'CODE',
        'Status',
        'SUPPLIER',
        'PO NUMBER',
        'Price',
        'DATE PURCHASE',
        'LOCATION',
        'QR CODE GENERATOR',
    ];

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Read a Finance workbook and save every non-empty source row as staging data.
     * No AssetSys asset or reference record is created or modified here.
     */
    public function stageWorkbook(
        string $filePath,
        string $originalFilename,
        int $userId
    ): int {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('The uploaded workbook cannot be read.');
        }

        if ($userId <= 0) {
            throw new RuntimeException('Authenticated user is required.');
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            throw new RuntimeException('Please upload the Finance inventory in .xlsx format.');
        }

        $reader = new Xlsx();
        $reader->setReadDataOnly(false);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('The Excel workbook is invalid or cannot be opened.');
        }

        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $sheet->getHighestDataColumn()
            );

            $headerMap = [];
            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $header = trim((string) $sheet->getCell([$column, 1])->getFormattedValue());
                if ($header !== '') {
                    if (isset($headerMap[$header])) {
                        throw new RuntimeException('Duplicate column heading found: ' . $header);
                    }
                    $headerMap[$header] = $column;
                }
            }

            $missing = array_values(array_diff(self::REQUIRED_HEADERS, array_keys($headerMap)));
            if ($missing !== []) {
                throw new RuntimeException(
                    'The workbook is missing required columns: ' . implode(', ', $missing)
                );
            }

            $fileHash = hash_file('sha256', $filePath);
            if (!is_string($fileHash)) {
                throw new RuntimeException('Unable to calculate the workbook checksum.');
            }

            $filename = substr(basename($originalFilename), 0, 255);

            $this->db->beginTransaction();

            $batchStmt = $this->db->prepare(
                'INSERT INTO asset_import_batches
                    (original_filename, file_sha256, imported_by, batch_status)
                 VALUES
                    (:filename, :file_sha256, :imported_by, \'processing\')'
            );
            $batchStmt->execute([
                'filename' => $filename,
                'file_sha256' => $fileHash,
                'imported_by' => $userId,
            ]);

            $batchId = (int) $this->db->lastInsertId();

            $rowStmt = $this->db->prepare(
                'INSERT INTO asset_import_rows
                    (batch_id, source_row_number, source_data, row_status)
                 VALUES
                    (:batch_id, :source_row_number, :source_data, \'pending\')'
            );

            $totalRows = 0;

            for ($row = 2; $row <= $highestRow; $row++) {
                $displayValues = [];
                $rawValues = [];
                $hasValue = false;

                foreach (self::REQUIRED_HEADERS as $header) {
                    $column = $headerMap[$header];
                    $cell = $sheet->getCell([$column, $row]);
                    $raw = $cell->getValue();
                    $display = $cell->getFormattedValue();

                    if ($raw !== null && $raw !== '') {
                        $hasValue = true;
                    }

                    $displayValues[$header] = ($display === '' ? null : (string) $display);
                    $rawValues[$header] = $raw;
                }

                if (!$hasValue) {
                    continue;
                }

                $sourceData = json_encode(
                    [
                        'values' => $displayValues,
                        'raw_values' => $rawValues,
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR
                );

                $rowStmt->execute([
                    'batch_id' => $batchId,
                    'source_row_number' => $row,
                    'source_data' => $sourceData,
                ]);

                $totalRows++;
            }

            if ($totalRows === 0) {
                throw new RuntimeException('No inventory records were found below the header row.');
            }

            $finishStmt = $this->db->prepare(
                'UPDATE asset_import_batches
                 SET total_rows = :total_rows,
                     batch_status = \'preview\'
                 WHERE id = :id'
            );
            $finishStmt->execute([
                'total_rows' => $totalRows,
                'id' => $batchId,
            ]);

            $this->db->commit();

            return $batchId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Unable to stage the Finance workbook.');
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function getBatch(int $batchId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
                b.id,
                b.original_filename,
                b.file_sha256,
                b.total_rows,
                b.imported_rows,
                b.skipped_rows,
                b.failed_rows,
                b.batch_status,
                b.created_at,
                u.first_name,
                u.last_name
             FROM asset_import_batches b
             INNER JOIN users u ON u.id = b.imported_by
             WHERE b.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $batchId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBatchRows(int $batchId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        $stmt = $this->db->prepare(
            'SELECT source_row_number, source_data, row_status, validation_errors
             FROM asset_import_rows
             WHERE batch_id = :batch_id
             ORDER BY source_row_number ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['batch_id' => $batchId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $decoded = json_decode($row['source_data'], true);
            $row['source_values'] = is_array($decoded['values'] ?? null)
                ? $decoded['values']
                : [];
        }
        unset($row);

        return $rows;
    }

    /** Return distinct Finance Item ID and Status values for manual mapping. */
    public function getMappingOptions(int $batchId): array
    {
        $stmt = $this->db->prepare(
            'SELECT source_data
             FROM asset_import_rows
             WHERE batch_id = :batch_id
             ORDER BY source_row_number ASC'
        );
        $stmt->execute(['batch_id' => $batchId]);

        $itemIds = [];
        $statuses = [];

        while ($source = $stmt->fetchColumn()) {
            $decoded = json_decode((string) $source, true);
            $values = is_array($decoded['values'] ?? null) ? $decoded['values'] : [];

            $itemId = trim((string) ($values['Item ID'] ?? ''));
            $status = trim((string) ($values['Status'] ?? ''));

            if ($itemId !== '') {
                $itemIds[$itemId] = true;
            }
            if ($status !== '') {
                $statuses[$status] = true;
            }
        }

        $itemValues = array_keys($itemIds);
        $statusValues = array_keys($statuses);
        natcasesort($itemValues);
        natcasesort($statusValues);

        return [
            'item_ids' => array_values($itemValues),
            'statuses' => array_values($statusValues),
        ];
    }

    /** Save manual category/status mappings without altering original source rows. */
    public function saveBatchMapping(int $batchId, array $mapping): void
    {
        $batch = $this->getBatch($batchId);
        if (!$batch) {
            throw new RuntimeException('Import batch not found.');
        }
        if ($batch['batch_status'] !== 'preview') {
            throw new RuntimeException('Mappings can only be changed while the batch is in preview.');
        }

        $options = $this->getMappingOptions($batchId);
        $categories = $this->db->query(
            'SELECT id FROM asset_categories WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $statuses = $this->db->query(
            'SELECT id FROM asset_statuses WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);

        $categoryIds = array_map('intval', $categories);
        $statusIds = array_map('intval', $statuses);

        $clean = ['item_ids' => [], 'statuses' => []];

        foreach ($options['item_ids'] as $value) {
            $id = filter_var($mapping['item_ids'][$value] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !in_array((int) $id, $categoryIds, true)) {
                throw new RuntimeException('Select a valid AssetSys category for Finance Item ID: ' . $value);
            }
            $clean['item_ids'][$value] = (int) $id;
        }

        foreach ($options['statuses'] as $value) {
            $id = filter_var($mapping['statuses'][$value] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !in_array((int) $id, $statusIds, true)) {
                throw new RuntimeException('Select a valid AssetSys status for Finance Status: ' . $value);
            }
            $clean['statuses'][$value] = (int) $id;
        }

        $stmt = $this->db->prepare(
            'UPDATE asset_import_batches
             SET mapping_data = :mapping_data,
                 mapping_saved_at = CURRENT_TIMESTAMP
             WHERE id = :id AND batch_status = \'preview\''
        );
        $stmt->execute([
            'mapping_data' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $batchId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Unable to save mappings for this batch.');
        }
    }

    public function getSavedMapping(int $batchId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mapping_data FROM asset_import_batches WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $batchId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return ['item_ids' => [], 'statuses' => []];
        }

        $mapping = json_decode($json, true);
        return is_array($mapping)
            ? $mapping
            : ['item_ids' => [], 'statuses' => []];
    }

}
