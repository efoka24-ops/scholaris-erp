-- Stages et soutenances (STG) de l'application IPES : lecture des conventions, du suivi et des soutenances par la direction
-- générale (rôle du socle direction_groupe, R02). La gestion reste à R06/R16 (higher.internship.manage), la programmation des
-- soutenances à R16 (higher.defense.manage, API-18) et le directeur d'école (R04) lit les conventions de son école par
-- higher.document.sign_school. Les rôles IPES « nouveaux » (R03, R05, R07) recevront higher.internship.read avec RoleMatrix
-- (HigherRolesMigrationTest exige que migration et RoleMatrix concordent pour eux).

INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.internship.read');
