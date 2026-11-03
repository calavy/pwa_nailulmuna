-- WA outbound global dispatch queue (idempotent, tidak menghapus data lama)

CREATE TABLE IF NOT EXISTS wa_dispatch_id_seq (
    seq_date DATE NOT NULL PRIMARY KEY,
    last_num INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_outbound_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispatch_id VARCHAR(24) NULL,
    target_phone VARCHAR(40) NOT NULL,
    kind VARCHAR(40) NOT NULL DEFAULT 'general',
    message MEDIUMTEXT NOT NULL,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 40,
    dedup_key VARCHAR(191) NULL,
    status ENUM('pending','sending','retry_wait','sent','failed','dead','cancelled') NOT NULL DEFAULT 'pending',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dispatch_source ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',
    payload_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NULL,
    next_retry_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    provider_message_id VARCHAR(128) NULL,
    retry_reason VARCHAR(64) NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    UNIQUE KEY uk_wa_outbound_dispatch_id (dispatch_id),
    INDEX idx_wa_outbound_drain (status, next_retry_at, priority, id),
    INDEX idx_wa_outbound_target (target_phone, status),
    INDEX idx_wa_outbound_dedup (dedup_key),
    INDEX idx_wa_outbound_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel lama: status cancelled. Kolom cancelled_at/cancelled_by ditambahkan otomatis oleh wa_outbound_migrate_schema_extras().
ALTER TABLE wa_outbound_queue
    MODIFY status ENUM('pending','sending','retry_wait','sent','failed','dead','cancelled') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS wa_opt_out (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_phone VARCHAR(40) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'all',
    status ENUM('opt_out','opt_in') NOT NULL DEFAULT 'opt_out',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NULL,
    UNIQUE KEY uk_wa_opt_out_target_cat (target_phone, category),
    INDEX idx_wa_opt_out_phone (target_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kunci dedup pesan yang dihapus permanen dari antrean; mencegah job mengantre ulang.
CREATE TABLE IF NOT EXISTS wa_outbound_blocked_keys (
    dedup_key VARCHAR(191) NOT NULL PRIMARY KEY,
    kind VARCHAR(40) NULL,
    blocked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
