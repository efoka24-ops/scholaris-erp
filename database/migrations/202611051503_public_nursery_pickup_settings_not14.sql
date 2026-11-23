-- Maternelle publique (C-106) : parametres de depart de la securite de la sortie, au niveau PLATEFORME, et modele de message NOT-14.
-- Code a usage unique (T724) : 5 essais errones avant blocage (spec 004 US3, a confirmer). Paquet du matin hors ligne (T725) : age maximal
-- du paquet en jours, absent tant que l'ecole ne l'a pas fixe (a confirmer). NOT-14 : fermeture exceptionnelle de l'ecole, message de
-- securite (modele plateforme : un parent qui a refuse les SMS non essentiels le recoit quand meme).

INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES ('01PUBNURSET00000000000010A', 'platform', 'platform', '', 'public_nursery.pickup.code_max_attempts', '5', 'system', '2026-11-05T15:03:00+01:00', '2026-11-05T15:03:00+01:00');

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01PNNOT1400000000000000001', 'platform', 'platform', 'pn.not14.parent', 'fr', 'sms', NULL, ':establishment_name sera fermée le :date. Motif : :details. Gardez votre enfant à la maison.', '["establishment_name","date","details"]', '2026-11-05T15:03:00+01:00', '2026-11-05T15:03:00+01:00'),
('01PNNOT1400000000000000002', 'platform', 'platform', 'pn.not14.parent', 'en', 'sms', NULL, ':establishment_name will be closed on :date. Reason: :details. Please keep your child at home.', '["establishment_name","date","details"]', '2026-11-05T15:03:00+01:00', '2026-11-05T15:03:00+01:00')
