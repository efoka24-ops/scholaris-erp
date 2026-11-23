-- PP1 : rattachement daté d'un établissement à un territoire (RG-TER-1, RG-TER-4). Une ligne est un lien ; un changement
-- d'arrondissement clôt l'ancien lien (valid_to) et en ouvre un nouveau, sans jamais réécrire le passé.
-- Portable MySQL 8 / SQLite : dates ISO 8601 normalisées UTC en texte, aucun FK inter-module.
CREATE TABLE organisation_establishment_territories (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    territory_id CHAR(26) NOT NULL,
    valid_from VARCHAR(32) NOT NULL,
    valid_to VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX organisation_estab_territory_unique ON organisation_establishment_territories (tenant_id, establishment_id, valid_from);
CREATE INDEX organisation_estab_territory_lookup ON organisation_establishment_territories (tenant_id, establishment_id, valid_from, valid_to);
CREATE INDEX organisation_estab_territory_by_territory ON organisation_establishment_territories (tenant_id, territory_id, valid_from, valid_to);
