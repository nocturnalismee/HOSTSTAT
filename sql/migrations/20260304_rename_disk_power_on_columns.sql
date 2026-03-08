-- Migration: Rename disk power-on columns to POT naming

SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'disk_health_states'
      AND column_name = 'power_on_hours'
);
SET @sql := IF(
    @has_col > 0,
    'ALTER TABLE disk_health_states CHANGE COLUMN power_on_hours power_on_time BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'disk_health_metrics'
      AND column_name = 'power_on_hours'
);
SET @sql := IF(
    @has_col > 0,
    'ALTER TABLE disk_health_metrics CHANGE COLUMN power_on_hours power_on_time BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'disk_health_metrics_history'
      AND column_name = 'power_on_hours_max'
);
SET @sql := IF(
    @has_col > 0,
    'ALTER TABLE disk_health_metrics_history CHANGE COLUMN power_on_hours_max power_on_time_max BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

