-- Lecture des stages et soutenances (module Internships) par le directeur académique (R03), le chef de département (R05) et la
-- scolarité (R07), comme dans la RoleMatrix de l'application Supérieur.

INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.internship.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.internship.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.internship.read');
