-- Évaluation par compétences de la Primaire publique (T662 à T664, T666) : saisie des critères par occasion (API-06), mentions calculées
-- (unité, trimestre, année), remédiation tracée (registre et fiche de suivi individualisée : arrêté conjoint du 14 août 2026), bulletins
-- (DOC-08 à DOC-10) et banque d'appréciations, notes chiffrées optionnelles (EX-EVA-08). Aucun rang, aucune donnée APEE (RG-EVA-1, RG-DOC-3).
CREATE TABLE pp_criterion_checks (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    unit_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    criterion_id CHAR(26) NOT NULL,
    occasion_no INTEGER NOT NULL,
    outcome VARCHAR(8) NOT NULL,
    entered_by VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    entered_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_checks_cell ON pp_criterion_checks (tenant_id, unit_id, person_id, criterion_id, occasion_no);
CREATE INDEX pp_checks_class ON pp_criterion_checks (tenant_id, establishment_id, unit_id, structure_id);

CREATE TABLE pp_check_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    unit_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    cells_count INTEGER NOT NULL,
    entered_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_check_batches_uuid ON pp_check_batches (tenant_id, client_uuid);

CREATE TABLE pp_mentions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    scope_kind VARCHAR(10) NOT NULL,
    scope_ref VARCHAR(40) NOT NULL,
    mention VARCHAR(16) NOT NULL,
    perfection_mastered INTEGER NOT NULL,
    rule_version VARCHAR(60) NOT NULL,
    overridden_reason VARCHAR(500) NULL,
    overridden_by VARCHAR(40) NULL,
    parent_notified_at VARCHAR(32) NULL,
    computed_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_mentions_scope ON pp_mentions (tenant_id, person_id, frame_id, scope_kind, scope_ref);
CREATE INDEX pp_mentions_class ON pp_mentions (tenant_id, establishment_id, structure_id, scope_kind, scope_ref);

CREATE TABLE pp_remediations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    unit_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    criterion_id CHAR(26) NOT NULL,
    remediation_state VARCHAR(12) NOT NULL,
    remediation_form VARCHAR(24) NULL,
    outcome_note VARCHAR(500) NULL,
    updated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_remediations_cell ON pp_remediations (tenant_id, unit_id, person_id, criterion_id);
CREATE INDEX pp_remediations_person ON pp_remediations (tenant_id, establishment_id, person_id);

CREATE TABLE pp_remediation_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    unit_id CHAR(26) NULL,
    held_on VARCHAR(10) NOT NULL,
    duration_minutes INTEGER NOT NULL,
    remediation_form VARCHAR(24) NOT NULL,
    criterion_ids TEXT NOT NULL,
    person_ids TEXT NOT NULL,
    notes VARCHAR(500) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pp_remediation_sessions_class ON pp_remediation_sessions (tenant_id, establishment_id, structure_id, held_on);

CREATE TABLE pp_report_cards (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    card_kind VARCHAR(12) NOT NULL,
    scope_ref VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    appraisal VARCHAR(1000) NULL,
    card_state VARCHAR(12) NOT NULL,
    content TEXT NOT NULL,
    document_id CHAR(26) NULL,
    generated_by VARCHAR(40) NOT NULL,
    published_by VARCHAR(40) NULL,
    published_at VARCHAR(32) NULL,
    rectified_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_report_cards_scope ON pp_report_cards (tenant_id, person_id, card_kind, scope_ref);
CREATE INDEX pp_report_cards_class ON pp_report_cards (tenant_id, establishment_id, structure_id, card_kind, scope_ref);

CREATE TABLE pp_appraisal_phrases (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    phrase VARCHAR(300) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pp_appraisal_phrases_school ON pp_appraisal_phrases (tenant_id, establishment_id, locale);

CREATE TABLE pp_unit_scores (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    unit_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    score_hundredths INTEGER NOT NULL,
    scale_max INTEGER NOT NULL,
    entered_by VARCHAR(40) NOT NULL,
    entered_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pp_unit_scores_cell ON pp_unit_scores (tenant_id, unit_id, person_id, frame_id);
