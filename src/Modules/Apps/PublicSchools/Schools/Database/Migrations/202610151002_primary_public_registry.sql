-- Registre technique PublicPrimary, indépendant de l'application privée Primary (feature 003).
-- Portable MySQL 8 / SQLite ; ULID, tenant/établissement sur chaque ligne, aucun FK vers Organisation.
CREATE TABLE pp_school_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    school_code VARCHAR(80) NOT NULL,
    territory_id CHAR(26) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pp_school_profiles_establishment ON pp_school_profiles (tenant_id, establishment_id);
CREATE UNIQUE INDEX pp_school_profiles_code ON pp_school_profiles (tenant_id, school_code);

CREATE TABLE pp_infrastructure_snapshots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    territory_snapshot TEXT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    version INTEGER NOT NULL,
    classrooms_count INTEGER NOT NULL,
    classroom_condition_fr VARCHAR(500) NOT NULL,
    classroom_condition_en VARCHAR(500) NOT NULL,
    water_access INTEGER NOT NULL,
    latrines_count INTEGER NOT NULL,
    electricity INTEGER NOT NULL,
    connectivity VARCHAR(32) NOT NULL,
    equipment_fr VARCHAR(1000) NOT NULL,
    equipment_en VARCHAR(1000) NOT NULL,
    status VARCHAR(16) NOT NULL,
    correction_reason VARCHAR(500) NULL,
    closed_by VARCHAR(40) NULL,
    closed_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pp_infrastructure_year_version ON pp_infrastructure_snapshots (tenant_id, establishment_id, school_year, version);
CREATE INDEX pp_infrastructure_lookup ON pp_infrastructure_snapshots (tenant_id, establishment_id, school_year, version);