-- Droits du socle pour les rôles du secondaire, vague E2, au moindre privilège et sans aucun droit de suppression.
-- (a) Assessment (demande E2-C, lot Grades) : les routes du socle exigent un droit assessment.* avant d'appliquer le cycle des
--     notes. Le censeur lit et valide (validation, verrouillage, approbation des corrections) ; le chef d'établissement et le
--     professeur principal lisent seulement. La saisie reste à l'enseignant (rôle commun, assessment.enter déjà accordé).
-- (b) Attendance (lot E2-B) : le surveillant général lit l'appel par heure du socle et décide des justificatifs d'absence
--     (attendance.justify : accepter ou refuser). Il ne fait pas l'appel lui-même (pas d'attendance.take). L'enseignant (rôle
--     commun) a déjà attendance.take par le socle Identity : rien à ajouter.

INSERT INTO role_permissions (role, permission) VALUES ('sec_censeur', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_censeur', 'assessment.validate');
INSERT INTO role_permissions (role, permission) VALUES ('sec_chef_etablissement', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_professeur_principal', 'assessment.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_surveillant_general', 'attendance.read');
INSERT INTO role_permissions (role, permission) VALUES ('sec_surveillant_general', 'attendance.justify');
