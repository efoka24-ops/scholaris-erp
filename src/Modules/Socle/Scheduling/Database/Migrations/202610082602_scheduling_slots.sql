-- Creneaux hebdomadaires recurrents ; un creneau peut porter plusieurs structures (cours mutualise).

CREATE TABLE timetable_slots (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject_label VARCHAR(120) NOT NULL,
    teacher_person_id VARCHAR(40) NOT NULL,
    room_id VARCHAR(40) NULL,
    course_unit_id VARCHAR(40) NULL,
    weekday SMALLINT NOT NULL,
    start_time VARCHAR(5) NOT NULL,
    end_time VARCHAR(5) NOT NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    period_of_day VARCHAR(10) NOT NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX timetable_slots_day ON timetable_slots (tenant_id, establishment_id, weekday);
CREATE INDEX timetable_slots_teacher ON timetable_slots (tenant_id, teacher_person_id);
CREATE INDEX timetable_slots_room ON timetable_slots (tenant_id, establishment_id, room_id);
CREATE INDEX timetable_slots_unit ON timetable_slots (tenant_id, course_unit_id);

CREATE TABLE timetable_slot_structures (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    slot_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL
);

CREATE INDEX timetable_slot_structures_slot ON timetable_slot_structures (tenant_id, slot_id);
CREATE INDEX timetable_slot_structures_structure ON timetable_slot_structures (tenant_id, establishment_id, structure_id);
