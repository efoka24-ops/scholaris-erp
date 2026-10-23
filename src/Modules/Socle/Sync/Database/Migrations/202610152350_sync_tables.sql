-- PP6 Sync : appareils (clé de signature chiffrée, révocables), lots hors ligne reçus et éléments traités.
-- Portable MySQL 8 et SQLite : identifiants ULID, dates ISO 8601 en texte, aucune clé étrangère. Chaque table porte tenant_id
-- et establishment_id. Aucune clé d'appareil en clair : key_cipher contient le texte chiffré (libsodium secretbox, base64).

CREATE TABLE sync_devices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    key_cipher VARCHAR(255) NOT NULL,
    registered_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    last_seen_at VARCHAR(32) NULL,
    revoked_at VARCHAR(32) NULL,
    revoked_by VARCHAR(40) NULL
);

CREATE INDEX sync_devices_establishment ON sync_devices (tenant_id, establishment_id, id);

CREATE TABLE sync_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    device_id CHAR(26) NOT NULL,
    batch_id VARCHAR(128) NOT NULL,
    body_hash CHAR(64) NOT NULL,
    submitted_by VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    item_count INTEGER NOT NULL,
    received_at VARCHAR(32) NOT NULL,
    locked_at VARCHAR(32) NULL,
    processed_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX sync_batches_client_batch ON sync_batches (tenant_id, batch_id);
CREATE INDEX sync_batches_queue ON sync_batches (status, received_at, id);

CREATE TABLE sync_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_ref CHAR(26) NOT NULL,
    item_no INTEGER NOT NULL,
    kind VARCHAR(60) NOT NULL,
    client_uuid VARCHAR(64) NOT NULL,
    status VARCHAR(10) NOT NULL,
    result_code INTEGER NULL,
    payload TEXT NULL,
    result TEXT NULL,
    created_at VARCHAR(32) NOT NULL,
    processed_at VARCHAR(32) NULL
);

CREATE UNIQUE INDEX sync_items_batch_item ON sync_items (tenant_id, batch_ref, item_no);
CREATE INDEX sync_items_client ON sync_items (tenant_id, establishment_id, kind, client_uuid, status);
