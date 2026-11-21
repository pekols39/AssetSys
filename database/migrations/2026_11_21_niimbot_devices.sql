-- Registry for independently authenticated NIIMBOT print stations.
-- Safe to re-run: creates the registry table only when it does not exist.
--
-- The existing Property Custodian agent is enrolled automatically on its
-- first successful authenticated poll using the legacy .env credentials.
-- New stations are added by inserting their device ID and SHA-256 token hash.

CREATE TABLE IF NOT EXISTS niimbot_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(100) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_niimbot_devices_device_id (device_id),
    KEY idx_niimbot_devices_enabled (is_enabled)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- To register another station, generate a random 64-character hexadecimal
-- token locally, store only SHA-256(token) here, and put the raw token only
-- in that station's ignored tools/niimbot/agent-config.json file.
--
-- Example (replace the hash with the locally generated SHA-256 hash):
-- INSERT INTO niimbot_devices (device_id, display_name, token_hash, is_enabled)
-- VALUES ('it-laptop-b1', 'IT Laptop B1', '<64-character-sha256-hash>', 1);
--
-- Disable a station without affecting other stations:
-- UPDATE niimbot_devices SET is_enabled = 0 WHERE device_id = 'it-laptop-b1';
--
-- Re-enable:
-- UPDATE niimbot_devices SET is_enabled = 1 WHERE device_id = 'it-laptop-b1';
--
-- Rollback (run manually only if the registry must be removed):
-- DROP TABLE IF EXISTS niimbot_devices;
