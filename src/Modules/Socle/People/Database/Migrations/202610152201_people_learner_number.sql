-- PP8 : identifiant d'élève (learner_number) attribué une seule fois par un fournisseur d'application ; unique par client.
-- Portable MySQL 8 / SQLite : colonne nullable, les valeurs NULL multiples restent permises par l'index unique.
ALTER TABLE people ADD COLUMN learner_number VARCHAR(40) NULL;

CREATE UNIQUE INDEX people_tenant_learner_number ON people (tenant_id, learner_number);
