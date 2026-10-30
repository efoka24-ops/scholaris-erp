-- Lot P3 du primaire public (T680 à T687, T690) : droits complémentaires, sans toucher à 202610152351_roles_public_schools.sql.
-- 1. public_primary.exams.read : l'enseignant (R10) lit la liste d'examen de sa classe, le délégué régional (R05) la liste consolidée
--    (matrice du CDC « Listes d'examen » : L ; RG-TER-3 fait exception pour les listes d'examen, lecture journalisée).
-- 2. public_primary.exams.consolidate et public_primary.exams.attest : la délégation départementale (R06) consolide (centres,
--    sous-centres, jurys, vacations : EX-EXA-03) et saisit la signature des attestations DOC-16 (Q17).
-- 3. public_schools.inspection.manage : grilles de visite paramétrables (EX-INS-02), réglées au niveau national (RG-TER-6) par
--    l'Inspection générale (R04) et l'administrateur fonctionnel (R21).
-- 4. public_schools.canteen.read : le directeur (R09) consulte la cantine et les campagnes de santé de son école (aucune donnée
--    nominative) ; le responsable de la cantine (R17) et l'agent de santé (R18) lisent ce qu'ils saisissent.
-- 5. documents.read : l'inspecteur (R07), le conseiller pédagogique (R08) et la délégation départementale (R06) téléchargent les PDF
--    qu'ils émettent (rapport de visite DOC-26, tableau de bord DOC-28, listes et attestations d'examen).
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_primary.exams.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'public_primary.exams.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_primary.exams.consolidate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_primary.exams.attest');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'public_schools.inspection.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'public_schools.inspection.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.canteen.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_canteen_manager', 'public_schools.canteen.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_health_agent', 'public_schools.canteen.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_pedagogical_advisor', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'documents.read');
