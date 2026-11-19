-- Persistent print queue for the local NIIMBOT Windows agent.
-- Safe to re-run: creates the table only when it does not exist.

CREATE TABLE IF NOT EXISTS niimbot_print_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    asset_id INT(10) UNSIGNED NOT NULL,
    asset_qr_code_id INT(10) UNSIGNED NOT NULL,
    requested_by INT(10) UNSIGNED NOT NULL,

    asset_name VARCHAR(200) NOT NULL,
    asset_code VARCHAR(100) NOT NULL,
    asset_tag VARCHAR(100) DEFAULT NULL,
    finance_ffe_number VARCHAR(150) DEFAULT NULL,

    status ENUM(
        'pending',
        'processing',
        'completed',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,

    device_id VARCHAR(100) DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,
    lease_expires_at DATETIME DEFAULT NULL,

    completed_at DATETIME DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_niimbot_jobs_status_created (status, created_at),
    KEY idx_niimbot_jobs_asset_created (asset_id, created_at),
    KEY idx_niimbot_jobs_qr_code (asset_qr_code_id),
    KEY idx_niimbot_jobs_requested_by (requested_by),
    KEY idx_niimbot_jobs_lease (status, lease_expires_at)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Rollback (run manually only if the print queue must be removed):
-- DROP TABLE IF EXISTS niimbot_print_jobs;
