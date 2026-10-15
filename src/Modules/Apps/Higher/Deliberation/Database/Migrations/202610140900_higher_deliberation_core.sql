-- Délibération IPES (DEL, T281) : seulement le spécifique. Les délibérations, lignes, décisions et résultats sont ceux du socle
-- (deliberations, deliberation_lines, results) ; ces tables portent le jury, ses membres, les points de jury, les signatures du PV
-- avec empreinte, l'historique (réouvertures), les UE capitalisées, les décisions de fin d'année et les convocations au rattrapage.

CREATE TABLE hi_juries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    period_label VARCHAR(80) NOT NULL,
    exam_session VARCHAR(10) NOT NULL,
    regime VARCHAR(12) NOT NULL,
    president_person_id VARCHAR(40) NOT NULL,
    quorum_min INTEGER NOT NULL,
    agenda TEXT NULL,
    status VARCHAR(16) NOT NULL,
    deliberation_ref VARCHAR(40) NULL,
    minutes_fingerprint CHAR(64) NULL,
    minutes_version INTEGER NOT NULL,
    signed_at VARCHAR(32) NULL,
    published_at VARCHAR(32) NULL,
    reopen_count INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_juries_cell ON hi_juries (tenant_id, establishment_id, structure_id, period_id, exam_session);
CREATE INDEX hi_juries_school ON hi_juries (tenant_id, establishment_id, school_id, academic_year_id);

CREATE TABLE hi_jury_members (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    member_role VARCHAR(12) NOT NULL,
    present INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_jury_members_person ON hi_jury_members (tenant_id, jury_id, person_id);
CREATE INDEX hi_jury_members_of ON hi_jury_members (tenant_id, establishment_id, person_id);

CREATE TABLE hi_jury_points (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    points_requested DECIMAL(4,2) NOT NULL,
    points_granted DECIMAL(4,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    granted_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_jury_points_person ON hi_jury_points (tenant_id, jury_id, person_id);

CREATE TABLE hi_minutes_signatures (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    minutes_version INTEGER NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    member_role VARCHAR(12) NOT NULL,
    fingerprint CHAR(64) NULL,
    signed_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_minutes_signatures_one ON hi_minutes_signatures (tenant_id, jury_id, minutes_version, person_id);

CREATE TABLE hi_jury_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    reason VARCHAR(500) NULL,
    actor_id VARCHAR(40) NOT NULL,
    payload TEXT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX hi_jury_events_of ON hi_jury_events (tenant_id, jury_id, created_at);

CREATE TABLE hi_unit_credits (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    unit_code VARCHAR(40) NOT NULL,
    unit_title VARCHAR(160) NULL,
    credits INTEGER NOT NULL,
    average DECIMAL(5,2) NULL,
    letter VARCHAR(4) NULL,
    unit_status VARCHAR(16) NOT NULL,
    exam_session VARCHAR(10) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    recorded_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_unit_credits_unit ON hi_unit_credits (tenant_id, establishment_id, person_id, program_id, academic_year_id, unit_code);

CREATE TABLE hi_year_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    average DECIMAL(5,2) NULL,
    credits_earned INTEGER NOT NULL,
    credits_total INTEGER NOT NULL,
    cumulative_credits INTEGER NOT NULL,
    decision_code VARCHAR(24) NOT NULL,
    decision_label VARCHAR(80) NOT NULL,
    mention VARCHAR(40) NULL,
    diploma_file INTEGER NOT NULL,
    derogation INTEGER NOT NULL,
    acquired_units TEXT NOT NULL,
    missing_units TEXT NOT NULL,
    decided_by VARCHAR(40) NOT NULL,
    decided_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_year_decisions_one ON hi_year_decisions (tenant_id, establishment_id, person_id, program_id, academic_year_id);
CREATE INDEX hi_year_decisions_group ON hi_year_decisions (tenant_id, establishment_id, structure_id, academic_year_id);

CREATE TABLE hi_resit_convocations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    jury_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    unit_code VARCHAR(40) NOT NULL,
    element_code VARCHAR(40) NOT NULL,
    note DECIMAL(5,2) NULL,
    notified_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX hi_resit_convocations_one ON hi_resit_convocations (tenant_id, jury_id, person_id, element_code);
