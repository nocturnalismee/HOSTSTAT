CREATE TABLE IF NOT EXISTS ping_monitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    target VARCHAR(255) NOT NULL,
    target_type ENUM('ip','domain','url') NOT NULL DEFAULT 'domain',
    check_method ENUM('icmp','http') NOT NULL DEFAULT 'icmp',
    check_interval_seconds INT NOT NULL DEFAULT 60,
    timeout_seconds INT NOT NULL DEFAULT 2,
    failure_threshold INT NOT NULL DEFAULT 2,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ping_monitors_active (active),
    INDEX idx_ping_monitors_target (target)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_monitor_states (
    monitor_id INT PRIMARY KEY,
    last_status ENUM('up','down','unknown') NOT NULL DEFAULT 'unknown',
    consecutive_failures INT NOT NULL DEFAULT 0,
    last_latency_ms DECIMAL(10,2) DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    last_change_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ping_monitor_states_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE,
    INDEX idx_ping_monitor_states_status (last_status),
    INDEX idx_ping_monitor_states_last_checked (last_checked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_checks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    monitor_id INT NOT NULL,
    status ENUM('up','down') NOT NULL,
    latency_ms DECIMAL(10,2) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ping_checks_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE,
    INDEX idx_ping_checks_monitor_time (monitor_id, checked_at),
    INDEX idx_ping_checks_time (checked_at)
) ENGINE=InnoDB;
