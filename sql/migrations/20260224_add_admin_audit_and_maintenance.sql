USE servmon;

ALTER TABLE servers
    ADD COLUMN maintenance_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_telegram,
    ADD COLUMN maintenance_until DATETIME DEFAULT NULL AFTER maintenance_mode;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_detail VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT DEFAULT NULL,
    context_json JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_user_time (user_id, created_at),
    INDEX idx_admin_audit_action_time (action_type, created_at),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value)
VALUES
    ('session_idle_timeout_minutes', '60'),
    ('session_absolute_timeout_minutes', '480')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
