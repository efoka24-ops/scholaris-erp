-- Permission dédiée au réglage des circuits de validation (W-001), réservée à l'administrateur de l'établissement : super
-- administrateur du client, directeur d'établissement et administrateur fonctionnel de l'IPES. Aucun autre rôle. La permission
-- ne permet que d'ajuster dans les limites déclarées par chaque application : elle n'élève aucun droit de validation.
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'workflows.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'workflows.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'workflows.manage');
