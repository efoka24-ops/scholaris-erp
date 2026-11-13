-- Application secondaire, sous-module Syllabus (T862, T863) : progressions annuelles par matiere et niveau (lecons, heures
-- prevues, semaine prevue), cahier de textes signe par seance (API-06, hors ligne par client_uuid), visas du censeur et de
-- l'animateur pedagogique, journal des rappels NOT-12. Heures en DECIMAL, dates AAAA-MM-JJ, aucune valeur officielle codee.
-- Portable MySQL 8 et SQLite : pas de cle etrangere, pas d'AUTO_INCREMENT, aucun mot reserve ; client et etablissement partout.

CREATE TABLE se_progressions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    se_subject_id CHAR(26) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    subject_label VARCHAR(160) NOT NULL,
    level_code VARCHAR(20) NOT NULL,
    stream_id CHAR(26) NULL,
    total_hours DECIMAL(6,2) NOT NULL,
    status VARCHAR(10) NOT NULL,
    approved_by VARCHAR(40) NULL,
    approved_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_progressions_lookup ON se_progressions (tenant_id, establishment_id, academic_year_id, se_subject_id, level_code);

CREATE TABLE se_progression_lessons (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    progression_id CHAR(26) NOT NULL,
    sort_order INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    planned_hours DECIMAL(5,2) NOT NULL,
    planned_week INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_progression_lessons_progression ON se_progression_lessons (tenant_id, establishment_id, progression_id, sort_order);

CREATE TABLE se_lesson_logs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    se_subject_id CHAR(26) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    subject_label VARCHAR(160) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NULL,
    slot_id VARCHAR(40) NULL,
    session_date VARCHAR(10) NOT NULL,
    lesson_id CHAR(26) NULL,
    content TEXT NOT NULL,
    homework TEXT NULL,
    hours DECIMAL(4,2) NOT NULL,
    signed_by VARCHAR(40) NOT NULL,
    signed_at VARCHAR(32) NOT NULL,
    signature_hash CHAR(64) NOT NULL,
    client_uuid VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_lesson_logs_client ON se_lesson_logs (tenant_id, client_uuid);
CREATE INDEX se_lesson_logs_class ON se_lesson_logs (tenant_id, establishment_id, academic_year_id, structure_id, se_subject_id);
CREATE INDEX se_lesson_logs_assignment ON se_lesson_logs (tenant_id, establishment_id, assignment_id, session_date);

CREATE TABLE se_lesson_visas (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    se_subject_id CHAR(26) NOT NULL,
    visa_role VARCHAR(16) NOT NULL,
    visa_on VARCHAR(10) NOT NULL,
    remark VARCHAR(500) NULL,
    visa_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_lesson_visas_class ON se_lesson_visas (tenant_id, establishment_id, academic_year_id, structure_id, se_subject_id);

CREATE TABLE se_syllabus_notices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    notice_on VARCHAR(10) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_syllabus_notices_once ON se_syllabus_notices (tenant_id, establishment_id, assignment_id, notice_on, recipient_person_id);
