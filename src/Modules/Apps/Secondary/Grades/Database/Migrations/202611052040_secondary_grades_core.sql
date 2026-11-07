-- Notes du secondaire (lot E2-C, T868 à T870). Les épreuves, les notes, leur cycle et les corrections restent dans le socle
-- Assessment (D1) : ces tables ne portent que ce que le socle n'a pas. Identifiants du socle opaques (jamais lus en SQL).
-- Épreuves communes par niveau (EX-NOT-04) : une ligne par épreuve commune, liée aux épreuves du socle des classes du niveau.
CREATE TABLE se_common_tests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    level_code VARCHAR(12) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    held_on VARCHAR(10) NULL,
    organised_by VARCHAR(40) NOT NULL,
    assessment_ids TEXT NULL,
    status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX ix_se_common_tests_level ON se_common_tests (tenant_id, establishment_id, academic_year_id, level_code, period_id);

-- Saisine du surveillant général pour fraude (RG-NOT-3) : la note vaut zéro par le code d'absence « fraud » du socle ; la saisine
-- est tracée ici, traitée par une personne habilitée (aucune sanction n'est appliquée par l'application).
CREATE TABLE se_grade_fraud_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NULL,
    assessment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    reported_by VARCHAR(40) NOT NULL,
    note_text VARCHAR(500) NULL,
    status VARCHAR(12) NOT NULL,
    handled_by VARCHAR(40) NULL,
    handled_at VARCHAR(32) NULL,
    outcome_text VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_se_grade_fraud_reports_cell ON se_grade_fraud_reports (tenant_id, establishment_id, assessment_id, person_id);
CREATE INDEX ix_se_grade_fraud_reports_status ON se_grade_fraud_reports (tenant_id, establishment_id, status);

-- Journal des notifications des notes (NOT-04 note publiée, NOT-11 saisie en retard) : jamais deux fois le même message au même
-- destinataire pour le même événement.
CREATE TABLE se_grade_notifications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    notification_code VARCHAR(8) NOT NULL,
    event_ref VARCHAR(160) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    template_key VARCHAR(60) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_se_grade_notifications_event ON se_grade_notifications (tenant_id, establishment_id, notification_code, event_ref, recipient_person_id);
