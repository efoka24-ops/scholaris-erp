-- Catalogue initial : IPES (supérieur) disponible, les trois autres applications annoncées. Tarif mensuel par défaut
-- 60 000 XAF, essai gratuit de 90 jours, délai de grâce de 15 jours après l'échéance. Modifiable par l'administrateur de plateforme.

INSERT INTO platform_applications (app_key, label, target_type, status, version, required_modules, monthly_price_xaf, trial_days, grace_days, created_at, updated_at) VALUES
('higher', 'IPES - Enseignement supérieur', 'higher', 'available', '1.0.0', '["people","billing","messaging","documents","scheduling","attendance","assessment","staff","reporting"]', 60000, 90, 15, '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('secondary', 'Enseignement secondaire', 'secondary', 'coming_soon', '0.1.0', '["people","billing","messaging","documents","scheduling","attendance","assessment","staff","reporting"]', 60000, 90, 15, '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('primary', 'Enseignement primaire', 'primary', 'coming_soon', '0.1.0', '["people","billing","messaging","documents","scheduling","attendance","assessment","staff","reporting"]', 60000, 90, 15, '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00'),
('nursery', 'Maternelle', 'nursery', 'coming_soon', '0.1.0', '["people","billing","messaging","documents","scheduling","attendance","assessment","staff","reporting"]', 60000, 90, 15, '2026-10-09T00:00:00+01:00', '2026-10-09T00:00:00+01:00');
