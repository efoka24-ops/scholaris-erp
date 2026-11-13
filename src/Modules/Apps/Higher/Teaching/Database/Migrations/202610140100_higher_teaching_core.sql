-- Supérieur, enseignement (EDT, ENS hors paiement des vacations) : dossiers enseignants, salles, affectations par le chef de
-- département, emploi du temps (créneaux posés au socle par TimetableWriter), reports et remplacements, cahier de textes,
-- émargement et contre-signature, validation mensuelle des heures, accords de dépassement (RG-ENS-4), planning des examens.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère, aucun mot réservé.

-- Dossier enseignant (EX-ENS-01) : statut, grade, diplômes, établissement d'origine, autorisation de l'employeur (fonction publique).
CREATE TABLE hi_teacher_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    staff_id VARCHAR(40) NULL,
    school_id VARCHAR(40) NULL,
    department_id VARCHAR(40) NULL,
    teacher_status VARCHAR(20) NOT NULL,
    grade VARCHAR(60) NULL,
    diplomas TEXT NULL,
    origin_establishment VARCHAR(160) NULL,
    public_servant INTEGER NOT NULL DEFAULT 0,
    employer_authorization_ref VARCHAR(80) NULL,
    employer_authorization_document VARCHAR(80) NULL,
    employer_authorization_until VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_teacher_profiles_person ON hi_teacher_profiles (tenant_id, establishment_id, person_id);

-- Salles et leur capacité (contrôle de capacité de l'emploi du temps, le socle ne connaît pas les capacités).
CREATE TABLE hi_rooms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    capacity INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_rooms_code ON hi_rooms (tenant_id, establishment_id, code);

-- Affectation (EX-ENS-02) : EC, groupe, nature CM/TD/TP, volume ; par le chef de département de SON département.
CREATE TABLE hi_assignments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    curriculum_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    element_code VARCHAR(30) NOT NULL,
    element_title VARCHAR(160) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    staff_id VARCHAR(40) NOT NULL,
    session_kind VARCHAR(10) NOT NULL,
    planned_minutes INTEGER NOT NULL,
    staff_assignment_id VARCHAR(40) NULL,
    status VARCHAR(12) NOT NULL,
    assigned_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_assignments_element ON hi_assignments (tenant_id, establishment_id, element_id, group_id);

CREATE INDEX hi_assignments_person ON hi_assignments (tenant_id, establishment_id, person_id);

-- Créneau hebdomadaire posé par Higher (copie de lecture du créneau du socle `slot_id`) : vues groupe, enseignant, salle.
-- `group_refs` : groupes concernés (cours mutualisé entre groupes ou filières), JSON.
CREATE TABLE hi_timetable_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    slot_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    group_refs TEXT NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    element_title VARCHAR(160) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    room_id VARCHAR(40) NULL,
    weekday INTEGER NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    day_period VARCHAR(10) NOT NULL,
    session_kind VARCHAR(10) NOT NULL,
    valid_from VARCHAR(32) NOT NULL,
    valid_to VARCHAR(32) NULL,
    headcount INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_timetable_entries_person ON hi_timetable_entries (tenant_id, establishment_id, person_id);

CREATE INDEX hi_timetable_entries_group ON hi_timetable_entries (tenant_id, establishment_id, group_id);

-- Report, annulation, remplacement d'enseignant ou changement de salle d'une séance (EX-EDT-05), notifiés (NOT-05).
CREATE TABLE hi_timetable_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    change_kind VARCHAR(20) NOT NULL,
    occurs_on VARCHAR(32) NOT NULL,
    new_date VARCHAR(32) NULL,
    new_start_time VARCHAR(5) NULL,
    new_end_time VARCHAR(5) NULL,
    replacement_person_id VARCHAR(40) NULL,
    new_room_id VARCHAR(40) NULL,
    reason VARCHAR(500) NOT NULL,
    notified_count INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_timetable_changes_entry ON hi_timetable_changes (tenant_id, establishment_id, entry_id);

-- Délégués de groupe (contre-signature du cahier de textes, EX-ENS-05, Q23).
CREATE TABLE hi_group_delegates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    designated_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_group_delegates_person ON hi_group_delegates (tenant_id, establishment_id, group_id, person_id);

-- Cahier de textes par séance (EX-ENS-04, API-08) : contenu, travaux donnés, signature électronique de l'enseignant, contre-signature
-- du délégué ou du surveillant (EX-ENS-05), validation mensuelle par le chef de département (EX-ENS-06), heure du socle liée.
CREATE TABLE hi_session_logs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    entry_id CHAR(26) NULL,
    slot_id VARCHAR(40) NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    session_kind VARCHAR(10) NOT NULL,
    held_on VARCHAR(32) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    minutes INTEGER NOT NULL,
    content TEXT NULL,
    homework TEXT NULL,
    attendance_session_id VARCHAR(40) NULL,
    vacation_hour_id VARCHAR(40) NULL,
    teacher_signed_at VARCHAR(32) NULL,
    teacher_signature VARCHAR(64) NULL,
    countersigned_by VARCHAR(40) NULL,
    countersigner_role VARCHAR(20) NULL,
    countersigned_at VARCHAR(32) NULL,
    status VARCHAR(20) NOT NULL,
    validation_id CHAR(26) NULL,
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_session_logs_session ON hi_session_logs (tenant_id, establishment_id, assignment_id, held_on, start_time);

CREATE INDEX hi_session_logs_hour ON hi_session_logs (tenant_id, establishment_id, vacation_hour_id);

CREATE INDEX hi_session_logs_person ON hi_session_logs (tenant_id, establishment_id, person_id, held_on);

-- Validation mensuelle des heures d'un enseignant par le chef de département (EX-ENS-06) ; l'état et le paiement sont ailleurs.
CREATE TABLE hi_hours_validations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    period_month VARCHAR(7) NOT NULL,
    log_count INTEGER NOT NULL,
    minutes_total INTEGER NOT NULL,
    cm_minutes INTEGER NOT NULL,
    td_minutes INTEGER NOT NULL,
    tp_minutes INTEGER NOT NULL,
    hetd_milli INTEGER NOT NULL,
    validated_by VARCHAR(40) NOT NULL,
    validated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_hours_validations_month ON hi_hours_validations (tenant_id, establishment_id, department_id, person_id, period_month);

-- Accord écrit du directeur d'école pour des heures au-delà du volume affecté (RG-ENS-4).
CREATE TABLE hi_overrun_approvals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    extra_minutes INTEGER NOT NULL,
    document_ref VARCHAR(80) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    approved_by VARCHAR(40) NOT NULL,
    approved_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_overrun_approvals_assignment ON hi_overrun_approvals (tenant_id, establishment_id, assignment_id);

-- Planning des examens (EX-EDT-06, Should) : épreuve d'un EC pour un groupe, salle, surveillants (JSON), sujet, session.
CREATE TABLE hi_exam_schedules (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    department_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NOT NULL,
    level_id VARCHAR(40) NOT NULL,
    group_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    element_id VARCHAR(40) NOT NULL,
    element_title VARCHAR(160) NOT NULL,
    exam_session VARCHAR(12) NOT NULL,
    exam_date VARCHAR(32) NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    room_id VARCHAR(40) NULL,
    supervisors TEXT NOT NULL,
    paper_ref VARCHAR(80) NULL,
    status VARCHAR(12) NOT NULL,
    convocations_count INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX hi_exam_schedules_date ON hi_exam_schedules (tenant_id, establishment_id, exam_date);
