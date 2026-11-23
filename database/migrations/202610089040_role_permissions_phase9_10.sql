-- Permissions des modules Scheduling (S07), Attendance (S08), Assessment (S09) et Staff (S10) par rôle.
-- Enseignant, parent et apprenant lisent leur propre périmètre sans permission de lecture (voir les modules).

INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'attendance.justify');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'attendance.discipline.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'attendance.discipline.approve');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'staff.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'vacations.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'vacations.validate');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.enter');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.publish');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.correction.approve');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.council.manage');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'assessment.deliberation.manage');

INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'attendance.justify');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'attendance.discipline.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'attendance.discipline.approve');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'staff.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'vacations.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'vacations.validate');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.publish');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.correction.approve');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.council.manage');
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'assessment.deliberation.manage');

INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'assessment.read');

INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'assessment.enter');

