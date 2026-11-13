-- Journal d'audit chaîné (append-only) et approbations à deux personnes.

CREATE TABLE audit_log (
    tenant_id VARCHAR(40) NOT NULL,
    seq INTEGER NOT NULL,
    actor_id VARCHAR(40) NULL,
    action VARCHAR(100) NOT NULL,
    object_type VARCHAR(60) NOT NULL,
    object_id VARCHAR(60) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip VARCHAR(45) NULL,
    device VARCHAR(255) NULL,
    occurred_at VARCHAR(32) NOT NULL,
    prev_hash CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    PRIMARY KEY (tenant_id, seq)
);

CREATE TABLE approvals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    kind VARCHAR(60) NOT NULL,
    subject_type VARCHAR(60) NOT NULL,
    subject_id VARCHAR(60) NOT NULL,
    reason TEXT NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    approved_by VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    decided_at VARCHAR(32) NULL
);

CREATE INDEX approvals_tenant_status ON approvals (tenant_id, status);
