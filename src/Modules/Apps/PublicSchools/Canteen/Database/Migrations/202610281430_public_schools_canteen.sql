-- Cantine et santé scolaire (CAN, T687, Could/Should) : repas servis par jour et par classe, stocks reçus et consommés (EX-CAN-01),
-- campagnes de santé saisies en effectifs avec le nombre de consentements des parents (EX-CAN-02). Aucun dossier médical, aucun nom.
CREATE TABLE pub_meals (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    day_on VARCHAR(10) NOT NULL,
    served_count INTEGER NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_meals_day ON pub_meals (tenant_id, establishment_id, structure_id, day_on);

CREATE TABLE pub_canteen_stock_moves (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    item_label VARCHAR(120) NOT NULL,
    unit_label VARCHAR(30) NOT NULL,
    direction VARCHAR(4) NOT NULL,
    quantity INTEGER NOT NULL,
    moved_on VARCHAR(10) NOT NULL,
    source_label VARCHAR(160) NULL,
    note VARCHAR(500) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_canteen_stock_item ON pub_canteen_stock_moves (tenant_id, establishment_id, item_label);

CREATE TABLE pub_health_campaigns (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    campaign_kind VARCHAR(20) NOT NULL,
    held_on VARCHAR(10) NOT NULL,
    label VARCHAR(160) NULL,
    girls_count INTEGER NOT NULL,
    boys_count INTEGER NOT NULL,
    consents_count INTEGER NOT NULL,
    refusals_count INTEGER NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_health_campaigns_school ON pub_health_campaigns (tenant_id, establishment_id, held_on);
