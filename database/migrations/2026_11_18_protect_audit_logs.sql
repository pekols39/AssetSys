-- Protect audit_logs from UPDATE and DELETE operations.
-- New INSERT operations remain allowed.
-- Safe to re-run: existing triggers with these names are replaced.

DROP TRIGGER IF EXISTS audit_logs_prevent_update;
DROP TRIGGER IF EXISTS audit_logs_prevent_delete;

CREATE TRIGGER audit_logs_prevent_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Audit logs cannot be modified.';

CREATE TRIGGER audit_logs_prevent_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Audit logs cannot be deleted.';

-- Rollback (run manually only if protection must be removed):
-- DROP TRIGGER IF EXISTS audit_logs_prevent_update;
-- DROP TRIGGER IF EXISTS audit_logs_prevent_delete;
