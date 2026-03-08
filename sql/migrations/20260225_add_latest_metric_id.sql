-- Add latest_metric_id column to servers for fast metric lookups
-- Idempotent: safe to re-run
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'servers' AND column_name = 'latest_metric_id');

SET @sql = IF(@col_exists = 0, 'ALTER TABLE servers ADD COLUMN latest_metric_id BIGINT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'servers' AND index_name = 'idx_servers_latest_metric');

SET @sql = IF(@idx_exists = 0, 'ALTER TABLE servers ADD INDEX idx_servers_latest_metric (latest_metric_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill existing data
UPDATE servers s
SET s.latest_metric_id = (
    SELECT m.id FROM metrics m
    WHERE m.server_id = s.id
    ORDER BY m.recorded_at DESC, m.id DESC
    LIMIT 1
)
WHERE s.latest_metric_id IS NULL;
