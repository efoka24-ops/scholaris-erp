-- Pieces du dossier, dossier de sante (champs chiffres) et consentements (jamais supprimes).

CREATE TABLE person_documents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    kind VARCHAR(30) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    size_bytes INTEGER NOT NULL,
    mime_type VARCHAR(60) NOT NULL,
    expires_on VARCHAR(10) NULL,
    uploaded_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX person_documents_person ON person_documents (tenant_id, person_id);

CREATE TABLE health_records (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    payload_enc TEXT NOT NULL,
    updated_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX health_records_person ON health_records (tenant_id, person_id);

CREATE TABLE consents (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    purpose VARCHAR(30) NOT NULL,
    granted_at VARCHAR(32) NOT NULL,
    granted_by VARCHAR(40) NULL,
    revoked_at VARCHAR(32) NULL,
    revoked_by VARCHAR(40) NULL
);

CREATE INDEX consents_person ON consents (tenant_id, person_id, purpose);
