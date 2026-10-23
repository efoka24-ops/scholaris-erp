-- Rôle global d'administrateur de plateforme (Identity) et permissions de la plateforme.
-- Le rôle est de niveau « tenant », sensible (double authentification obligatoire, même si AUTH_2FA_REQUIRED est faux),
-- rattaché au client technique « platform ». Il n'est jamais attribuable par l'API : seule la commande platform:admin-create le crée.
-- Il ne porte AUCUNE permission métier (élèves, notes, factures) : la plateforme ne voit pas les données d'un client.

INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('platform_admin', 'tenant', 1, 'Administrateur de plateforme', 'Platform administrator');

INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.access');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.clients.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.catalog.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.requests.read');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.requests.decide');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.applications.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.pricing.manage');
INSERT INTO role_permissions (role, permission) VALUES ('platform_admin', 'platform.payments.read');

-- Libre-service : un client ouvre et paie lui-même une application (super administrateur, direction de groupe, directeur).
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'applications.manage');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'applications.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'applications.manage');
