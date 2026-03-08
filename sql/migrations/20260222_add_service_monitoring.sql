USE servmon;

CREATE TABLE IF NOT EXISTS service_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_id BIGINT NOT NULL,
    server_id INT NOT NULL,
    service_group VARCHAR(32) NOT NULL,
    service_key VARCHAR(32) NOT NULL,
    unit_name VARCHAR(64) NOT NULL,
    status ENUM('up','down','unknown') NOT NULL,
    source ENUM('systemctl','service','pgrep') NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_metrics_metric FOREIGN KEY (metric_id) REFERENCES metrics(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_service_metrics_server_group_key_time (server_id, service_group, service_key, recorded_at),
    INDEX idx_service_metrics_metric (metric_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_service_states (
    server_id INT NOT NULL,
    service_group VARCHAR(32) NOT NULL,
    service_key VARCHAR(32) NOT NULL,
    unit_name VARCHAR(64) NOT NULL,
    last_status ENUM('up','down','unknown') NOT NULL,
    last_change_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, service_group, service_key),
    CONSTRAINT fk_server_service_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
