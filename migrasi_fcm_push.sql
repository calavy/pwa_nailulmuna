-- Migrasi FCM Push Notifications (jalankan sekali di phpMyAdmin)
USE pwa_nailulmuna;

-- Role kiai untuk notifikasi ringkasan harian
ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','kiai') NOT NULL DEFAULT 'pengurus';

CREATE TABLE IF NOT EXISTS fcm_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(512) NOT NULL,
    audience_type ENUM('wali','staff','kiai') NOT NULL,
    wali_santri_id INT NULL,
    user_id INT NULL,
    device_label VARCHAR(120) NULL,
    categories_json TEXT NULL COMMENT 'JSON array kategori aktif',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fcm_token (token(191)),
    INDEX idx_fcm_wali (wali_santri_id),
    INDEX idx_fcm_user (user_id),
    INDEX idx_fcm_audience (audience_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audience_type VARCHAR(20) NULL,
    target_ref VARCHAR(80) NULL,
    category VARCHAR(50) NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NULL,
    data_json TEXT NULL,
    tokens_targeted INT NOT NULL DEFAULT 0,
    tokens_success INT NOT NULL DEFAULT 0,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    response_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_push_logs_cat (category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('fcm_enabled', '0'),
('fcm_project_id', ''),
('fcm_client_email', ''),
('fcm_private_key', ''),
('fcm_vapid_key', ''),
('fcm_web_api_key', ''),
('fcm_sender_id', ''),
('fcm_app_id', ''),
('fcm_notify_mode', 'both'),
('fcm_daily_kiai_enabled', '1'),
('fcm_daily_kiai_time', '20:00'),
('fcm_daily_kiai_last_date', '')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
