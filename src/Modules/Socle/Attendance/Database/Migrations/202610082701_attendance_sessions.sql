-- S08 Appel : séances d'appel rattachées à un créneau, lignes par élève, notifications aux parents.

CREATE TABLE attendance_sessions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    slot_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    subject_label VARCHAR(160) NOT NULL,
    session_date VARCHAR(10) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    status VARCHAR(12) NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    expected_count INTEGER NOT NULL,
    taken_by VARCHAR(40) NOT NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    synced_at VARCHAR(32) NULL,
    qr_token_hash CHAR(64) NULL,
    qr_expires_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX attendance_sessions_client ON attendance_sessions (tenant_id, establishment_id, client_uuid);
CREATE UNIQUE INDEX attendance_sessions_slot_day ON attendance_sessions (tenant_id, slot_id, session_date);
CREATE INDEX attendance_sessions_day ON attendance_sessions (tenant_id, establishment_id, session_date);

CREATE TABLE attendance_records (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    session_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    session_date VARCHAR(10) NOT NULL,
    status CHAR(1) NOT NULL,
    arrived_at VARCHAR(5) NULL,
    client_uuid VARCHAR(40) NULL,
    justified SMALLINT NOT NULL,
    justification_id CHAR(26) NULL,
    note VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX attendance_records_person ON attendance_records (tenant_id, session_id, person_id);
CREATE UNIQUE INDEX attendance_records_client ON attendance_records (tenant_id, session_id, client_uuid);
CREATE INDEX attendance_records_learner ON attendance_records (tenant_id, establishment_id, person_id, session_date);
CREATE INDEX attendance_records_day ON attendance_records (tenant_id, establishment_id, session_date, status);

CREATE TABLE absence_notifications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    absence_date VARCHAR(10) NOT NULL,
    session_id CHAR(26) NOT NULL,
    status VARCHAR(10) NOT NULL,
    recipients INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX absence_notifications_once ON absence_notifications (tenant_id, establishment_id, person_id, absence_date);
