-- FIX-PRI (défaut 1) : la direction de l'école primaire privée ouvre l'appel par demi-journée (lecture de la liste d'appel et appel
-- d'une classe en l'absence du maître). Miroirs : Primary RoleMatrix::EXTRA_FIX_PRI et web/src/lib/permissions.ts.
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('primary_directeur', 'attendance.take');
