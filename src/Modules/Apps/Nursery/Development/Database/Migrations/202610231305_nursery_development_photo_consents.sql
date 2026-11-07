-- Maternelle privée : historique du consentement photo d'un enfant (MQ-06). Le consentement en vigueur est celui du socle
-- (ConsentRecorder / ConsentChecker, vérifié à chaque lecture d'une photo) ; cette table garde la trace du recueil : formulaire papier
-- (référence de la pièce, date de signature, signataire) ou portail du parent, et la révocation (date, auteur, motif). Jamais supprimée.
CREATE TABLE nu_photo_consents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    consent_ref VARCHAR(40) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    evidence_ref VARCHAR(120) NULL,
    signed_on VARCHAR(10) NOT NULL,
    signed_by_name VARCHAR(160) NOT NULL,
    statement_version VARCHAR(20) NULL,
    recorded_by VARCHAR(40) NOT NULL,
    granted_at VARCHAR(32) NOT NULL,
    status VARCHAR(10) NOT NULL,
    revoked_at VARCHAR(32) NULL,
    revoked_by VARCHAR(40) NULL,
    revoke_reason VARCHAR(255) NULL
);
CREATE INDEX nu_photo_consents_learner ON nu_photo_consents (tenant_id, establishment_id, learner_id, status);

-- Purge des médias à échéance (MQ-07) : date de purge et auteur, la ligne reste comme trace (aucune photo conservée).
ALTER TABLE nu_lifebook_media ADD COLUMN purged_at VARCHAR(32) NULL
