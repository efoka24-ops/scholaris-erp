-- S09 Évaluation : référentiel (matières, unités d'enseignement, éléments), épreuves et règles de calcul versionnées.

CREATE TABLE subjects (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    coefficient DECIMAL(7,2) NOT NULL,
    credits INTEGER NOT NULL,
    active INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX subjects_code ON subjects (tenant_id, establishment_id, code);

CREATE TABLE subject_elements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject_id CHAR(26) NOT NULL,
    name VARCHAR(160) NOT NULL,
    coefficient DECIMAL(7,2) NOT NULL,
    credits INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX subject_elements_subject ON subject_elements (tenant_id, subject_id);

CREATE TABLE assessments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject_id CHAR(26) NOT NULL,
    element_id CHAR(26) NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NULL,
    kind VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    weight DECIMAL(7,2) NOT NULL,
    max_score DECIMAL(7,2) NOT NULL,
    grading_mode VARCHAR(12) NOT NULL,
    anonymous INTEGER NOT NULL,
    status VARCHAR(30) NOT NULL,
    teacher_person_id VARCHAR(40) NULL,
    held_on VARCHAR(10) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX assessments_scope ON assessments (tenant_id, establishment_id, structure_id, period_id);
CREATE INDEX assessments_subject ON assessments (tenant_id, subject_id);

CREATE TABLE calculation_rules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    label VARCHAR(160) NOT NULL,
    definition TEXT NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX calculation_rules_version ON calculation_rules (tenant_id, establishment_id, academic_year_id, version);
