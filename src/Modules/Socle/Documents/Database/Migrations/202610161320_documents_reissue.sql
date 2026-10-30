-- Documents (PP15) : réédition « Rectificatif » sous le MÊME numéro. La table est reconstruite pour que l'unicité du numéro porte
-- sur (numéro, révision) : une réédition crée une nouvelle ligne (révision + 1) et révoque la précédente.

CREATE TABLE documents_rebuilt (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    type_code VARCHAR(30) NOT NULL,
    category VARCHAR(20) NOT NULL,
    number VARCHAR(80) NOT NULL,
    verification_token VARCHAR(32) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(10) NOT NULL,
    template_id CHAR(26) NOT NULL,
    template_version INTEGER NOT NULL,
    locale VARCHAR(5) NOT NULL,
    subject_person_id VARCHAR(40) NOT NULL,
    holder_name VARCHAR(200) NOT NULL,
    holder_mask VARCHAR(200) NOT NULL,
    data_json TEXT NOT NULL,
    content MEDIUMTEXT NOT NULL,
    revoke_reason TEXT NULL,
    revoked_at VARCHAR(32) NULL,
    revoked_by VARCHAR(40) NULL,
    replaced_by CHAR(26) NULL,
    issued_by VARCHAR(40) NOT NULL,
    issued_via VARCHAR(12) NOT NULL,
    issued_at VARCHAR(32) NOT NULL,
    signed_at VARCHAR(32) NULL,
    org_unit_id VARCHAR(40) NULL,
    signer_role VARCHAR(40) NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    reissue_of CHAR(26) NULL
);

INSERT INTO documents_rebuilt (id, tenant_id, establishment_id, type_code, category, number, verification_token, fingerprint, status, template_id, template_version, locale, subject_person_id, holder_name, holder_mask, data_json, content, revoke_reason, revoked_at, revoked_by, replaced_by, issued_by, issued_via, issued_at, signed_at, org_unit_id, signer_role, revision, reissue_of)
SELECT id, tenant_id, establishment_id, type_code, category, number, verification_token, fingerprint, status, template_id, template_version, locale, subject_person_id, holder_name, holder_mask, data_json, content, revoke_reason, revoked_at, revoked_by, replaced_by, issued_by, issued_via, issued_at, signed_at, org_unit_id, signer_role, 1, NULL FROM documents;

DROP TABLE documents;
ALTER TABLE documents_rebuilt RENAME TO documents;
CREATE UNIQUE INDEX ux_documents_number ON documents (tenant_id, establishment_id, number, revision);
CREATE UNIQUE INDEX ux_documents_token ON documents (verification_token);
CREATE INDEX ix_documents_registry ON documents (tenant_id, establishment_id, issued_at);
CREATE INDEX ix_documents_subject ON documents (tenant_id, subject_person_id);
