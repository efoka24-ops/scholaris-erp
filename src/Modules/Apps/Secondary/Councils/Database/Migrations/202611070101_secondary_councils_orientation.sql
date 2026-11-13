-- Orientation secondaire : les vœux et avis réutilisent se_orientations (Enrolment) ; décisions et recours sont tracés séparément.
-- Le délai réglementaire est lu dans le réglage versionné secondary.appeal_days ; aucune valeur n'est imposée par cette migration.
-- Portable MySQL 8 et SQLite ; toutes les lignes sont cloisonnées par client et établissement.

CREATE TABLE se_orientation_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    case_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    stream_id VARCHAR(40) NOT NULL,
    reason TEXT NOT NULL,
    decision_on VARCHAR(10) NOT NULL,
    decided_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_orientation_decisions_case ON se_orientation_decisions (tenant_id, establishment_id, case_id);

CREATE TABLE se_orientation_appeals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    decision_id CHAR(26) NOT NULL,
    case_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    submitted_on VARCHAR(10) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    outcome_reason TEXT NULL,
    revised_stream_id VARCHAR(40) NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_orientation_appeals_decision ON se_orientation_appeals (tenant_id, establishment_id, decision_id);
CREATE INDEX se_orientation_appeals_class ON se_orientation_appeals (tenant_id, establishment_id, class_id, submitted_on);