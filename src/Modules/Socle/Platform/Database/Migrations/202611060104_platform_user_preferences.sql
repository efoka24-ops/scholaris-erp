-- C-112 point 6 : réglages personnels de chaque utilisateur (tout client, plateforme comprise) : canaux de notification des
-- nouveaux messages (la notification dans l'application est toujours envoyée), résumé quotidien par e-mail (rôles de la
-- plateforme). Portable MySQL 8 / SQLite : une ligne par compte, dates ISO 8601 en texte, aucune clé étrangère.

CREATE TABLE platform_user_preferences (
    tenant_id VARCHAR(40) NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    notify_sms SMALLINT NOT NULL DEFAULT 0,
    notify_email SMALLINT NOT NULL DEFAULT 0,
    daily_digest SMALLINT NOT NULL DEFAULT 0,
    updated_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (tenant_id, user_id)
);

CREATE INDEX platform_user_preferences_person ON platform_user_preferences (tenant_id, person_id);

-- Résumé quotidien des membres de la plateforme (e-mail seulement).
INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01JC1120000000000000PF0005', 'platform', 'platform', 'platform_daily_digest', 'fr', 'email', 'SCHOLARIS : résumé du :date', 'Bonjour :first_name, voici le résumé de la plateforme : :details', '["first_name","date","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00'),
('01JC1120000000000000PF0006', 'platform', 'platform', 'platform_daily_digest', 'en', 'email', 'SCHOLARIS: summary for :date', 'Hello :first_name, here is the platform summary: :details', '["first_name","date","details"]', '2026-11-06T01:00:00+01:00', '2026-11-06T01:00:00+01:00');
