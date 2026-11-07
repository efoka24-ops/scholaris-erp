-- PP18 (T610) : clés de catalogue des applications publiques (module commun, Primaire publique, Maternelle publique).
-- Statut « bientôt » (coming_soon) tant qu'elles ne sont pas terminées : la ligne de catalogue fait foi sur le statut
-- déclaré par le manifeste, aucune n'apparaît donc « disponible » au catalogue public. L'administrateur de plateforme
-- les ouvrira (PATCH /platform/applications/{key}) à la livraison. Tarif provisoire : 30 000 XAF par mois.

INSERT INTO platform_applications (app_key, label, target_type, status, version, required_modules, monthly_price_xaf, trial_days, grace_days, created_at, updated_at) VALUES
('public_schools', 'Écoles publiques - module commun', 'primary', 'coming_soon', '0.1.0', '["people","messaging","documents","attendance","staff","reporting","organisation"]', 30000, 90, 15, '2026-10-19T22:00:00+01:00', '2026-10-19T22:00:00+01:00'),
('public_primary', 'Primaire publique', 'primary', 'coming_soon', '0.1.0', '["people","messaging","documents","attendance","assessment","staff","reporting","organisation"]', 30000, 90, 15, '2026-10-19T22:00:00+01:00', '2026-10-19T22:00:00+01:00'),
('public_nursery', 'Maternelle publique', 'nursery', 'coming_soon', '0.1.0', '["people","attendance","messaging","documents","organisation"]', 30000, 90, 15, '2026-10-19T22:00:00+01:00', '2026-10-19T22:00:00+01:00');
