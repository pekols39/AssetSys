-- Preserve the exact displayed Finance workbook values for Price and DATE PURCHASE.
-- Existing imported rows are backfilled from their retained staging source_data.
ALTER TABLE assets
    ADD COLUMN finance_price_text VARCHAR(100) NULL AFTER finance_price,
    ADD COLUMN finance_date_purchase_text VARCHAR(100) NULL AFTER finance_price_text;

UPDATE assets a
INNER JOIN asset_import_rows r
    ON r.asset_id = a.id
SET
    a.finance_price_text = NULLIF(
        JSON_UNQUOTE(JSON_EXTRACT(r.source_data, '$.values.Price')),
        ''
    ),
    a.finance_date_purchase_text = NULLIF(
        JSON_UNQUOTE(JSON_EXTRACT(r.source_data, '$.values.DATE PURCHASE')),
        ''
    )
WHERE r.row_status = 'imported';
