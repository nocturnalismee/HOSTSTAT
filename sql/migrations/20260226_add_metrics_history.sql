-- Migration: Data Aggregation
-- Create metrics_history table
CREATE TABLE IF NOT EXISTS metrics_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    recorded_at DATETIME NOT NULL,
    uptime BIGINT NOT NULL DEFAULT 0,
    ram_total BIGINT NOT NULL DEFAULT 0,
    ram_used BIGINT NOT NULL DEFAULT 0,
    hdd_total BIGINT NOT NULL DEFAULT 0,
    hdd_used BIGINT NOT NULL DEFAULT 0,
    cpu_load FLOAT NOT NULL DEFAULT 0.00,
    network_in_bps BIGINT NOT NULL DEFAULT 0,
    network_out_bps BIGINT NOT NULL DEFAULT 0,
    mail_mta VARCHAR(20) DEFAULT NULL,
    mail_queue_total INT NOT NULL DEFAULT 0,
    panel_profile VARCHAR(20) NOT NULL DEFAULT 'generic',
    CONSTRAINT fk_metrics_history_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_history_server_recorded (server_id, recorded_at)
) ENGINE=InnoDB;

-- Modify service_metrics to not delete when highly granular metrics are deleted
ALTER TABLE service_metrics DROP FOREIGN KEY fk_service_metrics_metric;
ALTER TABLE service_metrics MODIFY metric_id BIGINT NULL;
ALTER TABLE service_metrics ADD CONSTRAINT fk_service_metrics_metric FOREIGN KEY (metric_id) REFERENCES metrics(id) ON DELETE SET NULL;
