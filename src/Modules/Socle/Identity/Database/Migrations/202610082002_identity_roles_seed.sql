-- Rôles standards et leurs permissions. Niveau « tenant » : rôle de niveau client (tous les établissements).
-- is_sensitive = 1 : double authentification obligatoire (direction, finances, notes, administration).

INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('super_admin', 'tenant', 1, 'Super administrateur', 'Super administrator');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('direction_groupe', 'tenant', 1, 'Direction de groupe', 'Group management');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('directeur', 'establishment', 1, 'Directeur d''établissement', 'School principal');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('comptable', 'establishment', 1, 'Comptable', 'Accountant');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('caissier', 'establishment', 1, 'Caissier', 'Cashier');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('enseignant', 'establishment', 1, 'Enseignant', 'Teacher');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('parent', 'establishment', 0, 'Parent', 'Parent');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('apprenant', 'establishment', 0, 'Apprenant', 'Learner');

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'organisation.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'identity.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'identity.roles.assign');

INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'organisation.manage');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'identity.manage');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'identity.roles.assign');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'organisation.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'identity.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'identity.roles.assign');

INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'finance.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'finance.manage');

INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'finance.read');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'finance.collect');

INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'grades.enter');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'attendance.take');

INSERT INTO role_permissions (role, permission) VALUES ('parent', 'self.read');
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'self.read');
