<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';

/**
 * Data retention cleanup with batch delete pattern.
 *
 * Deletes old data in small batches (max 5000 rows per iteration)
 * to avoid long-running transactions and table locks.
 */

/**
 * Allowed table+column combinations for retention batch delete.
 * Prevents SQL injection by whitelisting valid targets.
 */
function retention_allowed_targets(): array
{
    return [
        'metrics' => 'recorded_at',
        'metrics_history' => 'recorded_at',
        'disk_health_metrics' => 'recorded_at',
        'disk_health_metrics_history' => 'recorded_date',
        'service_metrics' => 'recorded_at',
        'ping_checks' => 'checked_at',
        'alert_logs' => 'created_at',
        'login_attempts' => 'attempted_at',
        'admin_audit_logs' => 'created_at',
    ];
}

function retention_table_exists(string $table): bool
{
    $stmt = db()->prepare("SHOW TABLES LIKE :table");
    $stmt->execute([':table' => $table]);
    return $stmt->fetch() !== false;
}

function retention_batch_delete(string $table, string $column, string $cutoff, int $batchSize = 5000): int
{
    $allowed = retention_allowed_targets();
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        throw new InvalidArgumentException(
            "retention_batch_delete: disallowed table/column pair: {$table}.{$column}"
        );
    }

    $totalDeleted = 0;
    do {
        $stmt = db()->prepare(
            "DELETE FROM `{$table}` WHERE `{$column}` < :cutoff LIMIT :batch"
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':batch', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rowsDeleted = $stmt->rowCount();
        $totalDeleted += $rowsDeleted;

        if ($rowsDeleted > 0) {
            usleep(50000); // 50ms pause between batches to reduce lock pressure
        }
    } while ($rowsDeleted >= $batchSize);

    return $totalDeleted;
}

function run_core_retention_cleanup(int $days): array
{
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    servmon_log_info("Starting core retention cleanup: {$days} days, cutoff={$cutoff}", 'retention');

    $serviceMetrics = retention_batch_delete('service_metrics', 'recorded_at', $cutoff);
    $metrics = retention_batch_delete('metrics', 'recorded_at', $cutoff);

    // Clean up aggregated history with a longer retention (3x raw retention)
    $historyCutoff = date('Y-m-d H:i:s', strtotime("-" . ($days * 3) . " days"));
    $metricsHistory = 0;
    if (retention_table_exists('metrics_history')) {
        $metricsHistory = retention_batch_delete('metrics_history', 'recorded_at', $historyCutoff);
    }

    $pingChecks = 0;
    if (retention_table_exists('ping_checks')) {
        $pingChecks = retention_batch_delete('ping_checks', 'checked_at', $cutoff);
    }

    $alerts = retention_batch_delete('alert_logs', 'created_at', $cutoff);
    $attempts = retention_batch_delete('login_attempts', 'attempted_at', $cutoff);
    $audits = retention_batch_delete('admin_audit_logs', 'created_at', $cutoff);

    $result = [
        'cutoff_at' => $cutoff,
        'history_cutoff_at' => $historyCutoff,
        'service_metrics_deleted' => $serviceMetrics,
        'metrics_deleted' => $metrics,
        'metrics_history_deleted' => $metricsHistory,
        'ping_checks_deleted' => $pingChecks,
        'alerts_deleted' => $alerts,
        'attempts_deleted' => $attempts,
        'audits_deleted' => $audits,
    ];

    servmon_log_info('Core retention cleanup complete', 'retention', $result);

    return $result;
}

function run_disk_retention_cleanup(int $days): array
{
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $historyCutoffDate = date('Y-m-d', strtotime("-" . ($days * 3) . " days"));

    servmon_log_info("Starting disk retention cleanup: {$days} days, cutoff={$cutoff}", 'retention');

    $diskMetrics = 0;
    $diskHistory = 0;

    if (retention_table_exists('disk_health_metrics')) {
        $diskMetrics = retention_batch_delete('disk_health_metrics', 'recorded_at', $cutoff);
    }
    if (retention_table_exists('disk_health_metrics_history')) {
        $diskHistory = retention_batch_delete('disk_health_metrics_history', 'recorded_date', $historyCutoffDate);
    }

    $result = [
        'cutoff_at' => $cutoff,
        'history_cutoff_at' => $historyCutoffDate,
        'disk_metrics_deleted' => $diskMetrics,
        'disk_history_deleted' => $diskHistory,
    ];

    servmon_log_info('Disk retention cleanup complete', 'retention', $result);

    return $result;
}

function run_retention_cleanup(int $days): array
{
    return run_core_retention_cleanup($days);
}
