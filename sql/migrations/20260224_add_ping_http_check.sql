ALTER TABLE ping_monitors
    ADD COLUMN IF NOT EXISTS check_method ENUM('icmp','http') NOT NULL DEFAULT 'icmp' AFTER target_type;

ALTER TABLE ping_monitors
    MODIFY COLUMN target_type ENUM('ip','domain','url') NOT NULL DEFAULT 'domain';

UPDATE ping_monitors
SET target_type = 'url'
WHERE check_method = 'http' AND target_type <> 'url';
