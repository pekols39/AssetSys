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

            if ($itemId === '') {
                $itemIds['__BLANK__'] = true;
            } else {
                $itemIds[$itemId] = true;
            }

            if ($status === '') {
                $statuses['__BLANK__'] = true;
            } else {
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

        // Some PDO/MySQL configurations report zero affected rows when the
        // submitted mapping is identical to the already-saved mapping.
        $verify = $this->db->prepare(
            'SELECT mapping_data FROM asset_import_batches WHERE id = :id LIMIT 1'
        );
        $verify->execute(['id' => $batchId]);
        $savedJson = $verify->fetchColumn();

        if (!is_string($savedJson) || $savedJson === '') {
            throw new RuntimeException('Unable to verify saved mappings for this batch.');
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


    /**
     * Create AssetSys assets from a reviewed staging batch.
     * Finance reference text is retained in dedicated columns; no department,
     * location, supplier, FFE number, or Finance code is auto-normalized.
     */
    public function processBatch(int $batchId, int $userId): array
    {
        if ($batchId <= 0 || $userId <= 0) {
            throw new RuntimeException('Invalid import request.');
        }

        $batchStmt = $this->db->prepare(
            'SELECT id, batch_status, mapping_data
             FROM asset_import_batches
             WHERE id = :id
             FOR UPDATE'
        );
        $this->db->beginTransaction();

        try {
            $batchStmt->execute(['id' => $batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch || $batch['batch_status'] !== 'preview') {
                throw new RuntimeException('This import batch is not available for processing.');
            }

            if (!is_string($batch['mapping_data']) || $batch['mapping_data'] === '') {
                throw new RuntimeException('Save the category and status mappings before importing.');
            }

            $mapping = json_decode($batch['mapping_data'], true);
            if (!is_array($mapping)) {
                throw new RuntimeException('The saved reference mapping is invalid.');
            }

            $itemMappings = $mapping['item_ids'] ?? [];
            $statusMappings = $mapping['statuses'] ?? [];
            if (!is_array($itemMappings) || !is_array($statusMappings)) {
                throw new RuntimeException('The saved reference mapping is invalid.');
            }

            $this->db->prepare(
                'UPDATE asset_import_batches SET batch_status = \'processing\' WHERE id = :id'
            )->execute(['id' => $batchId]);

            $rowsStmt = $this->db->prepare(
                'SELECT id, source_row_number, source_data
                 FROM asset_import_rows
                 WHERE batch_id = :batch_id AND row_status = \'pending\'
                 ORDER BY source_row_number ASC
                 FOR UPDATE'
            );
            $rowsStmt->execute(['batch_id' => $batchId]);

            $insertAsset = $this->db->prepare(
                'INSERT INTO assets (
                    asset_code, asset_tag, serial_number, category_id,
                    department_id, asset_name, description, purchase_date,
                    purchase_cost, purchase_order_number, salvage_value,
                    depreciation_method, current_book_value, status_id,
                    location_id, created_by,
                    finance_item_id, finance_ffe_number, finance_code,
                    finance_main_location, finance_owner_department,
                    finance_status, finance_supplier, finance_po_number,
                    finance_location, finance_price, finance_qr_code_generator
                ) VALUES (
                    :asset_code, NULL, NULL, :category_id,
                    NULL, :asset_name, NULL, :purchase_date,
                    :purchase_cost, NULL, 0,
                    \'straight_line\', :current_book_value, :status_id,
                    NULL, :created_by,
                    :finance_item_id, :finance_ffe_number, :finance_code,
                    :finance_main_location, :finance_owner_department,
                    :finance_status, :finance_supplier, :finance_po_number,
                    :finance_location, :finance_price, :finance_qr_code_generator
                )'
            );

            $updateRow = $this->db->prepare(
                'UPDATE asset_import_rows
                 SET row_status = :row_status,
                     asset_id = :asset_id,
                     validation_errors = :validation_errors
                 WHERE id = :id'
            );

            $imported = 0;
            $failed = 0;

            while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $decoded = json_decode((string) $row['source_data'], true, 512, JSON_THROW_ON_ERROR);
                    $values = is_array($decoded['values'] ?? null) ? $decoded['values'] : [];
                    $raw = is_array($decoded['raw_values'] ?? null) ? $decoded['raw_values'] : [];

                    $itemKey = trim((string) ($values['Item ID'] ?? '')) === ''
                        ? '__BLANK__'
                        : trim((string) $values['Item ID']);
                    $statusKey = trim((string) ($values['Status'] ?? '')) === ''
                        ? '__BLANK__'
                        : trim((string) $values['Status']);

                    $categoryId = filter_var($itemMappings[$itemKey] ?? null, FILTER_VALIDATE_INT);
                    $statusId = filter_var($statusMappings[$statusKey] ?? null, FILTER_VALIDATE_INT);

                    if ($categoryId === false || $statusId === false) {
                        throw new RuntimeException('A Finance Item ID or Status value has no saved AssetSys mapping.');
                    }

                    $assetName = trim((string) ($values['Item name'] ?? ''));
                    if ($assetName === '') {
                        throw new RuntimeException('Finance Item name is blank; an AssetSys asset name is required.');
                    }

                    $purchaseDate = $this->normalizeFinanceDate(
                        $raw['DATE PURCHASE'] ?? null,
                        $values['DATE PURCHASE'] ?? null
                    );

                    $priceText = trim((string) ($values['Price'] ?? ''));
                    $financePrice = null;
                    if ($priceText !== '') {
                        $normalizedPrice = str_replace([',', '₱', 'PHP', ' '], '', $priceText);
                        if (!is_numeric($normalizedPrice) || (float) $normalizedPrice < 0) {
                            throw new RuntimeException('Finance Price is not a valid non-negative amount.');
                        }
                        $financePrice = round((float) $normalizedPrice, 2);
                    }

                    $assetCode = $this->nextImportAssetCode();
                    $insertAsset->execute([
                        'asset_code' => $assetCode,
                        'category_id' => (int) $categoryId,
                        'asset_name' => $assetName,
                        'purchase_date' => $purchaseDate,
                        'purchase_cost' => $financePrice ?? 0.00,
                        'current_book_value' => $financePrice ?? 0.00,
                        'status_id' => (int) $statusId,
                        'created_by' => $userId,
                        'finance_item_id' => $this->nullableFinanceText($values['Item ID'] ?? null, 100),
                        'finance_ffe_number' => $this->nullableFinanceText($values['FFE NUMBER'] ?? null, 150),
                        'finance_code' => $this->nullableFinanceText($values['CODE'] ?? null, 100),
                        'finance_main_location' => $this->nullableFinanceText($values['LOCATION (Main)'] ?? null, 150),
                        'finance_owner_department' => $this->nullableFinanceText($values['OWNER / DEPARTMENT'] ?? null, 150),
                        'finance_status' => $this->nullableFinanceText($values['Status'] ?? null, 50),
                        'finance_supplier' => $this->nullableFinanceText($values['SUPPLIER'] ?? null, 150),
                        'finance_po_number' => $this->nullableFinanceText($values['PO NUMBER'] ?? null, 100),
                        'finance_location' => $this->nullableFinanceText($values['LOCATION'] ?? null, 255),
                        'finance_price' => $financePrice,
                        'finance_qr_code_generator' => $this->nullableFinanceText($values['QR CODE GENERATOR'] ?? null, 255),
                    ]);

                    $assetId = (int) $this->db->lastInsertId();
                    $updateRow->execute([
                        'row_status' => 'imported',
                        'asset_id' => $assetId,
                        'validation_errors' => null,
                        'id' => (int) $row['id'],
                    ]);
                    $imported++;
                } catch (Throwable $rowException) {
                    $updateRow->execute([
                        'row_status' => 'failed',
                        'asset_id' => null,
                        'validation_errors' => substr($rowException->getMessage(), 0, 60000),
                        'id' => (int) $row['id'],
                    ]);
                    $failed++;
                }
            }

            $summaryStmt = $this->db->prepare(
                'UPDATE asset_import_batches
                 SET imported_rows = imported_rows + :imported,
                     failed_rows = failed_rows + :failed,
                     batch_status = \'completed\',
                     completed_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $summaryStmt->execute([
                'imported' => $imported,
                'failed' => $failed,
                'id' => $batchId,
            ]);

            (new AuditLogService())->log(
                action: 'import',
                module: 'assets',
                entityType: 'asset_import_batch',
                entityId: $batchId,
                description: 'Finance inventory import batch processed.',
                newValues: [
                    'imported_rows' => $imported,
                    'failed_rows' => $failed,
                ]
            );

            $this->db->commit();

            return ['imported' => $imported, 'failed' => $failed];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Unable to process the Finance inventory batch.', 0, $exception);
        }
    }

    private function nextImportAssetCode(): string
    {
        $date = date('Y-m-d');
        $prefix = date('Ymd');

        $stmt = $this->db->prepare(
            'INSERT INTO asset_code_sequences (sequence_date, last_number)
             VALUES (:sequence_date, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute(['sequence_date' => $date]);

        $stmt = $this->db->prepare(
            'SELECT last_number FROM asset_code_sequences
             WHERE sequence_date = :sequence_date FOR UPDATE'
        );
        $stmt->execute(['sequence_date' => $date]);
        $number = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT MAX(CAST(SUBSTRING_INDEX(asset_code, "-", -1) AS UNSIGNED))
             FROM assets WHERE asset_code REGEXP :pattern'
        );
        $stmt->execute(['pattern' => '^' . $prefix . '-[0-9]+$']);
        $existing = (int) $stmt->fetchColumn();

        if ($existing >= $number) {
            $number = $existing + 1;
            $stmt = $this->db->prepare(
                'UPDATE asset_code_sequences SET last_number = :number
                 WHERE sequence_date = :sequence_date'
            );
            $stmt->execute(['number' => $number, 'sequence_date' => $date]);
        }

        return $prefix . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private function normalizeFinanceDate(mixed $raw, mixed $display): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (Throwable) {
                // Try the formatted cell value below.
            }
        }

        $text = trim((string) ($display ?? $raw));
        if ($text === '') {
            return null;
        }

        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'j/n/Y', 'd-M-Y', 'j-M-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        throw new RuntimeException('Finance Date Purchase is not a recognizable date.');
    }

    private function nullableFinanceText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || (string) $value === '') {
            return null;
        }
        return substr((string) $value, 0, $maxLength);
    }


    /** Retrieve failed rows with their original Finance values for review/export. */
    public function getFailedRows(int $batchId, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT source_row_number, source_data, validation_errors
             FROM asset_import_rows
             WHERE batch_id = :batch_id AND row_status = \'failed\'
             ORDER BY source_row_number ASC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute(['batch_id' => $batchId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['source_data'], true);
            $row['source_values'] = is_array($decoded['values'] ?? null)
                ? $decoded['values']
                : [];
        }
        unset($row);

        return $rows;
    }

    public function countFailedRows(int $batchId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM asset_import_rows
             WHERE batch_id = :batch_id AND row_status = \'failed\''
        );
        $stmt->execute(['batch_id' => $batchId]);

        return (int) $stmt->fetchColumn();
    }

}
