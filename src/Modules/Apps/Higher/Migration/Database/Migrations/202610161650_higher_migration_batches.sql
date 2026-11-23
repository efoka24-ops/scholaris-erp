-- Supérieur, reprise de données (lot I4) : lots d'import (étudiants en cours, UE capitalisées et dettes, soldes d'ouverture,
-- personnel enseignant et affectations, notes de l'année en cours). Un lot naît toujours d'une simulation (rien n'est écrit),
-- puis s'applique en une transaction et reste annulable tant qu'aucune donnée dépendante n'existe.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère.

CREATE TABLE hi_import_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_kind VARCHAR(16) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    source_format VARCHAR(8) NOT NULL,
    file_name VARCHAR(160) NULL,
    file_sha256 CHAR(64) NOT NULL,
    batch_status VARCHAR(16) NOT NULL,
    rows_total INTEGER NOT NULL DEFAULT 0,
    rows_ok INTEGER NOT NULL DEFAULT 0,
    rows_error INTEGER NOT NULL DEFAULT 0,
    rows_warning INTEGER NOT NULL DEFAULT 0,
    rows_duplicate INTEGER NOT NULL DEFAULT 0,
    rows_applied INTEGER NOT NULL DEFAULT 0,
    payload LONGTEXT NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    applied_by VARCHAR(40) NULL,
    applied_at VARCHAR(32) NULL,
    reverted_by VARCHAR(40) NULL,
    reverted_at VARCHAR(32) NULL,
    revert_reason VARCHAR(500) NULL
);

CREATE INDEX hi_import_batches_kind ON hi_import_batches (tenant_id, establishment_id, import_kind, batch_status);

-- Une ligne par ligne du fichier : verdict de la simulation (ok, warning, duplicate, error), messages, puis, après application,
-- l'objet créé ou repris (`object_ref`) pour pouvoir l'annuler. `live` vaut 1 tant que le lot appliqué n'est pas annulé :
-- il porte la clé de reprise (idempotence entre lots).
CREATE TABLE hi_import_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    import_kind VARCHAR(16) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    line_no INTEGER NOT NULL,
    item_status VARCHAR(12) NOT NULL,
    key_ref VARCHAR(160) NOT NULL,
    person_id VARCHAR(40) NULL,
    messages_json TEXT NULL,
    object_ref TEXT NULL,
    live INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX hi_import_items_line ON hi_import_items (tenant_id, establishment_id, batch_id, line_no);
CREATE INDEX hi_import_items_key ON hi_import_items (tenant_id, establishment_id, import_kind, academic_year_id, key_ref, live);
CREATE INDEX hi_import_items_person ON hi_import_items (tenant_id, establishment_id, person_id, live);
