-- Primaire privé, référentiel pédagogique (EX-PRI-05, 16, 17) : profils de niveau, domaines, matières par niveau, compétences et
-- modèles de référentiel. Les niveaux, classes et matières restent dans le socle (pedagogical_structures, subjects) : ces
-- tables ne les dupliquent pas, elles les complètent. Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE pri_level_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    level_structure_id VARCHAR(40) NOT NULL,
    section_org_unit_id VARCHAR(40) NULL,
    cycle_code VARCHAR(20) NULL,
    sort_order INTEGER NOT NULL,
    is_final_level INTEGER NOT NULL DEFAULT 0,
    is_exam_level INTEGER NOT NULL DEFAULT 0,
    language VARCHAR(5) NOT NULL DEFAULT 'fr',
    external_key VARCHAR(80) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_level_profiles_level ON pri_level_profiles (tenant_id, establishment_id, level_structure_id);
CREATE UNIQUE INDEX pri_level_profiles_key ON pri_level_profiles (tenant_id, establishment_id, external_key);

CREATE TABLE pri_domains (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NULL,
    sort_order INTEGER NOT NULL,
    external_key VARCHAR(80) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_domains_code ON pri_domains (tenant_id, establishment_id, academic_year_id, code);

CREATE TABLE pri_level_subjects (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    level_structure_id VARCHAR(40) NOT NULL,
    subject_id CHAR(26) NOT NULL,
    subject_code VARCHAR(40) NOT NULL,
    subject_name VARCHAR(160) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    evaluation_mode VARCHAR(12) NOT NULL,
    sort_order INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_level_subjects_unique ON pri_level_subjects (tenant_id, establishment_id, academic_year_id, level_structure_id, subject_id);
CREATE INDEX pri_level_subjects_level ON pri_level_subjects (tenant_id, level_structure_id);

CREATE TABLE pri_competencies (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    subject_id CHAR(26) NULL,
    level_structure_id VARCHAR(40) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(255) NOT NULL,
    label_en VARCHAR(255) NULL,
    sort_order INTEGER NOT NULL,
    external_key VARCHAR(80) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_competencies_code ON pri_competencies (tenant_id, establishment_id, academic_year_id, level_structure_id, code);
CREATE INDEX pri_competencies_level ON pri_competencies (tenant_id, level_structure_id);

CREATE TABLE pri_referential_templates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(160) NOT NULL,
    origin VARCHAR(12) NOT NULL,
    language VARCHAR(5) NOT NULL,
    payload TEXT NOT NULL,
    version INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_referential_templates_code ON pri_referential_templates (tenant_id, establishment_id, code);
