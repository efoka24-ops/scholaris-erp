-- Primaire privé, conseil des maîtres de fin de période (EX-PRI-25, 27) : un conseil par classe et par trimestre, présidé par la
-- direction ; une ligne par élève (moyennes chiffrées facultatives et rang venant du moteur du socle, absences en demi-journées,
-- conduite, synthèse des compétences, appréciation, remédiation, proposition et décision de fin d'année au dernier trimestre).
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_councils (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    level_structure_id VARCHAR(40) NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(40) NULL,
    period_id VARCHAR(40) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    period_kind VARCHAR(12) NOT NULL,
    term_ordinal INTEGER NOT NULL,
    is_final_term INTEGER NOT NULL DEFAULT 0,
    child_period_ids TEXT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    held_on VARCHAR(10) NULL,
    status VARCHAR(12) NOT NULL,
    numeric_enabled INTEGER NOT NULL DEFAULT 0,
    rule_version INTEGER NULL,
    summary TEXT NULL,
    minutes TEXT NULL,
    created_by VARCHAR(40) NOT NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_councils_cell ON pri_councils (tenant_id, establishment_id, class_structure_id, period_id);
CREATE INDEX pri_councils_year ON pri_councils (tenant_id, establishment_id, academic_year_id, status);

CREATE TABLE pri_council_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    council_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    average_value VARCHAR(16) NULL,
    rank_in_class INTEGER NULL,
    class_size INTEGER NULL,
    subjects_json TEXT NULL,
    annual_average VARCHAR(16) NULL,
    annual_rank INTEGER NULL,
    terms_json TEXT NULL,
    absences_half_days INTEGER NOT NULL DEFAULT 0,
    unjustified_half_days INTEGER NOT NULL DEFAULT 0,
    lates INTEGER NOT NULL DEFAULT 0,
    conduct_code VARCHAR(20) NULL,
    competency_summary TEXT NULL,
    appraisal VARCHAR(500) NULL,
    remediation_flag INTEGER NOT NULL DEFAULT 0,
    remediation_note VARCHAR(255) NULL,
    repeater INTEGER NOT NULL DEFAULT 0,
    proposal VARCHAR(20) NULL,
    decision VARCHAR(20) NULL,
    reason VARCHAR(255) NULL,
    edited_fields VARCHAR(160) NULL,
    updated_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_council_lines_cell ON pri_council_lines (tenant_id, establishment_id, council_id, person_id);
CREATE INDEX pri_council_lines_person ON pri_council_lines (tenant_id, establishment_id, person_id);
