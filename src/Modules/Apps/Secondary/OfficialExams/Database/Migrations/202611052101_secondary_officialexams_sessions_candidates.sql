-- Examens officiels du secondaire (T906, T907, T949). Codes, formats, pièces et résultats sont configurés par établissement, aucune règle officielle prédéfinie.
CREATE TABLE se_official_exam_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    exam_code VARCHAR(40) NOT NULL,
    exam_label VARCHAR(160) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    comparison_period_id VARCHAR(40) NULL,
    registration_deadline VARCHAR(10) NULL,
    exam_on VARCHAR(10) NULL,
    required_pieces TEXT NOT NULL,
    optional_subjects TEXT NOT NULL,
    export_columns TEXT NOT NULL,
    passing_codes TEXT NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_official_exam_sessions_year ON se_official_exam_sessions (tenant_id, establishment_id, academic_year_id, status);
CREATE INDEX se_official_exam_sessions_class ON se_official_exam_sessions (tenant_id, establishment_id, class_structure_id, academic_year_id);

CREATE TABLE se_official_exam_candidates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    enrollment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    full_name VARCHAR(241) NOT NULL,
    photo_ref VARCHAR(80) NULL,
    pieces_received TEXT NOT NULL,
    selected_subjects TEXT NOT NULL,
    result_code VARCHAR(40) NULL,
    result_mark DECIMAL(10,3) NULL,
    result_imported_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_official_exam_candidates_person ON se_official_exam_candidates (tenant_id, establishment_id, session_id, person_id);
CREATE INDEX se_official_exam_candidates_session ON se_official_exam_candidates (tenant_id, establishment_id, session_id, full_name);
CREATE INDEX se_official_exam_candidates_class ON se_official_exam_candidates (tenant_id, establishment_id, class_structure_id, session_id);
