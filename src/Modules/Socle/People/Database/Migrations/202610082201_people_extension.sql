-- Extension de la table people (creee par le module Identity) : etat civil complet. Une seule colonne par ALTER, valeurs NULL autorisees.
ALTER TABLE people ADD COLUMN birth_date VARCHAR(10) NULL;
ALTER TABLE people ADD COLUMN birth_place VARCHAR(120) NULL;
ALTER TABLE people ADD COLUMN gender VARCHAR(10) NULL;
ALTER TABLE people ADD COLUMN nationality VARCHAR(60) NULL;
ALTER TABLE people ADD COLUMN photo_path VARCHAR(255) NULL;
ALTER TABLE people ADD COLUMN national_id VARCHAR(30) NULL;
ALTER TABLE people ADD COLUMN updated_at VARCHAR(32) NULL;

CREATE INDEX people_tenant_birth ON people (tenant_id, birth_date);
CREATE INDEX people_tenant_phone ON people (tenant_id, phone);
CREATE INDEX people_tenant_last_name ON people (tenant_id, last_name);
CREATE UNIQUE INDEX people_tenant_national_id ON people (tenant_id, national_id);
