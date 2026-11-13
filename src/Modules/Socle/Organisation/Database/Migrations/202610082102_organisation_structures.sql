-- Organisation (S02) : structures pédagogiques, registre des autorisations, salles.

CREATE TABLE pedagogical_structures (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    org_unit_id CHAR(26) NULL,
    kind VARCHAR(12) NOT NULL,
    name VARCHAR(160) NOT NULL,
    capacity INTEGER NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pedagogical_structures_scope ON pedagogical_structures (tenant_id, establishment_id);

CREATE TABLE authorizations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    reference VARCHAR(80) NOT NULL,
    authority VARCHAR(160) NULL,
    valid_from VARCHAR(32) NOT NULL,
    valid_to VARCHAR(32) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX authorizations_reference ON authorizations (tenant_id, establishment_id, structure_id, reference);

CREATE TABLE rooms (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    capacity INTEGER NULL,
    equipment TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX rooms_name ON rooms (tenant_id, establishment_id, name);
