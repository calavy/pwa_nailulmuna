-- Log idempotensi sinkronisasi offline (presensi scan, input poin).
CREATE TABLE IF NOT EXISTS offline_sync_log (
    client_uuid VARCHAR(36) NOT NULL PRIMARY KEY,
    module ENUM('presensi_scan', 'poin_input') NOT NULL,
    user_id INT NOT NULL DEFAULT 0,
    result ENUM('accepted', 'duplicate', 'conflict_lost', 'error') NOT NULL DEFAULT 'accepted',
    server_record_id INT NULL,
    client_created_at DATETIME NULL,
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_offline_sync_module (module, synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
