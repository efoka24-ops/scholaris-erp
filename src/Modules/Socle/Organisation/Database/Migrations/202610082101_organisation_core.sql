-- Organisation (S02) : clients, établissements, unités internes, années et périodes.
-- Portable MySQL 8 et SQLite : identifiants ULID, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE tenants (
    id CHAR(26) NOT NULL PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(16) NOT NULL,
    plan VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE establishments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    type VARCHAR(16) NOT NULL,
    branding TEXT NOT NULL,
    modules_enabled TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX establishments_tenant_name ON establishments (tenant_id, name);

CREATE TABLE org_units (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    parent_id CHAR(26) NULL,
    type VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX org_units_tenant_establishment ON org_units (tenant_id, establishment_id);

CREATE TABLE academic_years (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    label VARCHAR(40) NOT NULL,
    starts_on VARCHAR(32) NOT NULL,
    ends_on VARCHAR(32) NOT NULL,
    status VARCHAR(12) NOT NULL,
    closed_at VARCHAR(32) NULL,
    closed_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX academic_years_label ON academic_years (tenant_id, establishment_id, label);

CREATE TABLE periods (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id CHAR(26) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    ordinal INTEGER NOT NULL,
    starts_on VARCHAR(32) NOT NULL,
    ends_on VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX periods_unique ON periods (tenant_id, establishment_id, academic_year_id, kind, ordinal);
