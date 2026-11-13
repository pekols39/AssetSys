-- Remove the unused Finance QR Code Generator field.
-- AssetSys creates and manages QR identities through its own QR feature.
-- Existing Finance source rows remain preserved in asset_import_rows.source_data.

ALTER TABLE assets
    DROP COLUMN finance_qr_code_generator;
