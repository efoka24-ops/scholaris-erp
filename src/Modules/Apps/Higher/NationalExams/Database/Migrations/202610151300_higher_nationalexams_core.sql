-- Supérieur, examens nationaux BTS et HND (EXN, EX-EXN-01 à 07, RG-EXN-1 à 3, API-17, T304-T309, T332).
-- Session nationale par filière, année et type ; candidats tirés des seules inscriptions validées du niveau de sortie
-- (cycle : eligible -> complete -> exported -> convened -> resulted, ou withdrawn) ; journal des modifications de la liste
-- après export (RG-EXN-2, alerte au directeur général) et des exports (filigrane nominatif, empreinte).

CREATE TABLE hi_national_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    exam_type VARCHAR(8) NOT NULL,
    specialty_label VARCHAR(160) NOT NULL,
    calendar_ref VARCHAR(120) NULL,
    registration_deadline VARCHAR(32) NULL,
    exam_starts_on VARCHAR(32) NULL,
    attendance_from VARCHAR(32) NOT NULL,
    attendance_to VARCHAR(32) NOT NULL,
    fee_xaf INTEGER NOT NULL DEFAULT 0,
    required_pieces TEXT NULL,
    status VARCHAR(12) NOT NULL,
    export_count INTEGER NOT NULL DEFAULT 0,
    last_exported_at VARCHAR(32) NULL,
    last_exported_by VARCHAR(40) NULL,
    export_fingerprint CHAR(64) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_national_sessions_unique ON hi_national_sessions (tenant_id, establishment_id, program_id, academic_year_id, exam_type);

CREATE TABLE hi_national_candidates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    enrolment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    matricule VARCHAR(40) NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    presence_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    attendance_ok INTEGER NOT NULL DEFAULT 0,
    fee_receipt VARCHAR(80) NULL,
    pieces_received TEXT NULL,
    candidate_number VARCHAR(40) NULL,
    exam_centre VARCHAR(120) NULL,
    convened_at VARCHAR(32) NULL,
    result_code VARCHAR(12) NULL,
    result_mark DECIMAL(5,2) NULL,
    result_imported_at VARCHAR(32) NULL,
    withdrawn_reason VARCHAR(300) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_national_candidates_unique ON hi_national_candidates (tenant_id, establishment_id, session_id, person_id);
CREATE INDEX hi_national_candidates_session ON hi_national_candidates (tenant_id, establishment_id, session_id, status);

CREATE TABLE hi_national_list_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    candidate_id CHAR(26) NULL,
    event_kind VARCHAR(20) NOT NULL,
    after_export INTEGER NOT NULL DEFAULT 0,
    detail TEXT NULL,
    actor_id VARCHAR(40) NOT NULL,
    alerted_count INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX hi_national_list_events_session ON hi_national_list_events (tenant_id, establishment_id, session_id, created_at);
