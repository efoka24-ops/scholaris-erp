-- Module 8 : communication multicanal.
-- Les preferences de canal vivent dans une table dediee plutot que sur users.

CREATE TABLE communication_templates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    subject_fr VARCHAR(255) NULL,
    subject_en VARCHAR(255) NULL,
    body_fr TEXT NOT NULL,
    body_en TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT comm_templates_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id)
);

CREATE UNIQUE INDEX comm_templates_tenant_code_unique ON communication_templates (tenant_id, code);

CREATE TABLE communication_messages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    template_id CHAR(36) NULL,
    channel VARCHAR(20) NOT NULL,
    recipient_user_id CHAR(36) NOT NULL,
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NULL,
    CONSTRAINT comm_messages_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT comm_messages_template_fk FOREIGN KEY (template_id) REFERENCES communication_templates (id),
    CONSTRAINT comm_messages_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users (id)
);

CREATE INDEX comm_messages_status_index ON communication_messages (tenant_id, status);

CREATE TABLE user_channel_preferences (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    preferred_channel VARCHAR(20) NOT NULL,
    fallback_channel VARCHAR(20) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT ucp_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT ucp_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE UNIQUE INDEX ucp_user_unique ON user_channel_preferences (user_id);

CREATE TABLE internal_messages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    sender_user_id CHAR(36) NOT NULL,
    recipient_user_id CHAR(36) NOT NULL,
    body TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NULL,
    CONSTRAINT internal_messages_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT internal_messages_sender_fk FOREIGN KEY (sender_user_id) REFERENCES users (id),
    CONSTRAINT internal_messages_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users (id)
);

CREATE INDEX internal_messages_recipient_index ON internal_messages (tenant_id, recipient_user_id, read_at);
