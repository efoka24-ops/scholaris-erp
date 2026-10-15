-- Permissions des modules Reporting (S11) et conformité (S13) par rôle.
-- Les portails famille et apprenant n'exigent que l'authentification et le lien personne.

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'reporting.group.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'privacy.requests.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'privacy.requests.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'privacy.export');

INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'reporting.group.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'privacy.requests.read');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'privacy.requests.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'privacy.requests.manage');
