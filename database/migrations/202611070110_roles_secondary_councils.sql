-- Droits du socle pour les rôles du secondaire, lot E3-A (conseils de classe et bulletins), au moindre privilège.
-- Les routes du socle /class-councils (ouverture, décisions, recalcul, validation, génération des bulletins) exigent
-- assessment.council.manage. Le censeur prépare le conseil (secondary.council.prepare) ; le chef d'établissement le préside
-- (secondary.council.preside). Aucun autre droit n'est nécessaire : la lecture (assessment.read) leur est déjà accordée (E2).

INSERT INTO role_permissions (role, permission) VALUES ('sec_censeur', 'assessment.council.manage');
INSERT INTO role_permissions (role, permission) VALUES ('sec_chef_etablissement', 'assessment.council.manage');
