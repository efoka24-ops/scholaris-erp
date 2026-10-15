-- Permissions des nouvelles fonctions du socle : messagerie interne, congés, rapports planifiés.
-- La modération de la messagerie et l'approbation des congés vont au directeur (pas de rôle dédié pour l'instant).

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.chat');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'messaging.moderate');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'staff.leave.request');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'staff.leave.approve');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'reporting.manage');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.chat');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'messaging.moderate');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'staff.leave.request');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'staff.leave.approve');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'reporting.manage');

INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'messaging.chat');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'staff.leave.request');

INSERT INTO role_permissions (role, permission) VALUES ('parent', 'messaging.chat');
