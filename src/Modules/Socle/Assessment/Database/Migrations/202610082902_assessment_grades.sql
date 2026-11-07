-- S09 Notes, historique append-only et demandes de correction.

CREATE TABLE grades (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    score DECIMAL(7,2) NULL,
    absence VARCHAR(12) NULL,
    qualitative VARCHAR(30) NULL,
    remark VARCHAR(255) NULL,
    version INTEGER NOT NULL,
    entered_by VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX grades_cell ON grades (tenant_id, assessment_id, person_id);
CREATE UNIQUE INDEX grades_client_uuid ON grades (tenant_id, client_uuid);
CREATE INDEX grades_person ON grades (tenant_id, person_id);

CREATE TABLE grade_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    grade_id CHAR(26) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    event_kind VARCHAR(20) NOT NULL,
    old_score DECIMAL(7,2) NULL,
    new_score DECIMAL(7,2) NULL,
    old_absence VARCHAR(12) NULL,
    new_absence VARCHAR(12) NULL,
    author VARCHAR(40) NOT NULL,
    reason VARCHAR(255) NULL,
    approved_by VARCHAR(40) NULL,
    happened_at VARCHAR(32) NOT NULL
);

CREATE INDEX grade_events_grade ON grade_events (tenant_id, grade_id);

CREATE TABLE grade_corrections (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    grade_id CHAR(26) NOT NULL,
    assessment_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    new_score DECIMAL(7,2) NULL,
    new_absence VARCHAR(12) NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    approval_id CHAR(26) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    decided_at VARCHAR(32) NULL
);

CREATE INDEX grade_corrections_scope ON grade_corrections (tenant_id, establishment_id, status);
