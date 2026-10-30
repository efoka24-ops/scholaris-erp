-- Identité, comptes et sécurité d'accès (S01). Aucune clé étrangère : couplage lâche entre modules.
-- Dates : texte ISO 8601 au fuseau Africa/Douala. Identifiants : ULID (26 caractères).

CREATE TABLE people (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(190) NULL,
    locale VARCHAR(5) NOT NULL DEFAULT 'fr',
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX people_tenant ON people (tenant_id);

CREATE TABLE users (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NULL,
    locale VARCHAR(5) NOT NULL DEFAULT 'fr',
    status VARCHAR(10) NOT NULL,
    two_factor_secret TEXT NULL,
    two_factor_confirmed_at VARCHAR(32) NULL,
    two_factor_last_step BIGINT NOT NULL DEFAULT 0,
    last_login_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX users_tenant_phone ON users (tenant_id, phone);
CREATE UNIQUE INDEX users_tenant_email ON users (tenant_id, email);
CREATE INDEX users_phone ON users (phone);
CREATE INDEX users_email ON users (email);
CREATE INDEX users_person ON users (tenant_id, person_id);

CREATE TABLE one_time_codes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    user_id CHAR(26) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at VARCHAR(32) NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    verified_at VARCHAR(32) NULL,
    ticket_hash CHAR(64) NULL,
    ticket_expires_at VARCHAR(32) NULL,
    consumed_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX one_time_codes_user ON one_time_codes (user_id, created_at);
CREATE INDEX one_time_codes_ticket ON one_time_codes (ticket_hash);

CREATE TABLE devices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    user_id CHAR(26) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    location VARCHAR(120) NULL,
    ip VARCHAR(45) NULL,
    last_seen_at VARCHAR(32) NOT NULL,
    trusted_until VARCHAR(32) NULL,
    revoked_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX devices_user ON devices (tenant_id, user_id);

CREATE TABLE access_tokens (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    user_id CHAR(26) NOT NULL,
    device_id CHAR(26) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    remember SMALLINT NOT NULL DEFAULT 0,
    expires_at VARCHAR(32) NOT NULL,
    last_used_at VARCHAR(32) NULL,
    needs_two_factor SMALLINT NOT NULL DEFAULT 0,
    two_factor_verified_at VARCHAR(32) NULL,
    two_factor_attempts SMALLINT NOT NULL DEFAULT 0,
    active_establishment_id VARCHAR(40) NULL,
    active_role VARCHAR(40) NULL,
    revoked_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX access_tokens_hash ON access_tokens (token_hash);
CREATE INDEX access_tokens_user ON access_tokens (tenant_id, user_id);
CREATE INDEX access_tokens_device ON access_tokens (device_id);

CREATE TABLE backup_codes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    user_id CHAR(26) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX backup_codes_user ON backup_codes (tenant_id, user_id);

CREATE TABLE login_attempts (
    identifier_hash CHAR(64) NOT NULL PRIMARY KEY,
    failed_count SMALLINT NOT NULL DEFAULT 0,
    locked_until VARCHAR(32) NULL,
    last_otp_at VARCHAR(32) NULL,
    otp_attempts SMALLINT NOT NULL DEFAULT 0,
    otp_window_start VARCHAR(32) NULL,
    otp_count SMALLINT NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE roles (
    code VARCHAR(40) NOT NULL PRIMARY KEY,
    level VARCHAR(12) NOT NULL,
    is_sensitive SMALLINT NOT NULL DEFAULT 0,
    label_fr VARCHAR(120) NOT NULL,
    label_en VARCHAR(120) NOT NULL
);

CREATE TABLE role_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(60) NOT NULL,
    PRIMARY KEY (role, permission)
);

CREATE TABLE role_assignments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    person_id CHAR(26) NOT NULL,
    role VARCHAR(40) NOT NULL,
    scope_type VARCHAR(14) NOT NULL,
    scope_id VARCHAR(40) NULL,
    establishment_id VARCHAR(40) NULL,
    starts_at VARCHAR(32) NOT NULL,
    ends_at VARCHAR(32) NULL,
    delegated_by CHAR(26) NULL,
    created_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX role_assignments_person ON role_assignments (tenant_id, person_id);
CREATE INDEX role_assignments_establishment ON role_assignments (tenant_id, establishment_id);

CREATE TABLE service_accounts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    scopes TEXT NOT NULL,
    key_prefix VARCHAR(12) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    previous_key_hash CHAR(64) NULL,
    previous_valid_until VARCHAR(32) NULL,
    rotated_at VARCHAR(32) NULL,
    revoked_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX service_accounts_prefix ON service_accounts (key_prefix);
CREATE INDEX service_accounts_tenant ON service_accounts (tenant_id);
