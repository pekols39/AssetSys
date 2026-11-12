-- Add reviewed AssetSys reference mappings to Finance import batches.
-- Run after 2026_11_12_finance_inventory_import.sql.
ALTER TABLE asset_import_batches
    ADD COLUMN mapping_data LONGTEXT NULL AFTER batch_status,
    ADD COLUMN mapping_saved_at TIMESTAMP NULL DEFAULT NULL AFTER mapping_data;
