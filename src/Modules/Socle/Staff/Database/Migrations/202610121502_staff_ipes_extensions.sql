-- Extensions IPES du module Staff. Une seule colonne par ALTER TABLE.
-- D18 : affectation rattachee a un element constitutif et a un groupe (references externes de l'application) ;
-- D15 : niveau d'enseignement (L1, M1, BTS...) porte par l'affectation, l'heure et le taux.

ALTER TABLE staff_assignments ADD COLUMN element_ref VARCHAR(80) NULL;
ALTER TABLE staff_assignments ADD COLUMN group_ref VARCHAR(80) NULL;
ALTER TABLE staff_assignments ADD COLUMN level VARCHAR(40) NULL;
CREATE INDEX staff_assignments_element ON staff_assignments (tenant_id, establishment_id, element_ref);

ALTER TABLE vacation_hours ADD COLUMN level VARCHAR(40) NULL;

-- Taux par categorie (grade) ET niveau : la cle d'unicite s'elargit au niveau ('' = tous niveaux, comportement historique).
-- Reconstruction portable (MySQL 8 et SQLite) : nouvelle table, copie, suppression, renommage, puis index.
CREATE TABLE vacation_rates_rebuilt (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    category VARCHAR(40) NOT NULL,
    level VARCHAR(40) NOT NULL DEFAULT '',
    hourly_rate INTEGER NOT NULL,
    monthly_cap_minutes INTEGER NULL,
    updated_at VARCHAR(32) NOT NULL
);

INSERT INTO vacation_rates_rebuilt (id, tenant_id, establishment_id, category, level, hourly_rate, monthly_cap_minutes, updated_at)
SELECT id, tenant_id, establishment_id, category, '', hourly_rate, monthly_cap_minutes, updated_at FROM vacation_rates;

DROP TABLE vacation_rates;
ALTER TABLE vacation_rates_rebuilt RENAME TO vacation_rates;
CREATE UNIQUE INDEX vacation_rates_category ON vacation_rates (tenant_id, establishment_id, category, level);
