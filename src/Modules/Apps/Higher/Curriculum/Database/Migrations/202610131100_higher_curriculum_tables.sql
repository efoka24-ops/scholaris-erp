-- Supérieur, maquettes (EX-MAQ-01 à 06) : maquette par filière, niveau, année et version ; semestres, UE, EC.
-- Une maquette publiée n'est jamais modifiée sur place : nouvelle version (RG-MAQ-1) ; instantané à chaque publication.

CREATE TABLE hi_curricula (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    regime VARCHAR(12) NOT NULL,
    version INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    title VARCHAR(160) NOT NULL,
    approved_programme_ref VARCHAR(120) NULL,
    previous_version_id CHAR(26) NULL,
    copied_from_id CHAR(26) NULL,
    published_at VARCHAR(32) NULL,
    published_by VARCHAR(40) NULL,
    grades_recorded_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_curricula_version ON hi_curricula (tenant_id, establishment_id, program_id, level_id, academic_year_id, version);

CREATE TABLE hi_curriculum_semesters (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    curriculum_id CHAR(26) NOT NULL,
    ordinal INTEGER NOT NULL,
    label VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_curriculum_semesters_ordinal ON hi_curriculum_semesters (tenant_id, establishment_id, curriculum_id, ordinal);

-- UE (LMD, certificat) ou matière (BTS, HND : coefficient et épreuve de l'examen national).
CREATE TABLE hi_curriculum_units (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    curriculum_id CHAR(26) NOT NULL,
    semester_id CHAR(26) NOT NULL,
    code VARCHAR(20) NOT NULL,
    title VARCHAR(160) NOT NULL,
    unit_type VARCHAR(16) NOT NULL,
    credits INTEGER NOT NULL,
    coefficient DECIMAL(6,2) NOT NULL,
    choice_group VARCHAR(20) NULL,
    choice_count INTEGER NULL,
    national_exam_paper VARCHAR(40) NULL,
    cc_weight_percent INTEGER NULL,
    sort_index INTEGER NOT NULL,
    catalog_subject_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_curriculum_units_code ON hi_curriculum_units (tenant_id, establishment_id, curriculum_id, code);

-- EC : crédits, coefficient, volumes en minutes (CM, TD, TP, travail personnel).
CREATE TABLE hi_curriculum_elements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    curriculum_id CHAR(26) NOT NULL,
    unit_id CHAR(26) NOT NULL,
    code VARCHAR(20) NOT NULL,
    title VARCHAR(160) NOT NULL,
    credits INTEGER NOT NULL,
    coefficient DECIMAL(6,2) NOT NULL,
    cm_minutes INTEGER NOT NULL,
    td_minutes INTEGER NOT NULL,
    tp_minutes INTEGER NOT NULL,
    personal_minutes INTEGER NOT NULL,
    cc_weight_percent INTEGER NULL,
    sort_index INTEGER NOT NULL,
    catalog_element_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_curriculum_elements_code ON hi_curriculum_elements (tenant_id, establishment_id, curriculum_id, code);

CREATE INDEX hi_curriculum_elements_unit ON hi_curriculum_elements (tenant_id, establishment_id, unit_id);

-- Instantané immuable de chaque publication (contenu JSON complet).
CREATE TABLE hi_curriculum_snapshots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    curriculum_id CHAR(26) NOT NULL,
    version INTEGER NOT NULL,
    content TEXT NOT NULL,
    published_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_curriculum_snapshots_curriculum ON hi_curriculum_snapshots (tenant_id, establishment_id, curriculum_id);
