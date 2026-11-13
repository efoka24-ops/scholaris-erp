-- Rapports planifies (T134) : definitions, journal d'execution idempotent, et modele d'e-mail de la plateforme.

CREATE TABLE report_schedules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    report_type VARCHAR(20) NOT NULL,
    frequency VARCHAR(10) NOT NULL,
    run_day INTEGER NULL,
    recipient_ids TEXT NOT NULL,
    status VARCHAR(10) NOT NULL,
    next_run_on VARCHAR(10) NOT NULL,
    last_run_at VARCHAR(32) NULL,
    last_run_status VARCHAR(20) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX report_schedules_due ON report_schedules (status, next_run_on);
CREATE INDEX report_schedules_tenant ON report_schedules (tenant_id, establishment_id);

CREATE TABLE report_runs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    schedule_id CHAR(26) NOT NULL,
    run_key VARCHAR(40) NOT NULL,
    trigger_kind VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL,
    sent_count INTEGER NOT NULL,
    failed_count INTEGER NOT NULL,
    summary VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX report_runs_key ON report_runs (tenant_id, schedule_id, run_key);

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01J9T0000000000000000PLT21', 'platform', 'platform', 'scheduled_report', 'fr', 'email', 'Rapport planifie : :label', 'Rapport :label du :date. :details. :establishment_name', '["label","date","details","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00'),
('01J9T0000000000000000PLT22', 'platform', 'platform', 'scheduled_report', 'en', 'email', 'Scheduled report: :label', 'Report :label of :date. :details. :establishment_name', '["label","date","details","establishment_name"]', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00');
