-- Reporting (PP4, T613) : agrégats par territoire calculés par `reporting:aggregate-territories` à partir des fournisseurs
-- `reporting.territory_aggregates.<application>`. Valeurs brutes : le masquage des petits effectifs (ThresholdDisclosurePolicy)
-- s'applique à la lecture. Un calcul remplace toutes les lignes du client pour la date de référence (idempotent).

CREATE TABLE reporting_territory_aggregates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    territory_id VARCHAR(40) NOT NULL,
    indicator_key VARCHAR(80) NOT NULL,
    reference_date VARCHAR(32) NOT NULL,
    cell_key VARCHAR(80) NOT NULL,
    cell_count INTEGER NOT NULL,
    establishment_count INTEGER NOT NULL,
    computed_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX reporting_territory_aggregates_cell ON reporting_territory_aggregates (tenant_id, territory_id, indicator_key, reference_date, cell_key);
CREATE INDEX reporting_territory_aggregates_date ON reporting_territory_aggregates (tenant_id, reference_date);
