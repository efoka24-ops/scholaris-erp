-- Messaging (S05, Should) : messagerie interne modérée et archivée.

CREATE TABLE conversations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    created_by VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    archived_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX conversations_scope ON conversations (tenant_id, establishment_id, id);

CREATE TABLE conversation_messages (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    conversation_id CHAR(26) NOT NULL,
    sender_id VARCHAR(40) NOT NULL,
    body TEXT NOT NULL,
    moderation_status VARCHAR(12) NOT NULL,
    moderated_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX conversation_messages_conversation ON conversation_messages (tenant_id, conversation_id, id)
