-- S08 Discipline (incidents, sanctions à valider) et personnes autorisées à récupérer un enfant.

CREATE TABLE incidents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    occurred_on VARCHAR(10) NOT NULL,
    category VARCHAR(40) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    reported_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX incidents_person ON incidents (tenant_id, establishment_id, person_id);

CREATE TABLE sanctions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    incident_id CHAR(26) NULL,
    person_id VARCHAR(40) NOT NULL,
    kind VARCHAR(40) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(10) NOT NULL,
    approval_id CHAR(26) NOT NULL,
    proposed_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX sanctions_person ON sanctions (tenant_id, establishment_id, person_id);
CREATE INDEX sanctions_status ON sanctions (tenant_id, establishment_id, status);

CREATE TABLE authorized_pickups (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    pickup_name VARCHAR(160) NOT NULL,
    relationship VARCHAR(60) NOT NULL,
    phone VARCHAR(30) NULL,
    photo_document_ref VARCHAR(80) NULL,
    grant_type VARCHAR(10) NOT NULL,
    valid_on VARCHAR(10) NULL,
    granted_by VARCHAR(40) NOT NULL,
    status VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX authorized_pickups_learner ON authorized_pickups (tenant_id, establishment_id, learner_id);
