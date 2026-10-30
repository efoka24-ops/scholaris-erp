-- Primaire privé, services aux familles (EX-PRI-36, Could ; Q12, à confirmer) : cantine, garderie, transport facturés au forfait par
-- cycle (trimestre, mois ou année). Une facture par abonnement et par cycle (pri_service_charges, unicité), émise par le socle Billing.
-- Portable MySQL 8 / SQLite : ULID, dates ISO 8601 en texte, montants entiers XAF, aucune clé étrangère, aucun mot réservé.

CREATE TABLE pri_service_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    service_kind VARCHAR(20) NOT NULL,
    label VARCHAR(160) NOT NULL,
    fee_code VARCHAR(40) NOT NULL,
    billing_cycle VARCHAR(12) NOT NULL,
    capacity INTEGER NULL,
    route_label VARCHAR(160) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_service_plans_kind ON pri_service_plans (tenant_id, establishment_id, service_kind);

CREATE TABLE pri_service_subscriptions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    status VARCHAR(12) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX pri_service_subscriptions_person ON pri_service_subscriptions (tenant_id, establishment_id, person_id);
CREATE INDEX pri_service_subscriptions_plan ON pri_service_subscriptions (tenant_id, establishment_id, plan_id, status);

CREATE TABLE pri_service_charges (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subscription_id CHAR(26) NOT NULL,
    cycle_key VARCHAR(40) NOT NULL,
    invoice_id VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX pri_service_charges_cycle ON pri_service_charges (tenant_id, establishment_id, subscription_id, cycle_key);
