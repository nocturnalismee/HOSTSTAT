<?php
declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function normalizedSql(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read: ' . $path);
    }
    $content = strtolower($content);
    return preg_replace('/\s+/', ' ', $content) ?? $content;
}

$schemaPath = __DIR__ . '/../sql/schema.sql';
$schemaSql = normalizedSql($schemaPath);

// Migration parity: 20260225_add_latest_metric_id.sql
assertTrue(
    str_contains($schemaSql, 'latest_metric_id bigint default null'),
    'schema.sql must include servers.latest_metric_id column'
);
assertTrue(
    str_contains($schemaSql, 'index idx_servers_latest_metric (latest_metric_id)'),
    'schema.sql must include idx_servers_latest_metric index'
);

// Migration parity: 20260225_add_worker_health_table.sql
assertTrue(
    str_contains($schemaSql, 'create table if not exists worker_health'),
    'schema.sql must include worker_health table'
);
assertTrue(
    str_contains($schemaSql, 'worker_name varchar(50) not null primary key'),
    'schema.sql must include worker_health.worker_name primary key'
);
assertTrue(
    str_contains($schemaSql, "last_state enum('running','ok','error') not null default 'running'"),
    'schema.sql must include worker_health.last_state enum'
);

// Migration parity: 20260304_add_disk_health_tables.sql
assertTrue(
    str_contains($schemaSql, 'create table if not exists disk_health_states'),
    'schema.sql must include disk_health_states table'
);
assertTrue(
    str_contains($schemaSql, "health_status enum('ok','warning','critical','unknown') not null default 'unknown'"),
    'schema.sql must include disk_health health_status enum'
);
assertTrue(
    str_contains($schemaSql, 'create table if not exists disk_health_metrics'),
    'schema.sql must include disk_health_metrics table'
);

echo "schema_consistency_test passed\n";
