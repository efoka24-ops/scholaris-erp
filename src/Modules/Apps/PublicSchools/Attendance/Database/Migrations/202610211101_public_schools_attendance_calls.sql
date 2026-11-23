-- Suivi des appels de classe par demi-journée (T634, T638) : projection de l'application sur les séances du socle, pour savoir
-- quels jours une classe n'a pas fait l'appel (NOT-12) et qui a corrigé quoi. Les statuts des élèves restent dans le socle.
CREATE TABLE pub_attendance_calls (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    day_on VARCHAR(10) NOT NULL,
    day_part VARCHAR(10) NOT NULL,
    session_id CHAR(26) NOT NULL,
    headcount INTEGER NOT NULL,
    absent_count INTEGER NOT NULL,
    late_count INTEGER NOT NULL,
    taken_by VARCHAR(40) NOT NULL,
    recorded_at VARCHAR(32) NOT NULL,
    corrected_by VARCHAR(40) NULL,
    corrected_at VARCHAR(32) NULL,
    correction_reason VARCHAR(500) NULL
);
CREATE UNIQUE INDEX pub_attendance_calls_slot ON pub_attendance_calls (tenant_id, establishment_id, structure_id, day_on, day_part);
CREATE INDEX pub_attendance_calls_day ON pub_attendance_calls (tenant_id, establishment_id, day_on);

-- Alertes déjà levées (NOT-03, NOT-12, NOT-13) : une seule fois par sujet, période et niveau (directeur, puis inspection).
CREATE TABLE pub_attendance_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    period_key VARCHAR(16) NOT NULL,
    level VARCHAR(12) NOT NULL,
    recipients INTEGER NOT NULL,
    raised_on VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_attendance_alerts_once ON pub_attendance_alerts (tenant_id, establishment_id, kind, subject_id, period_key, level);

-- Remplaçant déclaré d'un enseignant absent (RG-PRE-2) : sans remplaçant, la classe est « sans enseignant ».
ALTER TABLE pub_teacher_presence ADD COLUMN replacement_staff_id CHAR(26) NULL;
