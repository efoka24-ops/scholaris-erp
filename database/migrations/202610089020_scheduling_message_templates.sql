-- Modèle de message de la plateforme utilisé par Scheduling (modification ponctuelle d'un cours), en français et en anglais.

INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES
('01J9T0000000000000000SCH01', 'platform', 'platform', 'timetable_change', 'fr', 'sms', NULL, 'Emploi du temps : :subject du :date (:time) est :change_label. :details :establishment_name', '["subject","date","time","change_label","details","establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('01J9T0000000000000000SCH02', 'platform', 'platform', 'timetable_change', 'en', 'sms', NULL, 'Timetable: :subject on :date (:time) has been changed (:change_label). :details :establishment_name', '["subject","date","time","change_label","details","establishment_name"]', '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00');
