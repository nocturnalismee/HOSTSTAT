USE servmon;

INSERT INTO users (username, password_hash, role)
VALUES ('admin', '$2y$12$ubSHwQRdqexoIqAKA8BZNeRZcfhtZTzsIU70zQsE8jY5nKBBkQICO', 'admin')
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO users (username, password_hash, role)
VALUES ('support', '$2y$12$ubSHwQRdqexoIqAKA8BZNeRZcfhtZTzsIU70zQsE8jY5nKBBkQICO', 'viewer')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO servers (id, name, url, location, host, type, agent_mode, token, notify_email, active)
VALUES
(
    1,
    'web-prod-01',
    NULL,
    'Singapore',
    '10.10.10.11',
    'Nginx',
    'push',
    'a4fbb8ef2d5ad6cff4edc2eb2a0b53a1d4cd1c3f9b8fbe1c1134cb60225d11f0',
    'ops@example.com',
    1
),
(
    2,
    'mail-prod-01',
    NULL,
    'Jakarta',
    '10.10.10.21',
    'Postfix',
    'push',
    '98f2bd11f74f0ecdf5f86c8423d2f7dd19580e47142988569c4b9567cc1b7dc3',
    'mailops@example.com',
    1
)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO metrics (server_id, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, recorded_at)
VALUES
(1, 864000, 8589934592, 4294967296, 107374182400, 53687091200, 0.42, 1200000, 900000, 'none', 0, NOW() - INTERVAL 30 MINUTE),
(1, 864060, 8589934592, 4380866641, 107374182400, 53955564544, 0.58, 1500000, 1100000, 'none', 0, NOW() - INTERVAL 15 MINUTE),
(1, 864120, 8589934592, 4445962240, 107374182400, 54358179840, 0.62, 1300000, 1250000, 'none', 0, NOW() - INTERVAL 1 MINUTE),
(2, 125000, 17179869184, 8589934592, 214748364800, 88919244800, 0.77, 2100000, 2500000, 'postfix', 16, NOW() - INTERVAL 30 MINUTE),
(2, 125060, 17179869184, 9019431321, 214748364800, 90177536000, 0.81, 2600000, 2300000, 'postfix', 27, NOW() - INTERVAL 15 MINUTE),
(2, 125120, 17179869184, 9448928051, 214748364800, 91053306624, 0.93, 2800000, 2950000, 'postfix', 33, NOW() - INTERVAL 1 MINUTE);

UPDATE servers s
SET s.latest_metric_id = (
    SELECT m.id
    FROM metrics m
    WHERE m.server_id = s.id
    ORDER BY m.recorded_at DESC, m.id DESC
    LIMIT 1
)
WHERE s.id IN (1, 2);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('branding_logo_url', ''),
('branding_favicon_url', ''),
('alert_down_minutes', '5'),
('alert_cooldown_minutes', '30'),
('alert_service_status_enabled', '1'),
('alert_ping_enabled', '1'),
('threshold_mail_queue', '50'),
('threshold_mail_queue_critical', '100'),
('threshold_cpu_load', '2.00'),
('threshold_cpu_load_critical', '4.00'),
('threshold_ram_pct', '85'),
('threshold_ram_pct_critical', '95'),
('threshold_disk_pct', '90'),
('threshold_disk_pct_critical', '97'),
('alert_service_flap_suppress_minutes', '5'),
('cache_ttl_status_list', '15'),
('cache_ttl_status_single', '15'),
('cache_ttl_history_24h', '30'),
('cache_ttl_history_7d', '120'),
('cache_ttl_history_30d', '180'),
('cache_ttl_alert_logs', '20'),
('public_alerts_redact_message', '0'),
('channel_email_enabled', '0'),
('channel_telegram_enabled', '0'),
('session_idle_timeout_minutes', '60'),
('session_absolute_timeout_minutes', '480'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_secure', 'tls'),
('smtp_from_email', ''),
('smtp_from_name', 'servmon'),
('smtp_to_email', ''),
('telegram_bot_token', ''),
('telegram_chat_id', ''),
('telegram_thread_id', ''),
('retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
