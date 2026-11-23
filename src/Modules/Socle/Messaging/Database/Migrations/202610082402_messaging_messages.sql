-- Messaging (S05) : messages (envoi unitaire ou en masse) et suivi de délivrance par destinataire.

CREATE TABLE messages (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    created_by VARCHAR(40) NULL,
    template_key VARCHAR(60) NULL,
    body TEXT NULL,
    channels TEXT NOT NULL,
    variables TEXT NOT NULL,
    audience TEXT NOT NULL,
    urgent SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL,
    scheduled_at VARCHAR(32) NULL,
    recipients_count INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX messages_scope ON messages (tenant_id, establishment_id, id);

CREATE TABLE message_deliveries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    message_id CHAR(26) NOT NULL,
    recipient_id VARCHAR(40) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    status VARCHAR(12) NOT NULL,
    reason VARCHAR(40) NULL,
    provider_reference VARCHAR(80) NULL,
    variables TEXT NULL,
    not_before VARCHAR(32) NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    sent_at VARCHAR(32) NULL,
    delivered_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX message_deliveries_message ON message_deliveries (tenant_id, message_id, id);

CREATE INDEX message_deliveries_due ON message_deliveries (status, not_before);

CREATE INDEX message_deliveries_provider ON message_deliveries (tenant_id, provider_reference)
