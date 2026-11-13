-- Supérieur, paramétrage académique (EX-ACA-01/02) : extensions 1-1 des unités et structures du socle.
-- L'identifiant de chaque ligne EST celui de l'unité (école, département : org_units) ou de la structure (filière, niveau,
-- groupe : pedagogical_structures) créée par Organisation\Contracts\StructureWriter : aucune double source de vérité.
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE hi_schools (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(160) NOT NULL,
    director_person_id VARCHAR(40) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_schools_code ON hi_schools (tenant_id, establishment_id, code);

CREATE TABLE hi_departments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(160) NOT NULL,
    head_person_id VARCHAR(40) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_departments_code ON hi_departments (tenant_id, establishment_id, school_id, code);

-- Filière ou spécialité (parent_program_id) : un seul régime, jamais modifié (RG-ORG-7).
CREATE TABLE hi_programs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    parent_program_id CHAR(26) NULL,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(160) NOT NULL,
    regime VARCHAR(12) NOT NULL,
    teaching_language VARCHAR(12) NOT NULL,
    study_mode VARCHAR(16) NOT NULL,
    duration_years INTEGER NOT NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_programs_code ON hi_programs (tenant_id, establishment_id, school_id, code);

CREATE INDEX hi_programs_department ON hi_programs (tenant_id, establishment_id, department_id);

CREATE TABLE hi_levels (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    ordinal INTEGER NOT NULL,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(160) NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_levels_ordinal ON hi_levels (tenant_id, establishment_id, program_id, ordinal);

CREATE TABLE hi_groups (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id CHAR(26) NOT NULL,
    department_id CHAR(26) NOT NULL,
    program_id CHAR(26) NOT NULL,
    level_id CHAR(26) NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(160) NOT NULL,
    capacity INTEGER NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_groups_code ON hi_groups (tenant_id, establishment_id, level_id, code);
