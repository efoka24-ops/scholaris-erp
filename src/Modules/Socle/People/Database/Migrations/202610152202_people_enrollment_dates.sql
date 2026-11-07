-- PP9 : dates d'entrée et de clôture d'une inscription (LearnerWriter : inscription à une date, transfert ou retrait à la date d'accueil).
-- Portable MySQL 8 / SQLite : une seule colonne par ALTER, valeurs NULL autorisées, format AAAA-MM-JJ.
ALTER TABLE enrollments ADD COLUMN enrolled_on VARCHAR(10) NULL;
ALTER TABLE enrollments ADD COLUMN closed_on VARCHAR(10) NULL;
