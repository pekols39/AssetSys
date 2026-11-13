-- Preserve the exact original Item name from the imported workbook.
-- AssetSys asset_name may be edited independently after import.
ALTER TABLE assets
    ADD COLUMN finance_item_name VARCHAR(200) NULL AFTER finance_item_id;

UPDATE assets a
INNER JOIN asset_import_rows r
    ON r.asset_id = a.id
SET a.finance_item_name = NULLIF(
    NULLIF(
        JSON_UNQUOTE(JSON_EXTRACT(r.source_data, '$."values"."Item name"')),
        ''
    ),
    'null'
)
WHERE r.row_status = 'imported';
