-- Supérieur, relations avec l'université de tutelle (TUT, EX-TUT-01 à 04, RG-TUT-1/2, T310, T311, T306).
-- Transmissions (listes DOC-18, notes, relevés, PV) avec empreinte du lot ; dossiers de diplôme transmis seulement après
-- délibération finale signée et quitus (RG-TUT-2) ; journal des consultations de la tutelle (R22, EX-TUT-04).

CREATE TABLE hi_tutelage_transmissions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NULL,
    tutelage_id VARCHAR(40) NOT NULL,
    university_name VARCHAR(200) NOT NULL,
    transmission_kind VARCHAR(20) NOT NULL,
    status VARCHAR(16) NOT NULL,
    items_count INTEGER NOT NULL DEFAULT 0,
    fingerprint CHAR(64) NOT NULL,
    file_name VARCHAR(160) NULL,
    note VARCHAR(500) NULL,
    sent_by VARCHAR(40) NOT NULL,
    sent_at VARCHAR(32) NOT NULL,
    answered_at VARCHAR(32) NULL,
    answered_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX hi_tutelage_transmissions_program ON hi_tutelage_transmissions (tenant_id, establishment_id, program_id, academic_year_id);

CREATE TABLE hi_diploma_files (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrolment_id VARCHAR(40) NOT NULL,
    tutelage_id VARCHAR(40) NOT NULL,
    university_name VARCHAR(200) NOT NULL,
    decision_code VARCHAR(40) NOT NULL,
    status VARCHAR(16) NOT NULL,
    status_note VARCHAR(500) NULL,
    transmitted_at VARCHAR(32) NOT NULL,
    status_changed_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_diploma_files_unique ON hi_diploma_files (tenant_id, establishment_id, person_id, program_id, academic_year_id);

CREATE TABLE hi_tutelage_consultations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    resource_kind VARCHAR(20) NOT NULL,
    rows_count INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX hi_tutelage_consultations_program ON hi_tutelage_consultations (tenant_id, establishment_id, program_id, created_at);
