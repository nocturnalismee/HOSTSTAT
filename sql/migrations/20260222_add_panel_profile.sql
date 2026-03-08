USE servmon;

ALTER TABLE metrics
    ADD COLUMN IF NOT EXISTS panel_profile VARCHAR(20) NOT NULL DEFAULT 'generic' AFTER mail_queue_total;
