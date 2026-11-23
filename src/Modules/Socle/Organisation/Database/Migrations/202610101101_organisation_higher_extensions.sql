-- Organisation : extensions demandées par l'application Supérieur (D1, D2, D6). Sans effet sur l'existant :
-- colonnes facultatives (NULL par défaut) et table nouvelle.
-- D1 : hiérarchie des structures (filière > niveau > groupe) par parent_id.
ALTER TABLE pedagogical_structures ADD COLUMN parent_id CHAR(26) NULL;

CREATE INDEX pedagogical_structures_parent ON pedagogical_structures (tenant_id, establishment_id, parent_id);

-- D2 : niveaux couverts par l'autorisation (liste JSON d'identifiants de structures de niveau ; NULL = tous) et pièce numérisée.
ALTER TABLE authorizations ADD COLUMN covered_levels TEXT NULL;

ALTER TABLE authorizations ADD COLUMN document_id CHAR(26) NULL;

-- D6 : paramètres d'une unité interne (école, département), portée « org_unit ». Héritage :
-- plateforme > client > établissement > unité parente > unité. Table distincte de `settings` : son index unique
-- (scope_type, tenant_id, establishment_id, setting_key) ne peut pas être modifié de façon portable MySQL 8 / SQLite.
CREATE TABLE org_unit_settings (
    id CHAR(26) NOT NULL PRIMARY KEY,
    scope_type VARCHAR(16) NOT NULL DEFAULT 'org_unit',
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    org_unit_id CHAR(26) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    value_json TEXT NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX org_unit_settings_key ON org_unit_settings (tenant_id, establishment_id, org_unit_id, setting_key);
