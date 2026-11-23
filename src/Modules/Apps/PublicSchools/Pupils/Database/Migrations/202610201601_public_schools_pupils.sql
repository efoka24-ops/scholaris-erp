CREATE TABLE pub_pupil_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    birth_act_ref VARCHAR(80) NULL,
    birth_act_verified INTEGER NOT NULL,
    home_language VARCHAR(40) NULL,
    is_displaced INTEGER NOT NULL,
    is_refugee INTEGER NOT NULL,
    is_orphan INTEGER NOT NULL,
    accommodation_type VARCHAR(40) NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_pupil_profiles_person ON pub_pupil_profiles (tenant_id, person_id);

CREATE TABLE pub_class_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    academic_year_id CHAR(26) NOT NULL,
    level_codes VARCHAR(80) NOT NULL,
    shift VARCHAR(12) NOT NULL,
    homeroom_staff_id CHAR(26) NULL,
    homeroom_from VARCHAR(10) NULL
);
CREATE UNIQUE INDEX pub_class_profiles_structure ON pub_class_profiles (tenant_id, structure_id);

CREATE TABLE pub_transfers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    from_establishment_id VARCHAR(40) NOT NULL,
    to_establishment_id VARCHAR(40) NOT NULL,
    state VARCHAR(16) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    requested_at VARCHAR(32) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    arrival_on VARCHAR(10) NULL,
    refusal_reason VARCHAR(500) NULL,
    exeat_document_id CHAR(26) NULL
);
CREATE INDEX pub_transfers_to ON pub_transfers (tenant_id, to_establishment_id, state);

CREATE TABLE pub_dropouts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    detected_on VARCHAR(10) NOT NULL,
    family_contacted_at VARCHAR(32) NULL,
    outcome VARCHAR(16) NULL
);
