-- Add worker_health table for database-backed worker status tracking
CREATE TABLE IF NOT EXISTS worker_health (
    worker_name VARCHAR(50) NOT NULL PRIMARY KEY,
    last_state ENUM('running','ok','error') NOT NULL DEFAULT 'running',
    last_started_at DATETIME DEFAULT NULL,
    last_success_at DATETIME DEFAULT NULL,
    last_failure_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
