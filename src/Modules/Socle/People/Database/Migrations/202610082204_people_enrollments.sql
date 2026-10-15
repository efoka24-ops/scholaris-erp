-- Inscriptions (avec brouillon) et historique de scolarite.

CREATE TABLE enrollments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NULL,
    academic_year_id VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL,
    internal_number VARCHAR(40) NULL,
    completeness SMALLINT NOT NULL DEFAULT 0,
    documents_due_on VARCHAR(10) NULL,
    created_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX enrollments_person ON enrollments (tenant_id, person_id);
CREATE INDEX enrollments_structure ON enrollments (tenant_id, structure_id, status);
CREATE INDEX enrollments_establishment ON enrollments (tenant_id, establishment_id, status);

CREATE TABLE schooling_history (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    enrollment_id CHAR(26) NULL,
    school_name VARCHAR(160) NOT NULL,
    level_label VARCHAR(80) NULL,
    year_label VARCHAR(40) NULL,
    outcome VARCHAR(60) NULL,
    source VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX schooling_history_person ON schooling_history (tenant_id, person_id);
