-- Secondaire, reprise de données d'un ancien logiciel (T977, E4-C) : lots d'import par fichier joint (classes, élèves et
-- inscriptions, soldes de frais d'ouverture). Un lot naît toujours d'une SIMULATION (rapport ligne par ligne, rien d'écrit hors du
-- lot) et ne s'applique que s'il n'a aucune ligne en erreur. Idempotent : une ligne déjà reprise (même clé, lot appliqué) est un
-- doublon ignoré. Cloisonné par client et par établissement.
-- Portable MySQL 8 / SQLite : ULID CHAR(26), dates ISO 8601 en texte VARCHAR(32), aucune clé étrangère.

CREATE TABLE se_migration_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_kind VARCHAR(16) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    entry_date VARCHAR(10) NULL,
    source_format VARCHAR(8) NOT NULL,
    file_name VARCHAR(160) NULL,
    file_sha256 CHAR(64) NOT NULL,
    batch_status VARCHAR(16) NOT NULL,
    rows_total INTEGER NOT NULL DEFAULT 0,
    rows_ok INTEGER NOT NULL DEFAULT 0,
    rows_error INTEGER NOT NULL DEFAULT 0,
    rows_duplicate INTEGER NOT NULL DEFAULT 0,
    rows_applied INTEGER NOT NULL DEFAULT 0,
    unknown_columns TEXT NULL,
    payload LONGTEXT NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    applied_by VARCHAR(40) NULL,
    applied_at VARCHAR(32) NULL
);

CREATE INDEX se_migration_batches_kind ON se_migration_batches (tenant_id, establishment_id, import_kind, batch_status);

-- Une ligne par ligne du fichier : verdict de la simulation (valid, duplicate, error), messages, puis, après application, l'objet
-- créé (`object_ref` : classe, personne ou facture). `live` vaut 1 quand la ligne a été reprise : il porte la clé de reprise.
CREATE TABLE se_migration_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    import_kind VARCHAR(16) NOT NULL,
    academic_year_id VARCHAR(40) NOT NULL,
    line_no INTEGER NOT NULL,
    item_status VARCHAR(12) NOT NULL,
    key_ref VARCHAR(160) NOT NULL,
    label VARCHAR(200) NULL,
    messages_json TEXT NULL,
    object_ref VARCHAR(80) NULL,
    live INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX se_migration_items_line ON se_migration_items (tenant_id, establishment_id, batch_id, line_no);
CREATE INDEX se_migration_items_key ON se_migration_items (tenant_id, establishment_id, import_kind, academic_year_id, key_ref, live);
