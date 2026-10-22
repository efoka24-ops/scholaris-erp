-- Rôles de l'application Maternelle privée (spec 007 §2, N01 à N05 et N10) et leurs permissions nursery.*.
-- Les rôles communs (directeur, comptable, caissier, parent, super_admin) sont réutilisés sans être modifiés ; le parent n'a pas de
-- permission nursery.* : ses routes de portail vérifient qu'il est garant de l'enfant. Miroir front : web/src/views/nursery-private/permissions.ts.

INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_promoteur', 'establishment', 0, 'Promoteur de maternelle', 'Nursery founder');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_directeur', 'establishment', 1, 'Directrice ou directeur de maternelle', 'Nursery head');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_educator', 'class', 0, 'Éducatrice ou enseignante de section', 'Nursery educator');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_assistant', 'class', 0, 'Aide maternelle', 'Nursery assistant');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_secretaire', 'establishment', 0, 'Secrétaire de maternelle', 'Nursery secretary');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('nursery_cantine', 'establishment', 0, 'Responsable de cantine de maternelle', 'Nursery canteen manager');

INSERT INTO role_permissions (role, permission) VALUES ('nursery_promoteur', 'nursery.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_promoteur', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.section.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.child.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.pickup.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.pickup.restrict');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.pickup.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.register.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.dev.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.dev.validate');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.frame.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.lifebook.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.need.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.allergy.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.message.send');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.transition.decide');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_directeur', 'nursery.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.child.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.register.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.arrival.record');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.dev.observe');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.dev.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.lifebook.write');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.lifebook.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.need.report');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.need.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.allergy.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.message.send');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'nursery.transition.opinion');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_educator', 'attendance.take');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.child.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.arrival.record');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.register.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'nursery.allergy.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_assistant', 'attendance.take');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.enrol');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.child.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.pickup.manage');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.pickup.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.register.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_secretaire', 'nursery.allergy.read');
INSERT INTO role_permissions (role, permission) VALUES ('nursery_cantine', 'nursery.allergy.read');
