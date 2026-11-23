-- Messaging (S05, Should) : messagerie interne modérée. Participants, signalements, pièces jointes par référence
-- de document, motif de modération. Une seule colonne par ALTER TABLE (portabilité SQLite).

ALTER TABLE conversations ADD COLUMN kind VARCHAR(20) NULL;

ALTER TABLE conversations ADD COLUMN learner_id VARCHAR(40) NULL;

ALTER TABLE conversations ADD COLUMN last_message_at VARCHAR(32) NULL;

ALTER TABLE conversation_messages ADD COLUMN attachments TEXT NULL;

ALTER TABLE conversation_messages ADD COLUMN moderated_at VARCHAR(32) NULL;

ALTER TABLE conversation_messages ADD COLUMN moderation_reason VARCHAR(160) NULL;

CREATE TABLE conversation_participants (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    conversation_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    chat_role VARCHAR(12) NOT NULL,
    last_read_message_id CHAR(26) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX conversation_participants_unique ON conversation_participants (tenant_id, conversation_id, person_id);

CREATE INDEX conversation_participants_person ON conversation_participants (tenant_id, establishment_id, person_id, conversation_id);

CREATE TABLE conversation_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    conversation_id CHAR(26) NOT NULL,
    message_id CHAR(26) NOT NULL,
    reported_by VARCHAR(40) NOT NULL,
    reason VARCHAR(200) NOT NULL,
    status VARCHAR(12) NOT NULL,
    resolved_by VARCHAR(40) NULL,
    resolved_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX conversation_reports_unique ON conversation_reports (tenant_id, message_id, reported_by);

CREATE INDEX conversation_reports_scope ON conversation_reports (tenant_id, establishment_id, id);

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01J9T0000000000000000PLT11', 'platform', 'platform', 'chat_new_message', 'fr', 'push', NULL, 'Nouveau message dans votre messagerie SCHOLARIS. :establishment_name', '["establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('01J9T0000000000000000PLT12', 'platform', 'platform', 'chat_new_message', 'en', 'push', NULL, 'New message in your SCHOLARIS inbox. :establishment_name', '["establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('01J9T0000000000000000PLT13', 'platform', 'platform', 'chat_new_message', 'fr', 'email', 'Nouveau message SCHOLARIS', 'Vous avez un nouveau message dans votre messagerie SCHOLARIS. Connectez-vous pour le lire. :establishment_name', '["establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('01J9T0000000000000000PLT14', 'platform', 'platform', 'chat_new_message', 'en', 'email', 'New SCHOLARIS message', 'You have a new message in your SCHOLARIS inbox. Sign in to read it. :establishment_name', '["establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00')
