-- Reprise de données (module Migration, lot I4) : directeur d'école (R04) et scolarité (R07).
-- Tableaux de bord : assiduité lue par le responsable de la discipline (R11), comme dans la RoleMatrix.

INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.migration.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.migration.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'higher.dashboard.read');
