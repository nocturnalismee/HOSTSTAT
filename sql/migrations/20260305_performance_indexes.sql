-- Performance indexes for ServMon
-- Run this migration to improve query performance

-- Index for metrics recorded_at queries (history queries)
CREATE INDEX IF NOT EXISTS idx_metrics_recorded_at ON metrics(recorded_at);
CREATE INDEX IF NOT EXISTS idx_metrics_history_recorded_at ON metrics_history(recorded_at);

-- Composite index for server + recorded_at (frequently used in JOINs)
CREATE INDEX IF NOT EXISTS idx_metrics_server_recorded ON metrics(server_id, recorded_at DESC);

-- Index for service_metrics time-based queries
CREATE INDEX IF NOT EXISTS idx_service_metrics_recorded_at ON service_metrics(recorded_at);

-- Index for ping_checks time-based queries  
CREATE INDEX IF NOT EXISTS idx_ping_checks_checked_at ON ping_checks(checked_at);

-- Index for alert_logs server + time queries
CREATE INDEX IF NOT EXISTS idx_alert_logs_server_created ON alert_logs(server_id, created_at DESC);

-- Index for server_states
CREATE INDEX IF NOT EXISTS idx_server_states_is_down ON server_states(is_down);
