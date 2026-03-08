-- Migration: Add disk health monitoring tables

CREATE TABLE IF NOT EXISTS disk_health_states (
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    device_name VARCHAR(128) DEFAULT NULL,
    model VARCHAR(191) DEFAULT NULL,
    serial VARCHAR(191) DEFAULT NULL,
    firmware VARCHAR(64) DEFAULT NULL,
    disk_type ENUM('ssd','hdd','nvme','unknown') NOT NULL DEFAULT 'unknown',
    capacity_bytes BIGINT UNSIGNED DEFAULT NULL,
    health_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    health_score DECIMAL(5,2) DEFAULT NULL,
    temperature_c DECIMAL(5,2) DEFAULT NULL,
    power_on_hours BIGINT UNSIGNED DEFAULT NULL,
    reallocated_sectors BIGINT UNSIGNED DEFAULT NULL,
    pending_sectors BIGINT UNSIGNED DEFAULT NULL,
    uncorrectable_sectors BIGINT UNSIGNED DEFAULT NULL,
    wearout_pct DECIMAL(5,2) DEFAULT NULL,
    total_written_bytes BIGINT UNSIGNED DEFAULT NULL,
    source_tool ENUM('hdsentinel','smartctl') NOT NULL DEFAULT 'smartctl',
    last_error VARCHAR(255) DEFAULT NULL,
    last_change_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, disk_key),
    CONSTRAINT fk_disk_health_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_disk_health_states_server_status (server_id, health_status),
    INDEX idx_disk_health_states_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disk_health_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    device_name VARCHAR(128) DEFAULT NULL,
    model VARCHAR(191) DEFAULT NULL,
    serial VARCHAR(191) DEFAULT NULL,
    health_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    health_score DECIMAL(5,2) DEFAULT NULL,
    temperature_c DECIMAL(5,2) DEFAULT NULL,
    power_on_hours BIGINT UNSIGNED DEFAULT NULL,
    total_written_bytes BIGINT UNSIGNED DEFAULT NULL,
    source_tool ENUM('hdsentinel','smartctl') NOT NULL DEFAULT 'smartctl',
    raw_summary TEXT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disk_health_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_disk_health_metrics_server_time (server_id, recorded_at),
    INDEX idx_disk_health_metrics_server_disk_time (server_id, disk_key, recorded_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disk_health_metrics_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    recorded_date DATE NOT NULL,
    health_score_avg DECIMAL(5,2) DEFAULT NULL,
    temperature_avg DECIMAL(6,2) DEFAULT NULL,
    temperature_max DECIMAL(6,2) DEFAULT NULL,
    power_on_hours_max BIGINT UNSIGNED DEFAULT NULL,
    total_written_bytes_max BIGINT UNSIGNED DEFAULT NULL,
    worst_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    CONSTRAINT fk_disk_health_metrics_history_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_disk_health_metrics_history_unique (server_id, disk_key, recorded_date),
    INDEX idx_disk_health_metrics_history_server_date (server_id, recorded_date)
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('cache_ttl_disk_health_list', '15'),
('cache_ttl_disk_health_single', '10'),
('cache_ttl_disk_health_history', '20')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
