-- Reporting (PP5) : circuit de validation séquentiel générique (étapes fournies par l'appelant) et snapshots gelés versionnés.

CREATE TABLE reporting_workflows (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    workflow_id VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL,
    submitted_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX reporting_workflows_cell ON reporting_workflows (tenant_id, workflow_id);

CREATE TABLE reporting_workflow_steps (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    workflow_id VARCHAR(80) NOT NULL,
    position INTEGER NOT NULL,
    step_id VARCHAR(60) NOT NULL,
    status VARCHAR(10) NOT NULL,
    actor_id VARCHAR(40) NULL,
    reason VARCHAR(500) NULL,
    decided_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX reporting_workflow_steps_cell ON reporting_workflow_steps (tenant_id, workflow_id, position);

CREATE TABLE reporting_workflow_snapshots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    workflow_id VARCHAR(80) NOT NULL,
    version VARCHAR(40) NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    frozen_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX reporting_workflow_snapshots_cell ON reporting_workflow_snapshots (tenant_id, workflow_id, version);
