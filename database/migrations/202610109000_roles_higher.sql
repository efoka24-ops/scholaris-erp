-- Rôles R01-R23 de l'application Supérieur (IPES) et leurs permissions (migration partagée, W0-5).
-- Référence lisible : src/Modules/Apps/Higher/Policies/RoleMatrix.php (tests/Feature/Higher/Core/HigherRolesMigrationTest compare).
-- Rôles du socle réutilisés : R02 direction_groupe, R09 enseignant, R12 apprenant, R13 parent, R14 caissier, R15 comptable ;
-- ils gardent leurs permissions et reçoivent seulement les permissions higher.* qui leur manquent.
-- Sensibles (2FA) : R02, R03, R04, R15 (decisions.md R33). Externes R22 et R23 : lecture higher.* seule, affectation à durée limitée.
-- R18 (bibliothécaire, option I4) : déclaré sans permission. higher.access = accès à l'application (sélecteur d'école, /higher/me).

INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_promoteur', 'establishment', 0, 'Promoteur', 'Founder');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_dir_academique', 'establishment', 1, 'Directeur académique', 'Academic director');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_dir_ecole', 'establishment', 1, 'Directeur d''école', 'School director');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_chef_dept', 'establishment', 0, 'Chef de département', 'Head of department');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_resp_filiere', 'establishment', 0, 'Responsable de filière', 'Programme leader');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_scolarite', 'establishment', 0, 'Service de la scolarité', 'Registrar');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_examens', 'establishment', 0, 'Service des examens', 'Examinations office');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_vacataire', 'establishment', 0, 'Enseignant vacataire', 'Visiting lecturer');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_discipline', 'establishment', 0, 'Responsable de la discipline', 'Discipline officer');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_resp_stages', 'establishment', 0, 'Responsable des stages', 'Internship officer');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_rh', 'establishment', 0, 'Responsable des ressources humaines', 'Human resources officer');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_biblio', 'establishment', 0, 'Bibliothécaire', 'Librarian');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_communication', 'establishment', 0, 'Responsable de la communication', 'Communications officer');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_admin_fonct', 'establishment', 0, 'Administrateur fonctionnel', 'Functional administrator');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_jury', 'establishment', 0, 'Membre de jury', 'Board of examiners member');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_tutelle', 'establishment', 0, 'Université de tutelle', 'Supervising university');
INSERT INTO roles (code, level, is_sensitive, label_fr, label_en) VALUES ('higher_auditeur', 'establishment', 0, 'Inspection / auditeur', 'Inspector / auditor');

-- R01 Promoteur
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'reporting.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.vacation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_promoteur', 'higher.document.read');

-- R02 Directeur général
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.dashboard.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.settings.sensitive');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.curriculum.approve');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.vacation.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.vacation.validate');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.document.read');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.document.sign_institutional');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.alert.dg');
INSERT INTO role_permissions (role, permission) VALUES ('direction_groupe', 'higher.national_list.export');

-- R03 Directeur académique
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'assessment.reopen');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.curriculum.approve');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.regulation.amend');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.calendar.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.tutelage.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_academique', 'higher.dashboard.read');

-- R04 Directeur d'école
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.admission.decide');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.enrolment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.enrolment.derogate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.curriculum.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.timetable.validate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'assessment.correction.approve');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.deliberation.preside');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.vacation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.vacation.validate_school');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.document.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.document.sign_school');
INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'higher.dashboard.read');

-- R05 Chef de département
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.assignment.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.curriculum.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.session_log.validate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'assessment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'assessment.correction.approve');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.deliberation.prepare');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.vacation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.hours.validate');
INSERT INTO role_permissions (role, permission) VALUES ('higher_chef_dept', 'higher.dashboard.read');

-- R06 Responsable de filière
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.curriculum.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.internship.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_filiere', 'higher.enrolment.read');

-- R07 Service de la scolarité
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'people.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'people.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.application.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.enrol');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'scheduling.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'scheduling.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'attendance.justify');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.deliberation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.deliberation.prepare');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'documents.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'documents.issue');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.document.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.national_list.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.dashboard.read');

-- R08 Service des examens
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.exam.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.anonymity.lift');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.grades.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_examens', 'higher.document.read');

-- R09 Enseignant permanent
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.grades.submit');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.session_log.write');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.timetable.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.curriculum.read');
INSERT INTO role_permissions (role, permission) VALUES ('enseignant', 'higher.vacation.read_own');

-- R10 Enseignant vacataire
INSERT INTO role_permissions (role, permission) VALUES ('higher_vacataire', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_vacataire', 'higher.vacation.read_own');

-- R11 Responsable de la discipline
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'attendance.discipline.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'higher.council.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_discipline', 'higher.attendance.read');

-- R12 Étudiant
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'higher.query.create');
INSERT INTO role_permissions (role, permission) VALUES ('apprenant', 'higher.application.submit');

-- R13 Garant
INSERT INTO role_permissions (role, permission) VALUES ('parent', 'higher.access');

-- R14 Caissier
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('caissier', 'higher.billing.read');

-- R15 Comptable
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.billing.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.quitus.issue');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.vacation.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.vacation.pay');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.session_log.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.admission.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.enrolment.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.document.read');
INSERT INTO role_permissions (role, permission) VALUES ('comptable', 'higher.dashboard.read');

-- R16 Responsable des stages
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_stages', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_stages', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_stages', 'higher.internship.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_stages', 'higher.defense.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_resp_stages', 'higher.enrolment.read');

-- R17 Responsable des ressources humaines
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'staff.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'staff.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'vacations.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_rh', 'higher.vacation.read');

-- R19 Responsable de la communication
INSERT INTO role_permissions (role, permission) VALUES ('higher_communication', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_communication', 'messaging.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_communication', 'messaging.send');
INSERT INTO role_permissions (role, permission) VALUES ('higher_communication', 'messaging.templates.manage');

-- R20 Administrateur fonctionnel
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'organisation.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'identity.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'identity.roles.assign');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'settings.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'documents.templates.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'higher.settings.read');
INSERT INTO role_permissions (role, permission) VALUES ('higher_admin_fonct', 'higher.settings.manage');

-- R21 Membre de jury
INSERT INTO role_permissions (role, permission) VALUES ('higher_jury', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_jury', 'assessment.deliberation.sign');
INSERT INTO role_permissions (role, permission) VALUES ('higher_jury', 'higher.jury.portal');
INSERT INTO role_permissions (role, permission) VALUES ('higher_jury', 'higher.deliberation.read');

-- R22 Université de tutelle
INSERT INTO role_permissions (role, permission) VALUES ('higher_tutelle', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_tutelle', 'higher.tutelage.read');

-- R23 Inspection / auditeur
INSERT INTO role_permissions (role, permission) VALUES ('higher_auditeur', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('higher_auditeur', 'higher.audit.read');

-- Rôles du socle cumulés avec R04 (directeur) et R20 (super_admin) : accès à l'application.
INSERT INTO role_permissions (role, permission) VALUES ('directeur', 'higher.access');
INSERT INTO role_permissions (role, permission) VALUES ('super_admin', 'higher.access');
