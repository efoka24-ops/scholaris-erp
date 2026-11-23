-- Extensions IPES de Documents (S16, S17). Colonnes facultatives : les types et documents existants gardent leur comportement.
-- Rôle du signataire attendu pour un type (directeur d'école, directeur général, président du jury, comptable...).
ALTER TABLE document_types ADD COLUMN signer_role VARCHAR(40) NULL;

-- Signataires par rôle, pour tout l'établissement (org_unit_id = '') ou pour une école interne (org_unit_id renseigné).
CREATE TABLE document_signers (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    signer_role VARCHAR(40) NOT NULL,
    org_unit_id VARCHAR(40) NOT NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_title VARCHAR(160) NULL,
    signature_image MEDIUMTEXT NULL,
    stamp_image MEDIUMTEXT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_document_signers ON document_signers (tenant_id, establishment_id, signer_role, org_unit_id);

-- Traçabilité de l'émission : école émettrice et rôle du signataire retenu.
ALTER TABLE documents ADD COLUMN org_unit_id VARCHAR(40) NULL;
ALTER TABLE documents ADD COLUMN signer_role VARCHAR(40) NULL;
