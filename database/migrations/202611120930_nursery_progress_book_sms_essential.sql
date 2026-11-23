-- Maternelle privée (décision du propriétaire du 12 novembre 2026, D-BUL) : le SMS « carnet de suivi disponible » est un message
-- de service essentiel, envoyé sans consentement recueilli (désinscription et consentement révoqué toujours respectés).
-- Valeur de départ au niveau PLATEFORME, que le client ou l'école remplace par /settings (false = le consentement s'applique).

INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES ('01NURSERY0SET0000000DBUL01', 'platform', 'platform', '', 'nursery.progress_book.sms_essential', 'true', 'system', '2026-11-12T09:30:00+01:00', '2026-11-12T09:30:00+01:00');
