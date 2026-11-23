-- Documents emis a la porte de la maternelle publique (C-106, T727) : un registre minimal pour retrouver et revoquer le bon document du
-- socle. DOC-21 carte d'une autorisation (source = identifiant de l'autorisation) et DOC-19 registre d'un jour (source = la date). Le
-- contenu reste dans le module Documents ; aucune donnee personnelle n'est copiee ici.
CREATE TABLE pn_issued_documents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    source_id VARCHAR(40) NOT NULL,
    learner_id VARCHAR(40) NULL,
    document_id VARCHAR(40) NOT NULL,
    doc_number VARCHAR(60) NOT NULL,
    verification_token VARCHAR(120) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    status VARCHAR(10) NOT NULL,
    issued_by VARCHAR(40) NOT NULL,
    issued_at VARCHAR(32) NOT NULL,
    revoked_at VARCHAR(32) NULL,
    revoke_reason VARCHAR(255) NULL
);
CREATE INDEX pn_issued_documents_source ON pn_issued_documents (tenant_id, establishment_id, kind, source_id, status);
CREATE INDEX pn_issued_documents_learner ON pn_issued_documents (tenant_id, establishment_id, learner_id)
