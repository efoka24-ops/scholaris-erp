-- Service S06 Documents : types, modèles versionnés, documents émis, lots, demandes, cachet, consultations publiques.
-- Portable MySQL 8 / SQLite : identifiants ULID, dates ISO 8601 (Africa/Douala), aucune clé étrangère.
-- tenant_id = '*' désigne le catalogue de la plateforme (types et modèles fournis, lecture seule pour les clients).

CREATE TABLE document_types (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    code VARCHAR(30) NOT NULL,
    label VARCHAR(160) NOT NULL,
    category VARCHAR(20) NOT NULL,
    type_letter CHAR(1) NOT NULL,
    number_template VARCHAR(80) NOT NULL,
    qr_enabled SMALLINT NOT NULL,
    blockable SMALLINT NOT NULL,
    variables TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_document_types_code ON document_types (tenant_id, establishment_id, code);

CREATE TABLE document_templates (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    type_code VARCHAR(30) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    html MEDIUMTEXT NOT NULL,
    version INTEGER NOT NULL,
    validated_at VARCHAR(32) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ux_document_templates_version ON document_templates (tenant_id, establishment_id, type_code, locale, version);

CREATE TABLE documents (
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
    signed_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX ux_documents_number ON documents (tenant_id, establishment_id, number);
CREATE UNIQUE INDEX ux_documents_token ON documents (verification_token);
CREATE INDEX ix_documents_registry ON documents (tenant_id, establishment_id, issued_at);
CREATE INDEX ix_documents_subject ON documents (tenant_id, subject_person_id);

CREATE TABLE document_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    type_code VARCHAR(30) NOT NULL,
    locale VARCHAR(5) NOT NULL,
    status VARCHAR(12) NOT NULL,
    total INTEGER NOT NULL,
    processed INTEGER NOT NULL,
    failed INTEGER NOT NULL,
    excluded INTEGER NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    finished_at VARCHAR(32) NULL
);
CREATE INDEX ix_document_batches_scope ON document_batches (tenant_id, establishment_id, created_at);

CREATE TABLE document_batch_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    subject_person_id VARCHAR(40) NOT NULL,
    data_json TEXT NOT NULL,
    status VARCHAR(10) NOT NULL,
    reason VARCHAR(255) NULL,
    document_id CHAR(26) NULL
);
CREATE INDEX ix_document_batch_items_batch ON document_batch_items (tenant_id, batch_id, status);

CREATE TABLE document_requests (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    type_code VARCHAR(30) NOT NULL,
    subject_person_id VARCHAR(40) NOT NULL,
    requested_by VARCHAR(40) NOT NULL,
    note VARCHAR(500) NULL,
    status VARCHAR(10) NOT NULL,
    document_id CHAR(26) NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX ix_document_requests_scope ON document_requests (tenant_id, establishment_id, status);

CREATE TABLE document_seals (
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_title VARCHAR(160) NULL,
    signature_image MEDIUMTEXT NULL,
    stamp_image MEDIUMTEXT NULL,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (tenant_id, establishment_id)
);

CREATE TABLE verification_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    token VARCHAR(64) NOT NULL,
    outcome VARCHAR(10) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    consulted_at VARCHAR(32) NOT NULL
);
CREATE INDEX ix_verification_log_token ON verification_log (token, consulted_at)
