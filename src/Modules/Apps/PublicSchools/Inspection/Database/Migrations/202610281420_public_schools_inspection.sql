-- Inspection et suivi pédagogique (INS, T686) : grilles de visite paramétrables et versionnées (EX-INS-02), planning et visites
-- (EX-INS-01), réponses saisies hors ligne, comptage sur place comparé au registre (EX-INS-04, objectif O1), recommandations suivies
-- jusqu'à la visite suivante (EX-INS-03), signalements de frais exigés illégalement (EX-INS-05). Aucune donnée nominative d'élève.
CREATE TABLE pub_visit_grids (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    code VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    items TEXT NOT NULL,
    active INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_visit_grids_version ON pub_visit_grids (tenant_id, code, version);

CREATE TABLE pub_visits (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    inspector_id VARCHAR(40) NOT NULL,
    planned_on VARCHAR(10) NOT NULL,
    visit_state VARCHAR(12) NOT NULL,
    done_on VARCHAR(10) NULL,
    structure_id VARCHAR(40) NULL,
    teacher_person_id VARCHAR(40) NULL,
    grid_id CHAR(26) NULL,
    answers TEXT NULL,
    counted_present INTEGER NULL,
    registry_count INTEGER NULL,
    observations VARCHAR(2000) NULL,
    report_document_id CHAR(26) NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_visits_school ON pub_visits (tenant_id, establishment_id, planned_on);
CREATE INDEX pub_visits_inspector ON pub_visits (tenant_id, inspector_id, planned_on);

CREATE TABLE pub_recommendations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    visit_id CHAR(26) NOT NULL,
    label VARCHAR(500) NOT NULL,
    followup_state VARCHAR(16) NOT NULL,
    checked_visit_id CHAR(26) NULL,
    checked_note VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_recommendations_school ON pub_recommendations (tenant_id, establishment_id, followup_state);

CREATE TABLE pub_fee_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    reported_on VARCHAR(10) NOT NULL,
    reporter_kind VARCHAR(16) NOT NULL,
    fee_label VARCHAR(160) NOT NULL,
    amount INTEGER NULL,
    description VARCHAR(1000) NOT NULL,
    report_state VARCHAR(16) NOT NULL,
    resolution VARCHAR(1000) NULL,
    handled_by VARCHAR(40) NULL,
    handled_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_fee_reports_school ON pub_fee_reports (tenant_id, establishment_id, report_state);
