-- Documents (SS12) : droit dédié au rectificatif d'un document (POST /documents/{id}/rectify), décision du chef d'établissement.
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'documents.rectify');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'documents.rectify');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'documents.rectify');
