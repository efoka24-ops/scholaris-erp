-- Personnel de l'école publique (T639, EX-PER-01 et EX-PER-03) : fiche de carrière et formations suivies.
-- Les affectations et les mutations restent dans le socle Staff (contrats AssignmentWriter et StaffMover).
CREATE TABLE pub_staff_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    public_status VARCHAR(20) NOT NULL,
    civil_service_number VARCHAR(40) NULL,
    contract_number VARCHAR(40) NULL,
    professional_diploma VARCHAR(80) NULL,
    service_start_on VARCHAR(10) NULL,
    updated_by VARCHAR(40) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_staff_profiles_staff ON pub_staff_profiles (tenant_id, staff_id);

CREATE TABLE pub_trainings (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    organiser VARCHAR(160) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_trainings_staff ON pub_trainings (tenant_id, staff_id);
