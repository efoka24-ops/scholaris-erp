-- Cloture d'annee (T137) : demandes de cloture (validation a deux personnes) et archive de l'etat de l'annee close.

CREATE TABLE year_closures (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id CHAR(26) NOT NULL,
    next_year_id CHAR(26) NULL,
    status VARCHAR(24) NOT NULL,
    approval_id CHAR(26) NULL,
    requested_by VARCHAR(40) NOT NULL,
    requested_at VARCHAR(32) NOT NULL,
    decided_by VARCHAR(40) NULL,
    closed_at VARCHAR(32) NULL,
    check_report TEXT NOT NULL,
    steps TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX year_closures_year ON year_closures (tenant_id, establishment_id, academic_year_id, status);

CREATE TABLE year_archives (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    academic_year_id CHAR(26) NOT NULL,
    closure_id CHAR(26) NOT NULL,
    snapshot TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX year_archives_year ON year_archives (tenant_id, establishment_id, academic_year_id);
