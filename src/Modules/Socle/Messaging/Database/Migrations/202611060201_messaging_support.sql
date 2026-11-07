-- Messaging (C-113) : conversations de support plateforme <-> établissement (nature « support »), statut et assignation
-- côté support, auteur « plateforme » d'un message, limitation des notifications e-mail et SMS (une par conversation,
-- par personne et par heure). Une seule colonne par ALTER TABLE (portabilité SQLite).

ALTER TABLE conversations ADD COLUMN support_status VARCHAR(12) NULL;

ALTER TABLE conversations ADD COLUMN support_assignee VARCHAR(40) NULL;

ALTER TABLE conversations ADD COLUMN platform_read_message_id CHAR(26) NULL;

ALTER TABLE conversation_messages ADD COLUMN sender_side VARCHAR(12) NULL;

CREATE INDEX conversations_support ON conversations (kind, support_status, id);

CREATE TABLE conversation_notification_marks (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    conversation_id CHAR(26) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    last_sent_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX conversation_notification_marks_unique ON conversation_notification_marks (tenant_id, conversation_id, person_id, channel);

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01JC6000000000000000MSG201', 'platform', 'platform', 'chat_new_message', 'fr', 'sms', NULL, 'Bonjour :first_name, vous avez un nouveau message dans votre messagerie SCHOLARIS. :establishment_name', '["first_name","establishment_name"]', '2026-11-06T00:00:00+01:00', '2026-11-06T00:00:00+01:00'),
('01JC6000000000000000MSG202', 'platform', 'platform', 'chat_new_message', 'en', 'sms', NULL, 'Hello :first_name, you have a new message in your SCHOLARIS inbox. :establishment_name', '["first_name","establishment_name"]', '2026-11-06T00:00:00+01:00', '2026-11-06T00:00:00+01:00');

-- Ouverture d'une conversation avec le support SCHOLARIS : administrateurs du client et direction.
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.support');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.support');
