-- Primaire privé, évaluation (EX-PRI-20, 31) : évaluations de compétence par élève et période, historique, état de verrouillage de la
-- feuille d'une classe, corrections approuvées après verrouillage et conduite de la période. Les notes chiffrées restent dans le
-- socle (grades). Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE pri_competency_marks (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    competency_id CHAR(26) NOT NULL,
    scale_code VARCHAR(20) NULL,
    absence_code VARCHAR(12) NULL,
    remark VARCHAR(255) NULL,
    status VARCHAR(12) NOT NULL,
    entered_by VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_competency_marks_cell ON pri_competency_marks (tenant_id, establishment_id, person_id, period_id, competency_id);
CREATE UNIQUE INDEX pri_competency_marks_uuid ON pri_competency_marks (tenant_id, client_uuid);
CREATE INDEX pri_competency_marks_class ON pri_competency_marks (tenant_id, establishment_id, class_structure_id, period_id);

CREATE TABLE pri_competency_mark_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    mark_id CHAR(26) NOT NULL,
    previous_code VARCHAR(20) NULL,
    new_code VARCHAR(20) NULL,
    actor_id VARCHAR(40) NOT NULL,
    reason VARCHAR(255) NULL,
    client_uuid VARCHAR(40) NULL,
    occurred_at VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_competency_mark_events_mark ON pri_competency_mark_events (tenant_id, mark_id);
CREATE UNIQUE INDEX pri_competency_mark_events_uuid ON pri_competency_mark_events (tenant_id, client_uuid);

CREATE TABLE pri_sheet_states (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    locked_by VARCHAR(40) NULL,
    locked_at VARCHAR(32) NULL,
    published_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_sheet_states_cell ON pri_sheet_states (tenant_id, establishment_id, class_structure_id, period_id);

CREATE TABLE pri_competency_corrections (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    mark_id CHAR(26) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    new_code VARCHAR(20) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_competency_corrections_class ON pri_competency_corrections (tenant_id, establishment_id, class_structure_id, status);

CREATE TABLE pri_conduct_marks (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    scale_code VARCHAR(20) NOT NULL,
    remark VARCHAR(255) NULL,
    entered_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_conduct_marks_cell ON pri_conduct_marks (tenant_id, establishment_id, person_id, period_id);
CREATE INDEX pri_conduct_marks_class ON pri_conduct_marks (tenant_id, establishment_id, class_structure_id, period_id);
