-- FIX-PUB (guide du primaire public, défauts 9 et 10 de G-PRI), sans toucher aux migrations existantes.
-- 1. public_primary.referential.school_manage : le directeur (R09) importe le référentiel d'exemple et génère les unités de son
--    école tant que le paramètre public_primary.referential.school_may_manage le permet (RG-TER-6, pilote sans niveau national).
-- 2. organisation.read : la direction des examens (R02, niveau national) lit les années scolaires et les périodes pour ouvrir une
--    session d'examen (GET /academic-years). Lecture seule, jamais organisation.manage ; aucune donnée nominative d'élève.
-- Miroirs : PublicSchools RoleMatrix::ROLES et web/src/lib/permissions.ts.
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.referential.school_manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_exam_directorate', 'organisation.read');
