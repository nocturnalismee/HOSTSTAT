USE servmon;

ALTER TABLE servers
    ADD COLUMN push_allowed_ips TEXT DEFAULT NULL AFTER maintenance_until;

INSERT INTO app_settings (setting_key, setting_value)
VALUES
    ('threshold_mail_queue_critical', '100'),
    ('threshold_cpu_load_critical', '4.00'),
    ('threshold_ram_pct_critical', '95'),
    ('threshold_disk_pct_critical', '97'),
    ('alert_service_flap_suppress_minutes', '5')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
