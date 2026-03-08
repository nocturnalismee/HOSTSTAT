-- Migration: Add separate retention setting for disk health data

INSERT INTO app_settings (setting_key, setting_value) VALUES
('disk_retention_days', '90')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

