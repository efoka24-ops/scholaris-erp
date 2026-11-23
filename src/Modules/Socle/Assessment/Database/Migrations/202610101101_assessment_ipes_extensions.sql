-- S09 Extensions pour les applications (IPES) : catalogue de programme (D9), pas de saisie et session des épreuves
-- (S2, S7), approbation à n niveaux (S3), règles rattachées à une structure (S5), session des résultats et des
-- délibérations avec empreinte et historique des procès-verbaux (S7, S8). Une seule colonne par ALTER TABLE.

ALTER TABLE subjects ADD COLUMN external_key VARCHAR(80) NULL;
ALTER TABLE subject_elements ADD COLUMN external_key VARCHAR(80) NULL;
ALTER TABLE subject_elements ADD COLUMN code VARCHAR(40) NULL;
ALTER TABLE subject_elements ADD COLUMN active INTEGER NOT NULL DEFAULT 1;
CREATE UNIQUE INDEX subjects_external ON subjects (tenant_id, establishment_id, external_key);
CREATE UNIQUE INDEX subject_elements_external ON subject_elements (tenant_id, establishment_id, external_key);

ALTER TABLE assessments ADD COLUMN score_step DECIMAL(5,2) NULL;
ALTER TABLE assessments ADD COLUMN exam_session VARCHAR(12) NOT NULL DEFAULT 'normal';

ALTER TABLE grade_corrections ADD COLUMN approval_step INTEGER NOT NULL DEFAULT 0;
ALTER TABLE grade_corrections ADD COLUMN approval_trail TEXT NULL;

ALTER TABLE calculation_rules ADD COLUMN structure_id VARCHAR(40) NULL;
CREATE INDEX calculation_rules_scope ON calculation_rules (tenant_id, establishment_id, academic_year_id, structure_id);

-- Résultats : la clé d'unicité s'élargit à la session. Reconstruction portable (MySQL 8 et SQLite) : nouvelle table,
-- copie, suppression, renommage, puis index.
CREATE TABLE results_rebuilt (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    exam_session VARCHAR(12) NOT NULL DEFAULT 'normal',
    period_label VARCHAR(80) NOT NULL,
    average DECIMAL(7,2) NULL,
    rank_in_class INTEGER NULL,
    class_size INTEGER NULL,
    credits_earned INTEGER NULL,
    credits_total INTEGER NULL,
    mention VARCHAR(60) NULL,
    proposal VARCHAR(60) NULL,
    decision VARCHAR(60) NULL,
    rule_version INTEGER NOT NULL,
    details TEXT NOT NULL,
    computed_extras TEXT NULL,
    status VARCHAR(12) NOT NULL,
    computed_at VARCHAR(32) NOT NULL,
    published_at VARCHAR(32) NULL
);

INSERT INTO results_rebuilt (id, tenant_id, establishment_id, person_id, structure_id, period_id, exam_session, period_label, average, rank_in_class, class_size, credits_earned, credits_total, mention, proposal, decision, rule_version, details, computed_extras, status, computed_at, published_at)
SELECT id, tenant_id, establishment_id, person_id, structure_id, period_id, 'normal', period_label, average, rank_in_class, class_size, credits_earned, credits_total, mention, proposal, decision, rule_version, details, NULL, status, computed_at, published_at FROM results;

DROP TABLE results;
ALTER TABLE results_rebuilt RENAME TO results;
CREATE UNIQUE INDEX results_cell ON results (tenant_id, establishment_id, person_id, period_id, exam_session);
CREATE INDEX results_structure ON results (tenant_id, establishment_id, structure_id, period_id);

CREATE TABLE deliberations_rebuilt (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    exam_session VARCHAR(12) NOT NULL DEFAULT 'normal',
    period_label VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL,
    president_id VARCHAR(40) NOT NULL,
    members_expected INTEGER NOT NULL,
    members_present TEXT NOT NULL,
    quorum_reached INTEGER NOT NULL,
    rule_version INTEGER NOT NULL,
    summary TEXT NOT NULL,
    minutes TEXT NULL,
    minutes_document_id VARCHAR(40) NULL,
    minutes_status VARCHAR(20) NULL,
    minutes_fingerprint VARCHAR(64) NULL,
    minutes_version INTEGER NOT NULL DEFAULT 0,
    minutes_history TEXT NULL,
    signatures TEXT NULL,
    reopen_reason VARCHAR(255) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    closed_at VARCHAR(32) NULL
);

INSERT INTO deliberations_rebuilt (id, tenant_id, establishment_id, structure_id, period_id, exam_session, period_label, status, president_id, members_expected, members_present, quorum_reached, rule_version, summary, minutes, minutes_document_id, minutes_status, minutes_fingerprint, minutes_version, minutes_history, signatures, reopen_reason, created_by, created_at, closed_at)
SELECT id, tenant_id, establishment_id, structure_id, period_id, 'normal', period_label, status, president_id, members_expected, members_present, quorum_reached, rule_version, summary, minutes, minutes_document_id, minutes_status, NULL, 0, NULL, signatures, reopen_reason, created_by, created_at, closed_at FROM deliberations;

DROP TABLE deliberations;
ALTER TABLE deliberations_rebuilt RENAME TO deliberations;
CREATE UNIQUE INDEX deliberations_cell ON deliberations (tenant_id, establishment_id, structure_id, period_id, exam_session);

ALTER TABLE deliberation_lines ADD COLUMN line_extras TEXT NULL;
