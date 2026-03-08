-- Migration: Add disk ingest and rollup settings defaults

INSERT INTO app_settings (setting_key, setting_value) VALUES
('disk_rollup_days', '2'),
('disk_push_max_body_bytes', '1048576'),
('disk_push_max_items', '64')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

