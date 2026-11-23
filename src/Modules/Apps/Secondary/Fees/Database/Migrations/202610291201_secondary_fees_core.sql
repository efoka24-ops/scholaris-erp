-- Application secondaire, sous-module Fees (T830 à T832). Les frais, factures et paiements restent dans le socle Billing ; ces
-- tables portent seulement ce que le socle n'a pas :
--  - se_fee_catalog : catalogue des frais AUTORISÉS d'un établissement public [fees_public_catalog] (RG-FIN-2). Tenu par le client
--    (administrateur fonctionnel) ; establishment_id = '*' pour une ligne valable dans tous les établissements du client. Vide à
--    l'ouverture (Q4) ; aucun montant officiel livré.
--  - se_fee_requests : demandes d'ajout au catalogue, créées par l'intendant ou automatiquement quand un frais hors catalogue est
--    refusé (422 fee_not_authorized) ; décision par une autre personne, journalisée.
--  - se_payment_plans : plan de paiement individuel [fees_private] (EX-FIN-05), validé par une autre personne que son auteur ;
--    un plan validé et à jour suspend le blocage de remise des documents (RG-DOC-4), jamais l'accès aux cours (RG-FIN-1).
-- Portable MySQL 8 et SQLite : pas de clé étrangère, pas d'AUTO_INCREMENT, aucun mot réservé ; montants entiers en XAF.

CREATE TABLE se_fee_catalog (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    fee_kind VARCHAR(20) NOT NULL,
    fee_code VARCHAR(40) NULL,
    label VARCHAR(160) NOT NULL,
    max_amount INTEGER NULL,
    cycle_code VARCHAR(10) NULL,
    legal_basis VARCHAR(255) NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_fee_catalog_kind ON se_fee_catalog (tenant_id, establishment_id, fee_kind);

CREATE TABLE se_fee_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    fee_kind VARCHAR(20) NOT NULL,
    fee_code VARCHAR(40) NULL,
    label VARCHAR(160) NOT NULL,
    amount INTEGER NOT NULL,
    justification VARCHAR(500) NULL,
    origin VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    decision_reason VARCHAR(255) NULL,
    catalog_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_fee_requests_status ON se_fee_requests (tenant_id, establishment_id, status, created_at);

CREATE TABLE se_payment_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    invoice_id VARCHAR(40) NULL,
    installments TEXT NOT NULL,
    total_amount INTEGER NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    approved_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    decision_reason VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_payment_plans_person ON se_payment_plans (tenant_id, establishment_id, person_id, status);
