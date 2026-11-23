-- C-104 : SMS aux parents pour absences répétées (NOT-PRI-10), envoyé par primary:alerts à la création de l'alerte, donc une seule fois par série
-- d'absences ; l'école le désactive avec le paramètre primary.alert.sms. Modèles plateforme FR/EN, variables de la liste blanche du moteur de messages.

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01C104000000000000000TP001', 'platform', 'platform', 'pri.not10.absence_streak', 'fr', 'sms', NULL, ':child_name est absent(e) depuis le :date (:details demi-journées consécutives). Merci de contacter :establishment_name. Réf. :reference', '["child_name","date","details","establishment_name","reference"]', '2026-11-05T13:01:00+01:00', '2026-11-05T13:01:00+01:00'),
('01C104000000000000000TP002', 'platform', 'platform', 'pri.not10.absence_streak', 'en', 'sms', NULL, ':child_name has been absent since :date (:details consecutive half-days). Please contact :establishment_name. Ref: :reference', '["child_name","date","details","establishment_name","reference"]', '2026-11-05T13:01:00+01:00', '2026-11-05T13:01:00+01:00');
