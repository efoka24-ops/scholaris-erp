-- Maternelle privée : services garderie, cantine et transport (MQ-22, EX-MAT-29). Le montant n'est jamais ici : il vient du frais
-- du catalogue du socle (`fee_item_id`). Un abonnement est facturé une fois par cycle (référence stable de facture du socle) ; la
-- présence au service se note par jour.
CREATE TABLE nu_service_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    label VARCHAR(160) NOT NULL,
    fee_item_id VARCHAR(40) NOT NULL,
    billing_cycle VARCHAR(12) NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_service_plans_kind ON nu_service_plans (tenant_id, establishment_id, kind);

CREATE TABLE nu_service_subscriptions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_service_sub_once ON nu_service_subscriptions (tenant_id, learner_id, plan_id, starts_on);

-- Facture du socle émise pour un abonnement et un cycle (mois AAAA-MM, trimestre AAAA-Tn ou année AAAA) : jamais deux fois.
CREATE TABLE nu_service_invoices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subscription_id CHAR(26) NOT NULL,
    billing_period VARCHAR(12) NOT NULL,
    invoice_id VARCHAR(40) NOT NULL,
    amount_xaf INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_service_invoices_once ON nu_service_invoices (tenant_id, subscription_id, billing_period);

CREATE TABLE nu_service_attendance (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    service_day VARCHAR(10) NOT NULL,
    present SMALLINT NOT NULL,
    recorded_by VARCHAR(40) NOT NULL,
    recorded_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_service_attendance_once ON nu_service_attendance (tenant_id, plan_id, learner_id, service_day)
