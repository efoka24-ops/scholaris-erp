-- Application secondaire, sous-module Timetable (T860, T861) : plages horaires de l'etablissement (Q30, aucune duree codee),
-- affectations d'enseignement (classe ou demi-groupe, matiere, enseignant permanent ou vacataire, salle), disponibilites,
-- salle de classe, demi-groupes de dedoublement, creneaux poses au socle (TimetableWriter), generations, remplacements et
-- permutations, journal des notifications NOT-14. Volumes horaires lus dans les coefficients de l'etablissement (Setup).
-- Portable MySQL 8 et SQLite : pas de cle etrangere, pas d'AUTO_INCREMENT, aucun mot reserve ; client et etablissement partout.

CREATE TABLE se_timetable_bands (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    weekday INTEGER NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    label VARCHAR(60) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_timetable_bands_slot ON se_timetable_bands (tenant_id, establishment_id, academic_year_id, weekday, start_time);

CREATE TABLE se_teaching_assignments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    group_code VARCHAR(4) NULL,
    se_subject_id CHAR(26) NOT NULL,
    subject_id VARCHAR(40) NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    subject_label VARCHAR(160) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    teacher_kind VARCHAR(12) NOT NULL,
    room_kind VARCHAR(12) NOT NULL,
    room_id VARCHAR(40) NULL,
    status VARCHAR(10) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_teaching_assignments_class ON se_teaching_assignments (tenant_id, establishment_id, academic_year_id, class_id);
CREATE INDEX se_teaching_assignments_teacher ON se_teaching_assignments (tenant_id, establishment_id, teacher_person_id);

CREATE TABLE se_teacher_availabilities (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    weekday INTEGER NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    availability_kind VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_teacher_availabilities_teacher ON se_teacher_availabilities (tenant_id, establishment_id, academic_year_id, teacher_person_id);

CREATE TABLE se_timetable_class_rooms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    room_id VARCHAR(40) NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_timetable_class_rooms_class ON se_timetable_class_rooms (tenant_id, establishment_id, academic_year_id, class_id);

CREATE TABLE se_timetable_groups (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    group_code VARCHAR(4) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_timetable_groups_code ON se_timetable_groups (tenant_id, establishment_id, class_id, group_code);

CREATE TABLE se_timetable_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    slot_id VARCHAR(40) NOT NULL,
    assignment_id CHAR(26) NOT NULL,
    class_id VARCHAR(40) NOT NULL,
    group_code VARCHAR(4) NULL,
    structure_id VARCHAR(40) NOT NULL,
    se_subject_id CHAR(26) NOT NULL,
    subject_label VARCHAR(160) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    room_id VARCHAR(40) NULL,
    weekday INTEGER NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    origin VARCHAR(12) NOT NULL,
    run_id CHAR(26) NULL,
    parent_entry_id CHAR(26) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_timetable_entries_class ON se_timetable_entries (tenant_id, establishment_id, academic_year_id, class_id);
CREATE INDEX se_timetable_entries_teacher ON se_timetable_entries (tenant_id, establishment_id, teacher_person_id);
CREATE INDEX se_timetable_entries_slot ON se_timetable_entries (tenant_id, slot_id);

CREATE TABLE se_timetable_runs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    class_refs TEXT NOT NULL,
    applied INTEGER NOT NULL,
    placed_count INTEGER NOT NULL,
    unplaced_count INTEGER NOT NULL,
    unplaced_json TEXT NOT NULL,
    reason VARCHAR(255) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_timetable_runs_year ON se_timetable_runs (tenant_id, establishment_id, academic_year_id);

CREATE TABLE se_timetable_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    change_kind VARCHAR(16) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    other_entry_id CHAR(26) NULL,
    class_id VARCHAR(40) NOT NULL,
    from_date VARCHAR(10) NOT NULL,
    to_date VARCHAR(10) NULL,
    replacement_person_id VARCHAR(40) NULL,
    previous_person_id VARCHAR(40) NULL,
    created_entry_refs TEXT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    notified_count INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_timetable_changes_class ON se_timetable_changes (tenant_id, establishment_id, class_id, from_date);

CREATE TABLE se_timetable_notices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    change_id CHAR(26) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    template_key VARCHAR(60) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_timetable_notices_once ON se_timetable_notices (tenant_id, establishment_id, change_id, recipient_person_id);
