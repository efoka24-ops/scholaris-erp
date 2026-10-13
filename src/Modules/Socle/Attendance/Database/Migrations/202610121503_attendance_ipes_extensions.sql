-- Extensions IPES (D19) : element constitutif de la seance d'appel, herite du creneau a l'ouverture (NULL si le creneau n'en a pas).
-- Une seule colonne par ALTER TABLE.

ALTER TABLE attendance_sessions ADD COLUMN course_unit_id VARCHAR(40) NULL;
CREATE INDEX attendance_sessions_unit ON attendance_sessions (tenant_id, establishment_id, course_unit_id);
