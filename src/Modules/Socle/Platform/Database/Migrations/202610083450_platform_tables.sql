-- Platform : catalogue d'applications, droits d'usage par client, demandes d'accès publiques, abonnements et paiements.
-- Portable MySQL 8 et SQLite : identifiants ULID, dates ISO 8601 en texte, montants entiers en XAF, aucune clé étrangère.

-- Catalogue (données de plateforme, hors client).
CREATE TABLE platform_applications (
    app_key VARCHAR(20) NOT NULL PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    target_type VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    version VARCHAR(20) NOT NULL,
    required_modules TEXT NOT NULL,
    monthly_price_xaf INTEGER NOT NULL,
    trial_days INTEGER NOT NULL,
    grace_days INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

-- Droit d'usage d'une application par un client (un seul par couple client, application).
CREATE TABLE tenant_applications (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    app_key VARCHAR(20) NOT NULL,
    state VARCHAR(16) NOT NULL,
    source VARCHAR(20) NOT NULL,
    started_on VARCHAR(10) NOT NULL,
    trial_ends_on VARCHAR(10) NOT NULL,
    paid_until VARCHAR(10) NULL,
    monthly_price_xaf INTEGER NULL,
    past_due_since VARCHAR(10) NULL,
    suspended_at VARCHAR(32) NULL,
    closed_at VARCHAR(32) NULL,
    state_reason VARCHAR(500) NULL,
    opened_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX tenant_applications_unique ON tenant_applications (tenant_id, app_key);
CREATE INDEX tenant_applications_state ON tenant_applications (state);

-- Contact de facturation et de notification d'un client (issu de la demande d'accès).
CREATE TABLE platform_clients (
    tenant_id VARCHAR(40) NOT NULL PRIMARY KEY,
    contact_name VARCHAR(160) NOT NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(20) NULL,
    locale VARCHAR(5) NOT NULL,
    access_request_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

-- Demandes d'accès publiques (avant toute création de client). Le jeton de suivi n'est conservé que haché.
CREATE TABLE platform_access_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    institution_name VARCHAR(160) NOT NULL,
    target_type VARCHAR(16) NOT NULL,
    city VARCHAR(80) NOT NULL,
    region VARCHAR(80) NOT NULL,
    country VARCHAR(2) NOT NULL,
    contact_first_name VARCHAR(80) NOT NULL,
    contact_last_name VARCHAR(80) NOT NULL,
    contact_email VARCHAR(190) NOT NULL,
    contact_phone VARCHAR(20) NULL,
    contact_position VARCHAR(80) NULL,
    ministerial_authorization VARCHAR(120) NULL,
    app_key VARCHAR(20) NOT NULL,
    request_message TEXT NULL,
    locale VARCHAR(5) NOT NULL,
    status VARCHAR(16) NOT NULL,
    decision_reason TEXT NULL,
    info_request TEXT NULL,
    complement TEXT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    tenant_id VARCHAR(40) NULL,
    establishment_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX platform_access_requests_token ON platform_access_requests (token_hash);
CREATE INDEX platform_access_requests_status ON platform_access_requests (status, created_at);

-- Paiements d'abonnement (hors module Billing : aucune facture, un reçu simple).
CREATE TABLE platform_payments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    tenant_application_id CHAR(26) NOT NULL,
    app_key VARCHAR(20) NOT NULL,
    reference VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(80) NULL,
    idempotency_key VARCHAR(80) NULL,
    amount_xaf INTEGER NOT NULL,
    months INTEGER NOT NULL,
    channel VARCHAR(20) NOT NULL,
    payer_phone VARCHAR(20) NOT NULL,
    status VARCHAR(16) NOT NULL,
    receipt_number VARCHAR(40) NULL,
    covered_from VARCHAR(10) NULL,
    covered_until VARCHAR(10) NULL,
    initiated_by VARCHAR(40) NOT NULL,
    paid_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX platform_payments_reference ON platform_payments (reference);
CREATE UNIQUE INDEX platform_payments_provider ON platform_payments (provider_reference);
CREATE UNIQUE INDEX platform_payments_idempotency ON platform_payments (tenant_id, idempotency_key);
CREATE INDEX platform_payments_tenant ON platform_payments (tenant_id, created_at);

-- Événements de webhook déjà traités : un rejeu ne crédite jamais deux fois.
CREATE TABLE platform_payment_events (
    event_id VARCHAR(120) NOT NULL PRIMARY KEY,
    payment_id CHAR(26) NULL,
    outcome VARCHAR(30) NOT NULL,
    received_at VARCHAR(32) NOT NULL
);

-- Relances d'échéance envoyées (J-14, J-3, J0) : une seule par échéance et par palier.
CREATE TABLE platform_reminders (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    tenant_application_id CHAR(26) NOT NULL,
    due_on VARCHAR(10) NOT NULL,
    stage INTEGER NOT NULL,
    outcome VARCHAR(20) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX platform_reminders_unique ON platform_reminders (tenant_application_id, due_on, stage);
