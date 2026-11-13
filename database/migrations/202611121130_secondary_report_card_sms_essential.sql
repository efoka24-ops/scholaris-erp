-- Secondaire (décision du propriétaire du 12 novembre 2026, D-BUL, S-NOT05) : le SMS « bulletin disponible » (NOT-05) est un
-- message de service essentiel, envoyé sans consentement recueilli (désinscription et consentement révoqué toujours respectés).
-- Valeur de départ au niveau PLATEFORME, que le client ou l'école remplace par /settings (false = le consentement s'applique).

INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES ('01SECONDARYSET00000NOT0501', 'platform', 'platform', '', 'secondary.report_card.sms_essential', 'true', 'system', '2026-11-12T11:30:00+01:00', '2026-11-12T11:30:00+01:00');
