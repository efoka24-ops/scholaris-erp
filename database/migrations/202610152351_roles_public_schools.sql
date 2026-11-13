-- PP18 (T610) : rôles R01-R10, R13 et R15-R21 des écoles publiques et leurs permissions (plan.md §5, matrice des droits du CDC).
-- Référence lisible : tests/Feature/RolePermissionsPublicSchoolsTest.php (matrice comparée à cette migration).
-- Hors périmètre : R11, R12 et R14 (propres à la Maternelle, semés par T712) ; le marquage des rôles de supervision à agrégats seuls (PP2, T606).
-- Niveaux : national = rôle de niveau client (tenant) ; territorial = affectation à un territoire (PP2) ; école = affectation à un établissement.
-- R13 réutilise le rôle « parent » du socle : il ne reçoit que la lecture de ses reçus APEE. R21 porte seul le droit de niveau client
-- organisation.territories.manage (rattacher les écoles aux territoires, T601). Sensibles (2FA) : rôles qui écrivent, valident ou
-- consultent des données nominatives ou financières (R02, R04 à R07, R09, R10, R15, R20, R21) ; les rôles à agrégats seuls ne le sont pas.
-- Permissions public_schools.* et public_primary.* : lecture, écriture et validation par module. Aucune permission nominative
-- (*.named.*) pour R01, R03, R05 ni R19 ; la liste des non-contributeurs APEE n'est accordée qu'au bureau de l'APEE (RG-APE-2).
-- sync.batches.submit (R07, R09, R10 : API-04) et sync.devices.manage (R09, R21) appartiennent au module Sync (T609).
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_cabinet', 'tenant', 0, 'Cabinet du ministre, secrétariat général', 'Minister''s office, general secretariat');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_exam_directorate', 'tenant', 1, 'Direction des examens, concours et certification', 'Directorate of examinations, competitions and certification');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_planning', 'tenant', 0, 'Division de la planification', 'Planning division');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_inspection_general', 'tenant', 1, 'Inspection générale des enseignements', 'General inspectorate of education');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_regional_delegate', 'territory', 1, 'Délégué régional', 'Regional delegate');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_departmental_delegate', 'territory', 1, 'Délégué départemental et service des statistiques', 'Divisional delegate and statistics service');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_district_inspector', 'territory', 1, 'Inspecteur d''arrondissement', 'Sub-divisional inspector');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_pedagogical_advisor', 'territory', 0, 'Conseiller ou animateur pédagogique', 'Pedagogical advisor or facilitator');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_school_director', 'establishment', 1, 'Directeur d''école', 'School head');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_class_teacher', 'establishment', 1, 'Enseignant titulaire de classe', 'Class teacher');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_apee_board', 'establishment', 1, 'Bureau de l''APEE (président, trésorier)', 'PTA board (chair, treasurer)');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_school_council', 'establishment', 0, 'Membre du conseil d''école', 'School council member');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_canteen_manager', 'establishment', 0, 'Responsable de la cantine', 'Canteen manager');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_school_health_agent', 'territory', 0, 'Agent de santé scolaire', 'School health agent');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_technical_partner', 'territory', 0, 'Partenaire technique et financier', 'Technical and financial partner');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_inspection_clerk', 'territory', 1, 'Agent de saisie de l''inspection', 'Inspectorate data-entry clerk');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('pub_functional_admin', 'tenant', 1, 'Administrateur fonctionnel', 'Functional administrator');

-- R01 Cabinet du ministre, secrétariat général
INSERT INTO role_permissions (role, permission) VALUES ('pub_cabinet', 'public_schools.aggregates.read');

-- R02 Direction des examens, concours et certification
INSERT INTO role_permissions (role, permission) VALUES ('pub_exam_directorate', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_exam_directorate', 'public_primary.exams.consolidate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_exam_directorate', 'public_primary.exams.import');

-- R03 Division de la planification
INSERT INTO role_permissions (role, permission) VALUES ('pub_planning', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_planning', 'public_schools.census.validate');

-- R04 Inspection générale des enseignements
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_general', 'documents.templates.manage');

-- R05 Délégué régional
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'public_schools.census.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_regional_delegate', 'public_schools.messages.announce_territory');

-- R06 Délégué départemental et service des statistiques
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.pupils.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.pupils.named.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.census.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.messages.send');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_schools.messages.announce_territory');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_primary.exams.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_departmental_delegate', 'public_primary.exams.import');

-- R07 Inspecteur d'arrondissement
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.school.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.pupils.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.pupils.named.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.teacher_presence.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.teacher_presence.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.messages.send');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.messages.announce_territory');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.apee.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.census.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.inspection.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_schools.aggregates.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_primary.promotion.opinion');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'public_primary.exams.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_district_inspector', 'sync.batches.submit');

-- R08 Conseiller ou animateur pédagogique
INSERT INTO role_permissions (role, permission) VALUES ('pub_pedagogical_advisor', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_pedagogical_advisor', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_pedagogical_advisor', 'public_schools.inspection.write');

-- R09 Directeur d'école
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.school.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.pupils.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.pupils.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.attendance.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.teacher_presence.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.teacher_presence.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.staff.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.messages.send');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.apee.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.census.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.evaluation.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.evaluation.publish');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.evaluation.rectify');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.promotion.validate');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'public_primary.exams.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'sync.batches.submit');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_director', 'sync.devices.manage');

-- R10 Enseignant titulaire de classe
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.pupils.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.attendance.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.teacher_presence.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.messages.send');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_schools.inspection.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_primary.evaluation.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'public_primary.promotion.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_class_teacher', 'sync.batches.submit');

-- R15 Bureau de l'APEE (président, trésorier)
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.apee.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.apee.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.apee.read_receipts');
INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.apee.non_payers.read');

-- R16 Membre du conseil d'école
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_council', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_council', 'public_schools.apee.read');

-- R17 Responsable de la cantine
INSERT INTO role_permissions (role, permission) VALUES ('pub_canteen_manager', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_canteen_manager', 'public_schools.canteen.write');

-- R18 Agent de santé scolaire
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_health_agent', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_school_health_agent', 'public_schools.canteen.write');

-- R19 Partenaire technique et financier
INSERT INTO role_permissions (role, permission) VALUES ('pub_technical_partner', 'public_schools.aggregates.read');

-- R20 Agent de saisie de l'inspection
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_clerk', 'public_schools.school.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_clerk', 'public_schools.pupils.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_clerk', 'public_schools.pupils.write');
INSERT INTO role_permissions (role, permission) VALUES ('pub_inspection_clerk', 'public_schools.census.write');

-- R21 Administrateur fonctionnel
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'organisation.territories.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'identity.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'identity.roles.assign');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'documents.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'messaging.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('pub_functional_admin', 'sync.devices.manage');

-- R13 Parent ou tuteur (rôle « parent » du socle, permission ajoutée)
INSERT INTO role_permissions (role, permission) VALUES ('parent', 'public_schools.apee.read_receipts');
