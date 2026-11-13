-- Ateliers, séances de travaux pratiques, sécurité, stock configurable, évaluations et stages (T908–T910).
-- Données entièrement cloisonnées par client et établissement, SQL portable MySQL 8 / SQLite.
CREATE TABLE se_workshops (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_id VARCHAR(40) NULL,
    room_id VARCHAR(40) NULL,
    label VARCHAR(160) NOT NULL,
    capacity INTEGER NULL,
    chief_person_id VARCHAR(40) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_workshops_establishment ON se_workshops (tenant_id, establishment_id, label);

CREATE TABLE se_workstations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    label VARCHAR(160) NOT NULL,
    machine INTEGER NOT NULL,
    ppe_required TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_workstations_workshop ON se_workstations (tenant_id, establishment_id, workshop_id);

CREATE TABLE se_practical_sheets (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    objective VARCHAR(1000) NOT NULL,
    materials TEXT NOT NULL,
    tools TEXT NOT NULL,
    safety_rules TEXT NOT NULL,
    criteria TEXT NOT NULL,
    base_score DECIMAL(5,2) NOT NULL,
    safety_cap DECIMAL(5,2) NULL,
    version_no INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_practical_sheets_workshop ON se_practical_sheets (tenant_id, establishment_id, workshop_id, version_no);

CREATE TABLE se_workshop_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    sheet_id CHAR(26) NOT NULL,
    session_date VARCHAR(10) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    attendance TEXT NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_workshop_sessions_uuid ON se_workshop_sessions (tenant_id, establishment_id, client_uuid);
CREATE INDEX se_workshop_sessions_class ON se_workshop_sessions (tenant_id, establishment_id, structure_id, session_date);

CREATE TABLE se_material_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    label VARCHAR(160) NOT NULL,
    unit_label VARCHAR(40) NOT NULL,
    unit_cost INTEGER NOT NULL,
    stock_qty DECIMAL(10,2) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_material_items_workshop ON se_material_items (tenant_id, establishment_id, workshop_id, label);

CREATE TABLE se_material_moves (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    item_id CHAR(26) NOT NULL,
    move_kind VARCHAR(8) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    session_id CHAR(26) NULL,
    unit_cost INTEGER NOT NULL,
    moved_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_material_moves_item ON se_material_moves (tenant_id, establishment_id, item_id, created_at);

CREATE TABLE se_safety_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    session_id CHAR(26) NULL,
    event_kind VARCHAR(16) NOT NULL,
    person_id VARCHAR(40) NULL,
    workstation_id CHAR(26) NULL,
    description VARCHAR(1000) NOT NULL,
    occurred_at VARCHAR(32) NOT NULL,
    reported_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_safety_events_workshop ON se_safety_events (tenant_id, establishment_id, workshop_id, occurred_at);

CREATE TABLE se_practical_evaluations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    workshop_id CHAR(26) NOT NULL,
    session_id CHAR(26) NOT NULL,
    sheet_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    criteria_scores TEXT NOT NULL,
    raw_total DECIMAL(5,2) NOT NULL,
    serious_safety_breach INTEGER NOT NULL DEFAULT 0,
    final_score DECIMAL(5,2) NOT NULL,
    assessment_id VARCHAR(40) NULL,
    evaluated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX se_practical_evaluations_one ON se_practical_evaluations (tenant_id, establishment_id, session_id, person_id);

CREATE TABLE se_companies (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    sector VARCHAR(120) NULL,
    address VARCHAR(500) NULL,
    contact_name VARCHAR(120) NULL,
    contact_phone VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_companies_establishment ON se_companies (tenant_id, establishment_id, label);

CREATE TABLE se_internships (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    company_id CHAR(26) NOT NULL,
    tutor_name VARCHAR(120) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    agreement_document_id VARCHAR(40) NULL,
    tutor_appraisal TEXT NULL,
    report_received_on VARCHAR(10) NULL,
    score DECIMAL(5,2) NULL,
    score_coefficient DECIMAL(5,2) NULL,
    assessment_id VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_internships_company ON se_internships (tenant_id, establishment_id, company_id, starts_on);
CREATE INDEX se_internships_learner ON se_internships (tenant_id, establishment_id, person_id, starts_on);

CREATE TABLE se_internship_visits (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    internship_id CHAR(26) NOT NULL,
    visitor_person_id VARCHAR(40) NOT NULL,
    visited_on VARCHAR(10) NOT NULL,
    notes VARCHAR(2000) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX se_internship_visits_internship ON se_internship_visits (tenant_id, establishment_id, internship_id, visited_on);
