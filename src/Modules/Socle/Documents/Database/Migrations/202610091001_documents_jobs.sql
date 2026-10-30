CREATE TABLE document_jobs (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    status VARCHAR(10) NOT NULL,
    attempts INT NOT NULL,
    locked_at VARCHAR(32) NULL,
    last_error VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL,
    finished_at VARCHAR(32) NULL
);
CREATE INDEX ix_document_jobs_queue ON document_jobs (status, created_at);
CREATE INDEX ix_document_jobs_batch ON document_jobs (tenant_id, batch_id)
