USE servmon;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('alert_service_status_enabled', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
