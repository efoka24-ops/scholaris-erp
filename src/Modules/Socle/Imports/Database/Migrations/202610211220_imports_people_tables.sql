-- Imports en masse des acteurs d'un établissement (T-001) : lots d'analyse et lignes normalisées.
-- Le fichier brut n'est JAMAIS conservé : seules les lignes normalisées et leur état restent, le temps du lot (expires_at).
-- Après l'application, les données personnelles des lignes appliquées sont effacées ; les lignes en erreur gardent leurs valeurs
-- jusqu'à l'expiration du lot (pour le fichier d'erreurs à corriger). Aucun mot de passe provisoire n'est stocké nulle part.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère.

CREATE TABLE import_people_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    source_format VARCHAR(8) NOT NULL,
    file_name VARCHAR(160) NULL,
    batch_status VARCHAR(12) NOT NULL,
    credentials_mode VARCHAR(24) NULL,
    send_codes SMALLINT NOT NULL DEFAULT 0,
    lines_total INTEGER NOT NULL DEFAULT 0,
    lines_valid INTEGER NOT NULL DEFAULT 0,
    lines_error INTEGER NOT NULL DEFAULT 0,
    lines_duplicate INTEGER NOT NULL DEFAULT 0,
    lines_applied INTEGER NOT NULL DEFAULT 0,
    lines_failed INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL,
    expires_at VARCHAR(32) NOT NULL,
    locked_at VARCHAR(32) NULL,
    committed_by VARCHAR(40) NULL,
    committed_at VARCHAR(32) NULL
);

CREATE INDEX import_people_batches_scope ON import_people_batches (tenant_id, establishment_id, expires_at);

CREATE TABLE import_people_lines (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    line_no INTEGER NOT NULL,
    line_status VARCHAR(12) NOT NULL,
    last_name VARCHAR(100) NULL,
    first_name VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(20) NULL,
    locale VARCHAR(2) NULL,
    role_code VARCHAR(40) NULL,
    scope_type VARCHAR(16) NULL,
    scope_code VARCHAR(80) NULL,
    scope_id VARCHAR(40) NULL,
    starts_on VARCHAR(10) NULL,
    ends_on VARCHAR(10) NULL,
    note VARCHAR(200) NULL,
    messages_json TEXT NULL,
    person_id VARCHAR(40) NULL,
    user_id VARCHAR(40) NULL
);

CREATE UNIQUE INDEX import_people_lines_line ON import_people_lines (tenant_id, batch_id, line_no);
CREATE INDEX import_people_lines_status ON import_people_lines (tenant_id, establishment_id, batch_id, line_status);
