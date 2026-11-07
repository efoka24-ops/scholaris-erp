-- Dossier du personnel : fiche (reference a une personne), qualifications, affectations d'enseignement.

CREATE TABLE staff_members (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    home_establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    status VARCHAR(12) NOT NULL,
    grade VARCHAR(80) NULL,
    category VARCHAR(40) NULL,
    employee_number VARCHAR(40) NULL,
    department_id VARCHAR(40) NULL,
    hired_on VARCHAR(10) NULL,
    left_on VARCHAR(10) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX staff_members_person ON staff_members (tenant_id, person_id);
CREATE INDEX staff_members_home ON staff_members (tenant_id, home_establishment_id, status);

CREATE TABLE staff_qualifications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    institution VARCHAR(160) NULL,
    obtained_on VARCHAR(10) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX staff_qualifications_staff ON staff_qualifications (tenant_id, staff_id);

CREATE TABLE staff_assignments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    staff_id CHAR(26) NOT NULL,
    academic_year_id VARCHAR(40) NULL,
    structure_id VARCHAR(40) NULL,
    subject_id VARCHAR(40) NULL,
    subject_label VARCHAR(160) NULL,
    session_kind VARCHAR(8) NOT NULL,
    planned_minutes INTEGER NOT NULL DEFAULT 0,
    starts_on VARCHAR(10) NULL,
    ends_on VARCHAR(10) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX staff_assignments_staff ON staff_assignments (tenant_id, staff_id, status);
CREATE INDEX staff_assignments_establishment ON staff_assignments (tenant_id, establishment_id, status);
