-- Écriture des paramètres de niveau plateforme : réservée au super administrateur.

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'settings.platform');
