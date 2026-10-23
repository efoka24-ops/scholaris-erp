-- Platform : demandes de régularisation par déclaration de paiement.
-- Le responsable d'un établissement déclare un paiement mobile money fait à TRU GROUP (numéro payeur, type total ou partiel,
-- moyen, référence). Seule une vérification (webhook de la passerelle ou confirmation du super admin) débloque : la
-- déclaration ne débloque jamais à elle seule.
-- Portable MySQL 8 et SQLite : dates ISO 8601 en texte, aucune clé étrangère, aucun mot réservé.

CREATE TABLE platform_regularisation_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    tenant_application_id CHAR(26) NOT NULL,
    app_key VARCHAR(20) NOT NULL,
    -- Clé d'idempotence fournie par le client : un renvoi du même formulaire ne crée pas de seconde demande.
    idempotency_key VARCHAR(80) NULL,
    -- total (solde dû) ou partial (montant saisi).
    kind VARCHAR(10) NOT NULL,
    -- orange_money ou mtn_momo.
    channel VARCHAR(20) NOT NULL,
    payer_phone VARCHAR(20) NOT NULL,
    -- Référence de la transaction telle que saisie, et sa forme normalisée (majuscules, lettres et chiffres) pour les comparaisons.
    reference VARCHAR(120) NOT NULL,
    reference_key VARCHAR(120) NOT NULL,
    declared_amount_xaf INTEGER NOT NULL,
    -- Solde dû au moment de la déclaration et échéance du cycle impayé concerné.
    balance_xaf INTEGER NOT NULL,
    cycle_due_on VARCHAR(10) NOT NULL,
    -- declared (en vérification), verified, rejected, expired.
    state VARCHAR(12) NOT NULL,
    -- tenant|application tant que la demande est ouverte (declared), sinon NULL : une seule demande ouverte à la fois.
    open_key VARCHAR(80) NULL,
    -- Référence normalisée une fois vérifiée : une même référence ne peut valider qu'une seule demande.
    verified_key VARCHAR(120) NULL,
    -- webhook ou manual.
    verified_via VARCHAR(10) NULL,
    confirmed_amount_xaf INTEGER NULL,
    confirmed_reference VARCHAR(120) NULL,
    payment_id CHAR(26) NULL,
    decision_reason VARCHAR(500) NULL,
    review_note VARCHAR(500) NULL,
    declared_by VARCHAR(40) NOT NULL,
    declared_at VARCHAR(32) NOT NULL,
    expires_on VARCHAR(10) NOT NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    expired_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX platform_regularisation_requests_open ON platform_regularisation_requests (open_key);
CREATE UNIQUE INDEX platform_regularisation_requests_verified ON platform_regularisation_requests (verified_key);
CREATE UNIQUE INDEX platform_regularisation_requests_idempotency ON platform_regularisation_requests (tenant_id, idempotency_key);
CREATE INDEX platform_regularisation_requests_tenant ON platform_regularisation_requests (tenant_id, declared_at);
CREATE INDEX platform_regularisation_requests_state ON platform_regularisation_requests (state, expires_on);

-- Crédit d'un paiement partiel confirmé pour le cycle impayé en cours (null : aucun) et échéance du cycle au premier versement.
-- Remis à zéro par le paiement qui couvre le solde.
ALTER TABLE tenant_applications ADD COLUMN regularisation_credit_xaf INTEGER NULL;
ALTER TABLE tenant_applications ADD COLUMN regularisation_credit_anchor VARCHAR(10) NULL;
