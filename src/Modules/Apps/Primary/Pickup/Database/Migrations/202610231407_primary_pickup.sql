-- Primaire privé, sortie sécurisée (EX-PRI-33, Could ; Q13 : désactivée par défaut, activable par niveau avec `primary.pickup.levels`).
-- Journal des remises (personne autorisée désignée par son identifiant, RG-SOR-1) et des refus (personne non autorisée), alerte au
-- parent et à la direction (NOT-PRI-07). Les autorisations restent celles du socle (`authorized_pickups`, AuthorizedPickupRegistry).
-- Portable MySQL 8 / SQLite, aucun mot réservé.

CREATE TABLE pri_pickup_logs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    pickup_id VARCHAR(40) NULL,
    outcome VARCHAR(12) NOT NULL,
    presented_name VARCHAR(160) NULL,
    note VARCHAR(255) NULL,
    handled_by VARCHAR(40) NOT NULL,
    handled_on VARCHAR(10) NOT NULL,
    handled_at VARCHAR(32) NOT NULL,
    notified_count INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_pickup_logs_day ON pri_pickup_logs (tenant_id, establishment_id, class_structure_id, handled_on);
