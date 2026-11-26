-- Route each queued label to the station associated with the requesting account.
-- Additive migration: only pending jobs are backfilled from their requester's role.
-- Completed/failed history and currently processing jobs are intentionally untouched.

ALTER TABLE niimbot_print_jobs
    ADD COLUMN target_device_id VARCHAR(100) NULL AFTER requested_by,
    ADD KEY idx_niimbot_print_jobs_target_status_created
        (target_device_id, status, created_at, id);

UPDATE niimbot_print_jobs j
INNER JOIN users u
    ON u.id = j.requested_by
INNER JOIN roles r
    ON r.id = u.role_id
SET j.target_device_id = CASE
    WHEN r.name = 'Property Custodian' THEN 'property-custodian-b1'
    WHEN r.name = 'Asset Manager' THEN 'it-laptop-b1'
    ELSE j.target_device_id
END
WHERE j.status = 'pending'
  AND j.target_device_id IS NULL
  AND r.name IN ('Property Custodian', 'Asset Manager');
