-- Conformité (S13) : demandes des personnes concernées, paramètres de confidentialité, passages de rétention.

CREATE TABLE data_subject_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    subject_person_id VARCHAR(60) NOT NULL,
    details TEXT NULL,
    status VARCHAR(12) NOT NULL,
    received_at VARCHAR(32) NOT NULL,
    due_at VARCHAR(32) NOT NULL,
    started_at VARCHAR(32) NULL,
    processed_at VARCHAR(32) NULL,
    refusal_reason TEXT NULL,
    resolution TEXT NULL,
    approval_id CHAR(26) NULL,
    created_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL
);

CREATE INDEX dsr_tenant_status ON data_subject_requests (tenant_id, status);
CREATE INDEX dsr_tenant_subject ON data_subject_requests (tenant_id, subject_person_id);

CREATE TABLE privacy_settings (
    tenant_id VARCHAR(40) NOT NULL,
    setting_name VARCHAR(60) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (tenant_id, setting_name)
);

CREATE TABLE retention_runs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    applied INTEGER NOT NULL,
    summary_json TEXT NOT NULL,
    run_at VARCHAR(32) NOT NULL
);

CREATE INDEX retention_runs_tenant ON retention_runs (tenant_id);
