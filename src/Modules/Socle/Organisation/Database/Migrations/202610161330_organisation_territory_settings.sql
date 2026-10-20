-- Organisation (PP16) : paramètres territoriaux hérités. territory_id vide = niveau national (le client). Un paramètre porte un
-- niveau de verrouillage : le niveau le plus profond qui peut encore le modifier (national, region, departement, arrondissement).

CREATE TABLE territory_settings (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    territory_id VARCHAR(40) NOT NULL,
    level_code VARCHAR(14) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    value_json TEXT NOT NULL,
    lock_level VARCHAR(14) NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX territory_settings_cell ON territory_settings (tenant_id, territory_id, setting_key);
