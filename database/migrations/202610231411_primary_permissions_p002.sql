-- Permissions de l'application primaire privée ajoutées par P-002 : conseil des maîtres (lecture), bulletins (lecture), remédiation,
-- examen de fin de cycle (lecture), exports de tutelle, services (cantine, garderie, transport) et sortie sécurisée (lecture).
-- Les permissions d'action existantes sont reprises : primary.council.preside, primary.report_card.sign, primary.exam.manage,
-- primary.promotion.decide, primary.pickup.handover. Concorde avec RoleMatrix::EXTRA_P002 (test PrimaryRolesMigrationTest).
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.council.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.report_card.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.remediation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.remediation.write');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.exam.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.exam.manage');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.tutelage.export');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.services.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.pickup.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'primary.pickup.handover');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.council.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.report_card.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.remediation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.remediation.write');
INSERT INTO role_permissions (role, permission) VALUES ('primary_adjoint', 'primary.exam.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.council.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.report_card.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.remediation.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.exam.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.tutelage.export');
INSERT INTO role_permissions (role, permission) VALUES ('primary_promoteur', 'primary.services.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.report_card.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.exam.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.tutelage.export');
INSERT INTO role_permissions (role, permission) VALUES ('primary_secretaire', 'primary.services.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_surveillant', 'primary.pickup.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'primary.council.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'primary.report_card.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'primary.remediation.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'primary.remediation.write');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'primary.services.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'primary.services.manage');
