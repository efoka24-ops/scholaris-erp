-- Rôles propres à l'application primaire privée. Les rôles enseignant, parent, apprenant, comptable et caissier sont réutilisés.
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('primary_promoteur', 'establishment', 0, 'Promoteur primaire', 'Primary founder');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('primary_directeur', 'establishment', 1, 'Directeur primaire', 'Primary headteacher');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('primary_adjoint', 'establishment', 0, 'Responsable pédagogique primaire', 'Primary deputy head');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('primary_secretaire', 'establishment', 0, 'Secrétaire primaire', 'Primary secretary');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('primary_surveillant', 'establishment', 0, 'Surveillant primaire', 'Primary supervisor');

INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.council.preside');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.report_card.sign');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'assessment.correction.approve');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.promotion.decide');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.referential.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'assessment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.enrol');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.exam.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_surveillant', 'attendance.sanction.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_surveillant', 'primary.pickup.handover');
