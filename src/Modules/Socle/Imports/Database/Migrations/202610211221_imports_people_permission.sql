-- Permission dédiée à l'import en masse des acteurs d'un établissement (T-001).
-- Attribuée à l'administrateur de l'établissement : super administrateur du client, directeur d'établissement et administrateur
-- fonctionnel de l'IPES (R20). Aucun autre rôle. L'importeur n'attribue de toute façon que les rôles qu'il peut déjà attribuer
-- (identity.roles.assign) : cette permission n'élève aucun droit.
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'imports.people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'imports.people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'imports.people.manage');
