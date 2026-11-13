-- Extensions IPES (D12) : nature de la seance d'un creneau (cm, td, tp, other ; NULL = non precisee, comportement historique).
-- Une seule colonne par ALTER TABLE.

ALTER TABLE timetable_slots ADD COLUMN session_kind VARCHAR(8) NULL;
