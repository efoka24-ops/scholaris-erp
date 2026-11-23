-- Tables du noyau. SQL portable (MySQL 8 et SQLite) : pas d'AUTO_INCREMENT ni de clause ENGINE.
-- Les identifiants métier sont des ULID (CHAR(26)) générés côté PHP.

CREATE TABLE IF NOT EXISTS idempotency_keys (
    tenant_id VARCHAR(40) NOT NULL,
    idem_key VARCHAR(128) NOT NULL,
    route VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    status_code INTEGER NULL,
    response_body TEXT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (tenant_id, idem_key)
);

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket CHAR(64) NOT NULL,
    window_start INTEGER NOT NULL,
    hits INTEGER NOT NULL,
    PRIMARY KEY (bucket, window_start)
);

CREATE TABLE IF NOT EXISTS number_sequences (
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(40) NOT NULL,
    year INTEGER NOT NULL,
    current_value INTEGER NOT NULL,
    PRIMARY KEY (tenant_id, establishment_id, kind, year)
);
