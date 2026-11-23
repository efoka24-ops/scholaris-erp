-- Calendrier academique d'un etablissement : semestres, periodes d'examens, rattrapages, deliberations, jours feries, fermetures, evenements.

CREATE TABLE calendar_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    title VARCHAR(160) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NOT NULL,
    academic_year_id VARCHAR(40) NULL,
    structure_id VARCHAR(40) NULL,
    notes VARCHAR(500) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX calendar_events_period ON calendar_events (tenant_id, establishment_id, starts_on);
CREATE INDEX calendar_events_kind ON calendar_events (tenant_id, establishment_id, kind);
