-- Application Supérieur (IPES), module DOC : identité documentaire de chaque école (en-tête, logo, couleur, code du numéro,
-- langue par défaut ; RG-ORG-4, RG-DOC-8), versionnée (RG-DOC-6), et registre des documents émis par l'application
-- (lien avec le document du socle, école, filière, version du profil, annulation sans suppression RG-DOC-5, duplicata RG-DOC-4).

CREATE TABLE hi_document_school_profiles (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    school_code VARCHAR(10) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    header_text VARCHAR(500) NULL,
    logo_url VARCHAR(500) NULL,
    primary_color VARCHAR(7) NOT NULL,
    default_locale VARCHAR(5) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_document_school_profiles_version ON hi_document_school_profiles (tenant_id, establishment_id, school_id, version);

CREATE TABLE hi_issued_documents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    document_id VARCHAR(40) NOT NULL,
    doc_code VARCHAR(10) NOT NULL,
    socle_type VARCHAR(30) NOT NULL,
    doc_number VARCHAR(80) NOT NULL,
    verification_token VARCHAR(64) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    school_id VARCHAR(40) NOT NULL,
    program_id VARCHAR(40) NULL,
    level_id VARCHAR(40) NULL,
    locale VARCHAR(5) NOT NULL,
    profile_version INTEGER NOT NULL,
    doc_status VARCHAR(12) NOT NULL,
    cancel_reason VARCHAR(500) NULL,
    replaced_by CHAR(26) NULL,
    cancelled_by VARCHAR(40) NULL,
    cancelled_at VARCHAR(32) NULL,
    duplicate_count INTEGER NOT NULL,
    last_duplicate_at VARCHAR(32) NULL,
    issued_by VARCHAR(40) NOT NULL,
    issued_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX hi_issued_documents_document ON hi_issued_documents (tenant_id, document_id);
CREATE INDEX hi_issued_documents_person ON hi_issued_documents (tenant_id, person_id);
CREATE INDEX hi_issued_documents_scope ON hi_issued_documents (tenant_id, establishment_id, school_id);
