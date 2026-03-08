USE servmon;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_states (
    server_id INT PRIMARY KEY,
    is_down TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_server_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alert_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT DEFAULT NULL,
    alert_type VARCHAR(50) NOT NULL,
    severity ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    context_json JSON DEFAULT NULL,
    sent_email TINYINT(1) NOT NULL DEFAULT 0,
    sent_telegram TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alert_type_time (alert_type, created_at),
    INDEX idx_alert_server_time (server_id, created_at),
    CONSTRAINT fk_alert_logs_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('alert_down_minutes', '5'),
('alert_cooldown_minutes', '30'),
('threshold_mail_queue', '50'),
('threshold_cpu_load', '2.00'),
('threshold_ram_pct', '85'),
('threshold_disk_pct', '90'),
('cache_ttl_status_list', '15'),
('cache_ttl_status_single', '15'),
('cache_ttl_history_24h', '30'),
('cache_ttl_history_7d', '120'),
('cache_ttl_history_30d', '180'),
('cache_ttl_alert_logs', '20'),
('channel_email_enabled', '0'),
('channel_telegram_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_secure', 'tls'),
('smtp_from_email', ''),
('smtp_from_name', 'servmon'),
('smtp_to_email', ''),
('telegram_bot_token', ''),
('telegram_chat_id', ''),
('telegram_thread_id', ''),
('retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
