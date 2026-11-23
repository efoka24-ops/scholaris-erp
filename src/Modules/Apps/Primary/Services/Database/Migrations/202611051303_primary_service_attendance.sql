-- C-104 : présence aux services du primaire privé (cantine, garderie, transport). Un abonné actif est marqué présent ou absent pour un
-- jour donné ; une seule ligne par abonnement et par jour (la saisie du jour se corrige, elle ne se duplique pas). Portable MySQL 8 / SQLite,
-- ULID, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_service_attendance (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    subscription_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    held_on VARCHAR(10) NOT NULL,
    is_present INTEGER NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_service_attendance_day ON pri_service_attendance (tenant_id, establishment_id, subscription_id, held_on);
CREATE INDEX pri_service_attendance_plan ON pri_service_attendance (tenant_id, establishment_id, plan_id, held_on);
