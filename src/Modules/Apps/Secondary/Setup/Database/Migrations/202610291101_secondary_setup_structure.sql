-- Application secondaire, sous-module Setup (T820) : niveaux, séries et spécialités ouvertes, profils de classe, matières,
-- coefficients et volumes horaires versionnés, gel d'une année, profils de salles, règlement intérieur versionné.
-- Rien n'est en dur : niveaux, matières, coefficients et volumes sont PARAMÉTRÉS PAR L'ÉTABLISSEMENT (valeurs d'exemple marquées
-- « example » et à confirmer). Chaque version de coefficient garde auteur, date et motif ; une année gelée n'est jamais recalculée.
-- Portable MySQL 8 et SQLite : pas de clé étrangère, pas d'AUTO_INCREMENT, aucun mot réservé ; client et établissement partout.

CREATE TABLE se_levels (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(20) NOT NULL,
    label VARCHAR(120) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    teaching_kind VARCHAR(12) NOT NULL,
    sort_order INTEGER NOT NULL,
    exam_code VARCHAR(20) NULL,
    structure_id VARCHAR(40) NOT NULL,
    origin VARCHAR(12) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_levels_code ON se_levels (tenant_id, establishment_id, section_code, teaching_kind, code);

CREATE TABLE se_streams (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    stream_ref_id CHAR(26) NOT NULL,
    stream_kind VARCHAR(12) NOT NULL,
    teaching_kind VARCHAR(12) NOT NULL,
    code VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    authorization_id VARCHAR(40) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_streams_ref ON se_streams (tenant_id, establishment_id, stream_ref_id);

CREATE TABLE se_class_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    section_code VARCHAR(4) NOT NULL,
    teaching_kind VARCHAR(12) NOT NULL,
    cycle_code VARCHAR(10) NOT NULL,
    level_id CHAR(26) NOT NULL,
    level_code VARCHAR(20) NOT NULL,
    stream_id CHAR(26) NULL,
    exam_code VARCHAR(20) NULL,
    capacity INTEGER NULL,
    head_teacher_person_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_class_profiles_structure ON se_class_profiles (tenant_id, structure_id, academic_year_id);
CREATE INDEX se_class_profiles_year ON se_class_profiles (tenant_id, establishment_id, academic_year_id, level_code);

CREATE TABLE se_subjects (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NULL,
    subject_id VARCHAR(40) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_subjects_code ON se_subjects (tenant_id, establishment_id, code);

CREATE TABLE se_subject_coefficients (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    se_subject_id CHAR(26) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    level_code VARCHAR(20) NOT NULL,
    stream_id CHAR(26) NULL,
    coefficient DECIMAL(5,2) NOT NULL,
    weekly_hours DECIMAL(5,2) NOT NULL,
    subject_role VARCHAR(12) NOT NULL,
    origin VARCHAR(10) NOT NULL,
    locked INTEGER NOT NULL DEFAULT 0,
    source_ref VARCHAR(120) NULL,
    version_no INTEGER NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    change_reason VARCHAR(255) NOT NULL,
    changed_by VARCHAR(40) NOT NULL,
    superseded_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_subject_coefficients_lookup ON se_subject_coefficients (tenant_id, establishment_id, academic_year_id, level_code, version_no);

CREATE TABLE se_coefficient_freezes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    frozen_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_coefficient_freezes_year ON se_coefficient_freezes (tenant_id, establishment_id, academic_year_id);

CREATE TABLE se_room_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    room_id VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    room_kind VARCHAR(12) NOT NULL,
    capacity INTEGER NULL,
    specialty_stream_id CHAR(26) NULL,
    equipment TEXT NULL,
    safety_notes TEXT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_room_profiles_room ON se_room_profiles (tenant_id, establishment_id, room_id);

CREATE TABLE se_school_rules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    rule_key VARCHAR(60) NOT NULL,
    value_json TEXT NOT NULL,
    version_no INTEGER NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    change_reason VARCHAR(255) NOT NULL,
    changed_by VARCHAR(40) NOT NULL,
    superseded_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_school_rules_key ON se_school_rules (tenant_id, establishment_id, rule_key, version_no);
