-- Socle : etablissements, comptes, RBAC, calendrier academique, audit.
--
-- SQL volontairement portable : MySQL 8 en production, SQLite pour les tests.
-- Les types ENUM sont remplaces par des VARCHAR dont les valeurs admises sont
-- appliquees par Validator ; les colonnes JSON sont des TEXT contenant du JSON.
-- Les identifiants sont des CHAR(36) generes par l'application.

CREATE TABLE tenants (
    id CHAR(36) NOT NULL PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PUBLIC',
    address VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    logo_url VARCHAR(255) NULL,
    public_enrollment_enabled TINYINT NOT NULL DEFAULT 0,
    config_json TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL
);

CREATE UNIQUE INDEX tenants_code_unique ON tenants (code);

CREATE TABLE users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    -- Nullable : un administrateur de la plateforme n appartient a aucun
    -- etablissement. Ne pas confondre avec l administrateur d une ecole.
    tenant_id CHAR(36) NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    mfa_enabled TINYINT NOT NULL DEFAULT 0,
    mfa_secret VARCHAR(255) NULL,
    last_login DATETIME NULL,
    avatar_url VARCHAR(255) NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT users_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX users_tenant_email_unique ON users (tenant_id, email);

CREATE TABLE roles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NULL
);

CREATE UNIQUE INDEX roles_tenant_name_unique ON roles (tenant_id, name);

CREATE TABLE permissions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    resource VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL
);

CREATE UNIQUE INDEX permissions_resource_action_unique ON permissions (resource, action);

CREATE TABLE role_permissions (
    role_id CHAR(36) NOT NULL,
    permission_id CHAR(36) NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
);

CREATE TABLE user_roles (
    user_id CHAR(36) NOT NULL,
    role_id CHAR(36) NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT user_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
);

CREATE TABLE academic_years (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    label VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NULL,
    CONSTRAINT academic_years_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX academic_years_tenant_label_unique ON academic_years (tenant_id, label);

-- Les periodes n'ont pas de tenant_id : leur rattachement passe par l'annee
-- academique, elle-meme scopee.
CREATE TABLE periods (
    id CHAR(36) NOT NULL PRIMARY KEY,
    academic_year_id CHAR(36) NOT NULL,
    type VARCHAR(20) NOT NULL,
    number INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    grading_status VARCHAR(20) NOT NULL DEFAULT 'CLOSED',
    CONSTRAINT periods_year_fk FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
);

CREATE UNIQUE INDEX periods_year_type_number_unique ON periods (academic_year_id, type, number);

-- Journal non scope : il trace aussi les actions du Super Admin, qui n'est
-- rattache a aucun etablissement.
CREATE TABLE audit_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NULL,
    action VARCHAR(100) NOT NULL,
    resource VARCHAR(100) NOT NULL,
    resource_id VARCHAR(255) NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NULL,
    timestamp DATETIME NULL
);

CREATE INDEX audit_logs_user_index ON audit_logs (user_id);

-- Suivi des migrations appliquees.
CREATE TABLE migrations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL
);

CREATE UNIQUE INDEX migrations_filename_unique ON migrations (filename);
