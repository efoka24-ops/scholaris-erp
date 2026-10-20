-- Arbre territorial versionné du socle Organisation. Codes SYN-* réservés aux fixtures de test ; aucune donnée officielle n'est semée.
-- Portable MySQL 8 / SQLite : une ligne est une version ULID d'un territoire logique ULID ; aucun FK inter-module.
CREATE TABLE organisation_territory_versions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    territory_id CHAR(26) NOT NULL,
    version INTEGER NOT NULL,
    territory_type VARCHAR(32) NOT NULL,
    code VARCHAR(80) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    parent_id CHAR(26) NULL,
    valid_from VARCHAR(32) NOT NULL,
    valid_to VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX organisation_territory_version_unique ON organisation_territory_versions (tenant_id, territory_id, version);
CREATE INDEX organisation_territory_lookup ON organisation_territory_versions (tenant_id, territory_id, valid_from, valid_to);
CREATE INDEX organisation_territory_parent ON organisation_territory_versions (tenant_id, parent_id, valid_from, valid_to);