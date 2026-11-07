-- Platform : délais par client et par application, options de service, mode régularisation de paiement.
-- Portable MySQL 8 et SQLite : une colonne par ALTER TABLE, dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.
-- Toutes les colonnes ajoutées sont facultatives : sans valeur, le comportement est celui du catalogue (inchangé).

-- Surcharges par client et par application (null : valeur du catalogue).
ALTER TABLE tenant_applications ADD COLUMN trial_days_override INTEGER NULL;
ALTER TABLE tenant_applications ADD COLUMN grace_days_override INTEGER NULL;
-- Périodicité de facturation en mois (1 mensuelle, 3 trimestrielle, 12 annuelle) ; null : au choix du payeur (1 à 12 mois).
ALTER TABLE tenant_applications ADD COLUMN billing_period_months INTEGER NULL;
-- Niveau de blocage choisi pour ce client (read_only ou locked) ; null : réglage de plateforme.
ALTER TABLE tenant_applications ADD COLUMN lock_level VARCHAR(12) NULL;

-- Mode régularisation de paiement : niveau appliqué (null : pas en régularisation), origine (auto ou manual), date de début.
ALTER TABLE tenant_applications ADD COLUMN regularisation VARCHAR(12) NULL;
ALTER TABLE tenant_applications ADD COLUMN regularisation_origin VARCHAR(10) NULL;
ALTER TABLE tenant_applications ADD COLUMN regularisation_since VARCHAR(10) NULL;

-- Promesse de paiement : le blocage automatique est reporté jusqu'à cette date (incluse).
ALTER TABLE tenant_applications ADD COLUMN promise_until VARCHAR(10) NULL;
ALTER TABLE tenant_applications ADD COLUMN promise_reason VARCHAR(500) NULL;

-- Options de service (modules activables : SMS, portail parents, import…), gérées par la plateforme.
-- app_keys : liste JSON des applications concernées ([] : toutes).
CREATE TABLE platform_service_options (
    option_key VARCHAR(30) NOT NULL PRIMARY KEY,
    label_fr VARCHAR(120) NOT NULL,
    label_en VARCHAR(120) NOT NULL,
    monthly_price_xaf INTEGER NOT NULL,
    app_keys TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

-- Souscription d'un client à une option pour une application (price_xaf : prix propre au client, null : prix de l'option).
CREATE TABLE tenant_service_options (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    app_key VARCHAR(20) NOT NULL,
    option_key VARCHAR(30) NOT NULL,
    price_xaf INTEGER NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX tenant_service_options_unique ON tenant_service_options (tenant_id, app_key, option_key);
