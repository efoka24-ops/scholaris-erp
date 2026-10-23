CREATE TABLE pub_schools (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    official_code VARCHAR(80) NOT NULL,
    creation_act_ref VARCHAR(120) NULL,
    creation_act_date VARCHAR(10) NULL,
    subsystem VARCHAR(16) NOT NULL,
    cycle VARCHAR(16) NOT NULL,
    school_group_ref CHAR(26) NULL,
    status VARCHAR(16) NOT NULL,
    latitude VARCHAR(20) NULL,
    longitude VARCHAR(20) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(160) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_schools_establishment ON pub_schools (tenant_id, establishment_id);
CREATE UNIQUE INDEX pub_schools_code ON pub_schools (tenant_id, official_code);

INSERT INTO pub_schools
    (id, tenant_id, establishment_id, official_code, creation_act_ref, creation_act_date, subsystem, cycle, school_group_ref, status, latitude, longitude, phone, email, created_at, updated_at)
SELECT id, tenant_id, establishment_id, school_code, NULL, NULL, 'francophone', 'primary', NULL, 'open', NULL, NULL, NULL, NULL, created_at, updated_at
FROM pp_school_profiles;

CREATE TABLE pub_infrastructure_years (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    year_label VARCHAR(9) NOT NULL,
    version INTEGER NOT NULL,
    state VARCHAR(16) NOT NULL,
    desks_count INTEGER NOT NULL,
    water_points INTEGER NOT NULL,
    latrines_girls INTEGER NOT NULL,
    latrines_boys INTEGER NOT NULL,
    has_electricity INTEGER NOT NULL,
    has_fence INTEGER NOT NULL,
    has_canteen INTEGER NOT NULL,
    has_library INTEGER NOT NULL,
    network_observed VARCHAR(16) NULL,
    digital_equipment TEXT NULL,
    correction_reason VARCHAR(500) NULL,
    closed_by VARCHAR(40) NULL,
    closed_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_infra_year ON pub_infrastructure_years (tenant_id, establishment_id, year_label, version);

CREATE TABLE pub_classrooms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    infrastructure_year_id CHAR(26) NOT NULL,
    label VARCHAR(60) NOT NULL,
    material VARCHAR(20) NOT NULL,
    room_state VARCHAR(16) NOT NULL,
    in_use INTEGER NOT NULL
);
CREATE INDEX pub_classrooms_year ON pub_classrooms (tenant_id, infrastructure_year_id);

CREATE TABLE pub_school_photos (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    latitude VARCHAR(20) NULL,
    longitude VARCHAR(20) NULL,
    taken_at VARCHAR(32) NOT NULL,
    visit_id CHAR(26) NULL,
    taken_by VARCHAR(40) NOT NULL
);
