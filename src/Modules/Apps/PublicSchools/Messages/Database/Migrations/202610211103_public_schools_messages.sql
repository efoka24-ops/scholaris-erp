-- Communication des écoles publiques (T640, EX-COM-01 et EX-COM-05) : journal des envois par classe, niveau ou école
-- (idempotent par client_uuid) et des annonces territoriales. Le texte des modèles est une donnée du socle Messaging.
CREATE TABLE pub_message_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    audience VARCHAR(10) NOT NULL,
    target VARCHAR(40) NULL,
    template_key VARCHAR(60) NOT NULL,
    variables TEXT NOT NULL,
    classes_count INTEGER NOT NULL,
    recipients_count INTEGER NOT NULL,
    sent_by VARCHAR(40) NOT NULL,
    client_uuid CHAR(36) NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pub_message_batches_client ON pub_message_batches (tenant_id, establishment_id, client_uuid);
CREATE INDEX pub_message_batches_school ON pub_message_batches (tenant_id, establishment_id, id);

CREATE TABLE pub_message_batch_classes (
    tenant_id VARCHAR(40) NOT NULL,
    batch_id CHAR(26) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id CHAR(26) NOT NULL,
    PRIMARY KEY (tenant_id, batch_id, structure_id)
);
CREATE INDEX pub_message_batch_classes_class ON pub_message_batch_classes (tenant_id, establishment_id, structure_id);

CREATE TABLE pub_territory_announcements (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    territory_id CHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    establishments_count INTEGER NOT NULL,
    announced_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX pub_territory_announcements_territory ON pub_territory_announcements (tenant_id, territory_id, id);
