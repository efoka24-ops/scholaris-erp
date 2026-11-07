-- Modifications ponctuelles d'une occurrence : cours annule, deplace ou remplace par un autre enseignant.

CREATE TABLE timetable_changes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    slot_id CHAR(26) NOT NULL,
    on_date VARCHAR(10) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    new_date VARCHAR(10) NULL,
    new_start_time VARCHAR(5) NULL,
    new_end_time VARCHAR(5) NULL,
    new_room_id VARCHAR(40) NULL,
    new_teacher_person_id VARCHAR(40) NULL,
    reason VARCHAR(300) NULL,
    notified_count SMALLINT NOT NULL DEFAULT 0,
    notified_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX timetable_changes_occurrence ON timetable_changes (tenant_id, slot_id, on_date);
CREATE INDEX timetable_changes_date ON timetable_changes (tenant_id, establishment_id, on_date);
CREATE INDEX timetable_changes_moved ON timetable_changes (tenant_id, establishment_id, new_date);
