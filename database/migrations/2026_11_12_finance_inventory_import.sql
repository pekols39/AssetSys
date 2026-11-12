-- Finance inventory alignment and import tracking
-- Target: MariaDB 10.4+ / MySQL 8+
-- Run once against the AssetSys database after taking a full database backup.
--
-- Finance source values are stored separately from normalized AssetSys references.
-- Finance identifiers are intentionally NOT unique because the source workbook
-- contains repeated FFE NUMBER and CODE values.

ALTER TABLE assets
    ADD COLUMN finance_item_id VARCHAR(100) NULL AFTER category_id,
    ADD COLUMN finance_ffe_number VARCHAR(150) NULL AFTER asset_tag,
    ADD COLUMN finance_code VARCHAR(100) NULL AFTER finance_ffe_number,
    ADD COLUMN finance_main_location VARCHAR(150) NULL AFTER location_id,
    ADD COLUMN finance_owner_department VARCHAR(150) NULL AFTER finance_main_location,
    ADD COLUMN finance_status VARCHAR(50) NULL AFTER finance_owner_department,
    ADD COLUMN finance_supplier VARCHAR(150) NULL AFTER finance_status,
    ADD COLUMN finance_location VARCHAR(255) NULL AFTER finance_supplier,
    ADD COLUMN finance_qr_code_generator VARCHAR(255) NULL AFTER finance_location;

-- These values are indexed for lookup, but duplicates remain allowed.
ALTER TABLE assets
    ADD INDEX idx_assets_finance_ffe_number (finance_ffe_number),
    ADD INDEX idx_assets_finance_code (finance_code),
    ADD INDEX idx_assets_finance_item_id (finance_item_id),
    ADD INDEX idx_assets_finance_main_location (finance_main_location),
    ADD INDEX idx_assets_finance_status (finance_status);

-- A blank Finance Price must remain NULL, not silently become PHP 0.00.
-- Existing records retain their current numeric values.
ALTER TABLE assets
    MODIFY COLUMN purchase_cost DECIMAL(15,2) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS asset_import_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_filename VARCHAR(255) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    imported_by INT UNSIGNED NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    batch_status ENUM('preview', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'preview',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_asset_import_batches_imported_by (imported_by),
    KEY idx_asset_import_batches_file_sha256 (file_sha256),
    KEY idx_asset_import_batches_status (batch_status),
    CONSTRAINT fk_asset_import_batches_user
        FOREIGN KEY (imported_by) REFERENCES users (id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id INT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    source_data LONGTEXT NOT NULL,
    asset_id INT UNSIGNED NULL,
    row_status ENUM('pending', 'imported', 'skipped', 'failed') NOT NULL DEFAULT 'pending',
    validation_errors TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_import_rows_batch_source (batch_id, source_row_number),
    KEY idx_asset_import_rows_asset_id (asset_id),
    KEY idx_asset_import_rows_status (row_status),
    CONSTRAINT fk_asset_import_rows_batch
        FOREIGN KEY (batch_id) REFERENCES asset_import_batches (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_asset_import_rows_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
