-- Services annexes du secondaire (T972, T973, EX-SEC-63, 64, 66) : offres et abonnements de l'internat [boarding], de la cantine et
-- du transport [canteen_transport] (facturés par le socle Billing, InvoiceIssuer), infirmerie [infirmary] (passages, dossier de
-- santé chiffré, dispenses d'EPS) et journal de chaque consultation des données de santé. Rien n'est supprimé : un abonnement se
-- termine à une date, un module coupé laisse ses lignes en lecture seule. Portable MySQL 8 et SQLite, cloisonné par client et
-- établissement.

CREATE TABLE se_service_offers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    service_kind VARCHAR(12) NOT NULL,
    label VARCHAR(160) NOT NULL,
    stops TEXT NULL,
    capacity INTEGER NULL,
    fee_code VARCHAR(40) NULL,
    amount_xaf INTEGER NOT NULL,
    active TINYINT NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_service_offers_kind ON se_service_offers (tenant_id, establishment_id, service_kind, active);

CREATE TABLE se_service_subscriptions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    offer_id CHAR(26) NOT NULL,
    service_kind VARCHAR(12) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    enrollment_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    stop_label VARCHAR(120) NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    status VARCHAR(12) NOT NULL,
    invoice_id VARCHAR(40) NULL,
    end_reason VARCHAR(255) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_service_subscriptions_person ON se_service_subscriptions (tenant_id, establishment_id, person_id, service_kind, status);
CREATE INDEX se_service_subscriptions_offer ON se_service_subscriptions (tenant_id, establishment_id, offer_id, status);

CREATE TABLE se_infirmary_visits (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    class_id VARCHAR(40) NULL,
    visited_at VARCHAR(32) NOT NULL,
    reason_code VARCHAR(20) NOT NULL,
    outcome VARCHAR(20) NOT NULL,
    parent_called TINYINT NOT NULL,
    notes_encrypted TEXT NULL,
    pe_exempt_from VARCHAR(10) NULL,
    pe_exempt_until VARCHAR(10) NULL,
    nurse_user_id VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_infirmary_visits_person ON se_infirmary_visits (tenant_id, establishment_id, person_id, visited_at);
CREATE INDEX se_infirmary_visits_exemption ON se_infirmary_visits (tenant_id, establishment_id, pe_exempt_until);

CREATE TABLE se_health_records (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    content_encrypted TEXT NOT NULL,
    updated_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_health_records_person ON se_health_records (tenant_id, establishment_id, person_id);

CREATE TABLE se_health_access_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NULL,
    user_id VARCHAR(40) NOT NULL,
    access_kind VARCHAR(20) NOT NULL,
    object_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_health_access_log_person ON se_health_access_log (tenant_id, establishment_id, person_id, created_at);
