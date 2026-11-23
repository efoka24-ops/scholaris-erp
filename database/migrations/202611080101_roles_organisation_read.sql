-- C-115 (défaut C110-PR1) : lecture de l'organisation (classes, niveaux, années scolaires, périodes) pour les rôles des applications
-- primaire privée, maternelle privée et secondaire dont les écrans appellent GET /structures ou /academic-years.
-- Moindre privilège : organisation.read seulement (jamais organisation.manage). Le rôle commun enseignant l'a déjà (socle).
-- Miroirs : Primary RoleMatrix::EXTRA_ORGANISATION, Nursery RoleMatrix::ROLES, Secondary RoleMatrix::EXTRA, web/src/lib/permissions.ts.

INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_surveillant', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_chef_etablissement', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_censeur', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_surveillant_general', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_animateur_pedagogique', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_professeur_principal', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_conseiller_orientation', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_intendant', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_scolarite', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_admin_fonctionnel', 'organisation.read');
