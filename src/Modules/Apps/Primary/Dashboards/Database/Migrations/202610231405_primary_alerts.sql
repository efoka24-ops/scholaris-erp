-- Primaire privé, alertes du directeur (spec §8, T551) : absences répétées (au moins `primary.alert.absences` demi-journées
-- consécutives, valeur paramétrée), détectées par la commande quotidienne `primary:alerts`. Une série d'absences ne donne qu'une
-- alerte (unicité élève, nature, début de série). Portable MySQL 8 / SQLite, aucun mot réservé.

CREATE TABLE pri_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_structure_id VARCHAR(40) NOT NULL,
    alert_kind VARCHAR(20) NOT NULL,
    streak_start VARCHAR(20) NOT NULL,
    half_days INTEGER NOT NULL,
    detected_on VARCHAR(10) NOT NULL,
    status VARCHAR(12) NOT NULL,
    resolved_by VARCHAR(40) NULL,
    resolved_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_alerts_streak ON pri_alerts (tenant_id, establishment_id, person_id, alert_kind, streak_start);
CREATE INDEX pri_alerts_status ON pri_alerts (tenant_id, establishment_id, status);
