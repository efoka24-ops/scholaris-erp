-- Application secondaire, sous-module Enrolment (T823) : dossier secondaire de l'élève (redoublement, exclusion
-- définitive et décision de réadmission), trace des inscriptions de l'application (alerte d'âge et décision du chef),
-- préinscriptions, transferts et exeat, orientations (module orientation, lot E3). L'inscription elle-même reste celle du socle.
-- Le matricule national est tenu par CarteScolaire (se_cs_students, contrat NationalIds), jamais dupliqué ici. Portable MySQL 8 et SQLite.

CREATE TABLE se_student_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    repeater INTEGER NOT NULL DEFAULT 0,
    final_exclusion_on VARCHAR(10) NULL,
    final_exclusion_ref VARCHAR(80) NULL,
    readmission_year_id VARCHAR(40) NULL,
    readmission_reason VARCHAR(255) NULL,
    readmission_decided_by VARCHAR(40) NULL,
    special_situation VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_student_profiles_person ON se_student_profiles (tenant_id, establishment_id, person_id);

CREATE TABLE se_enrolment_records (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrollment_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    class_profile_id CHAR(26) NOT NULL,
    level_code VARCHAR(20) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    entry_kind VARCHAR(16) NOT NULL,
    entry_date VARCHAR(10) NOT NULL,
    repeater INTEGER NOT NULL DEFAULT 0,
    age_alert INTEGER NOT NULL DEFAULT 0,
    age_decision_reason VARCHAR(255) NULL,
    exclusion_decision_reason VARCHAR(255) NULL,
    decided_by VARCHAR(40) NULL,
    documents_checked TEXT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_enrolment_records_enrollment ON se_enrolment_records (tenant_id, establishment_id, enrollment_id);
CREATE INDEX se_enrolment_records_year ON se_enrolment_records (tenant_id, establishment_id, academic_year_id, class_structure_id);

CREATE TABLE se_preregistrations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NULL,
    last_name VARCHAR(120) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    birth_date VARCHAR(10) NULL,
    gender VARCHAR(10) NULL,
    guardian_name VARCHAR(160) NULL,
    guardian_phone VARCHAR(20) NULL,
    target_level_code VARCHAR(20) NOT NULL,
    stream_id CHAR(26) NULL,
    channel VARCHAR(12) NOT NULL,
    entry_test_score DECIMAL(5,2) NULL,
    status VARCHAR(12) NOT NULL,
    documents_checked TEXT NULL,
    national_id VARCHAR(40) NULL,
    source_ref VARCHAR(80) NULL,
    source_payload TEXT NULL,
    enrollment_id VARCHAR(40) NULL,
    decision_reason VARCHAR(255) NULL,
    decided_by VARCHAR(40) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_preregistrations_status ON se_preregistrations (tenant_id, establishment_id, status, target_level_code);
CREATE UNIQUE INDEX se_preregistrations_source ON se_preregistrations (tenant_id, establishment_id, source_ref);

CREATE TABLE se_transfers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    direction VARCHAR(4) NOT NULL,
    other_school_label VARCHAR(160) NOT NULL,
    transfer_date VARCHAR(10) NOT NULL,
    exeat_document_id VARCHAR(40) NULL,
    national_id_checked INTEGER NOT NULL DEFAULT 0,
    national_id_match VARCHAR(12) NULL,
    file_ref VARCHAR(120) NULL,
    enrollment_id VARCHAR(40) NULL,
    reason VARCHAR(255) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_transfers_person ON se_transfers (tenant_id, establishment_id, person_id);

CREATE TABLE se_orientations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    wishes TEXT NULL,
    counsellor_opinion VARCHAR(255) NULL,
    council_opinion VARCHAR(255) NULL,
    proposed_stream_id CHAR(26) NULL,
    final_stream_id CHAR(26) NULL,
    interview_on VARCHAR(10) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_orientations_one ON se_orientations (tenant_id, person_id, academic_year_id);
