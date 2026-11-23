-- Documents (SS14) : liens de téléchargement à durée limitée et à usage borné (bulletins publiés, portail). Seule l'empreinte
-- SHA-256 du jeton est conservée ; chaque tentative de téléchargement public est journalisée (adresse IP hachée).

CREATE TABLE document_download_links (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    document_id CHAR(26) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at VARCHAR(32) NOT NULL,
    max_uses INTEGER NOT NULL,
    use_count INTEGER NOT NULL DEFAULT 0,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    last_used_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX ux_document_download_links_token ON document_download_links (token_hash);
CREATE INDEX ix_document_download_links_document ON document_download_links (tenant_id, document_id);

CREATE TABLE document_download_log (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    link_id CHAR(26) NULL,
    outcome VARCHAR(12) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    accessed_at VARCHAR(32) NOT NULL
);
CREATE INDEX ix_document_download_log_link ON document_download_log (link_id, accessed_at);
