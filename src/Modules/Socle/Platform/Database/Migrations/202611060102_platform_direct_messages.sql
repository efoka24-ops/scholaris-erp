-- C-112 point 4 : envoi direct depuis le back-office (SMS, e-mail, notification dans l'application), une ligne par destinataire.
-- Le destinataire est toujours un compte (client + utilisateur) : son adresse est relue au moment de l'envoi, jamais stockée ;
-- la liste n'en montre qu'une forme masquée. Portable MySQL 8 / SQLite : dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE platform_direct_messages (
    id CHAR(26) NOT NULL PRIMARY KEY,
    batch_id CHAR(26) NOT NULL,
    tenant_id VARCHAR(40) NOT NULL,
    recipient_user_id VARCHAR(40) NOT NULL,
    recipient_masked VARCHAR(80) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(12) NOT NULL,
    reason VARCHAR(40) NULL,
    provider_reference VARCHAR(80) NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE INDEX platform_direct_messages_batch ON platform_direct_messages (batch_id, id);
CREATE INDEX platform_direct_messages_channel ON platform_direct_messages (channel, created_at);

-- Modèle libre de la plateforme (texte saisi dans le back-office), SMS et e-mail, français et anglais.
INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01JC1120000000000000PF0001', 'platform', 'platform', 'platform_direct_message', 'fr', 'email', 'SCHOLARIS : :subject', 'Bonjour :first_name, :details', '["first_name","subject","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00'),
('01JC1120000000000000PF0002', 'platform', 'platform', 'platform_direct_message', 'en', 'email', 'SCHOLARIS: :subject', 'Hello :first_name, :details', '["first_name","subject","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00'),
('01JC1120000000000000PF0003', 'platform', 'platform', 'platform_direct_message', 'fr', 'sms', NULL, 'SCHOLARIS - Bonjour :first_name, :details', '["first_name","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00'),
('01JC1120000000000000PF0004', 'platform', 'platform', 'platform_direct_message', 'en', 'sms', NULL, 'SCHOLARIS - Hello :first_name, :details', '["first_name","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00');
