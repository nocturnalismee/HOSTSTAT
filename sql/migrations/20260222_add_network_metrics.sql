USE servmon;

ALTER TABLE metrics
    ADD COLUMN IF NOT EXISTS network_in_bps BIGINT NOT NULL DEFAULT 0 AFTER cpu_load,
    ADD COLUMN IF NOT EXISTS network_out_bps BIGINT NOT NULL DEFAULT 0 AFTER network_in_bps;
