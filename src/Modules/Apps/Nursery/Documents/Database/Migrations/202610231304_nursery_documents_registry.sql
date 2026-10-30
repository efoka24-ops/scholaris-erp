-- Maternelle privée : registre des documents émis par le module Documents du socle (carnet de suivi, carte des personnes autorisées,
-- registre de sortie, fiche de liaison). Le document lui-même (numéro, QR, contenu figé, révocation) reste dans le socle ; cette table
-- relie un document à l'objet de l'application qui l'a produit (carnet, autorisation, jour, fiche), pour le retrouver et le révoquer.
CREATE TABLE nu_issued_documents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    source_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NULL,
    document_id VARCHAR(40) NOT NULL,
    doc_number VARCHAR(80) NOT NULL,
    verification_token VARCHAR(64) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    status VARCHAR(10) NOT NULL,
    issued_by VARCHAR(40) NOT NULL,
    issued_at VARCHAR(32) NOT NULL,
    revoked_at VARCHAR(32) NULL,
    revoke_reason VARCHAR(255) NULL
);
CREATE INDEX nu_issued_documents_source ON nu_issued_documents (tenant_id, establishment_id, kind, source_id);
CREATE INDEX nu_issued_documents_learner ON nu_issued_documents (tenant_id, establishment_id, learner_id)
