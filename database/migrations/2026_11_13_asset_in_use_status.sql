-- Add a location-based deployment status and correct imported INSTALLED assets.
-- Existing employee assignments are not modified.
INSERT INTO asset_statuses (name, is_active)
SELECT 'In Use', 1
WHERE NOT EXISTS (
    SELECT 1
    FROM asset_statuses
    WHERE LOWER(TRIM(name)) = 'in use'
);

UPDATE assets AS a
INNER JOIN asset_statuses AS s
    ON LOWER(TRIM(s.name)) = 'in use'
SET a.status_id = s.id
WHERE UPPER(TRIM(COALESCE(a.finance_status, ''))) = 'INSTALLED'
  AND a.assigned_to_user_id IS NULL
  AND NULLIF(TRIM(COALESCE(a.assigned_to_name, '')), '') IS NULL;
