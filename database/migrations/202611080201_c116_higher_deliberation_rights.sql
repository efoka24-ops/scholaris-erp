-- C-116 (défaut C110-I1) : droits du socle pour la séance de délibération de l'IPES, alignés sur Policies\RoleMatrix (Higher).
-- Le directeur d'école (R04, président du jury) ouvre et conduit la séance du socle (/deliberations : ouverture, présents,
-- démarrage, décisions, clôture) : assessment.deliberation.manage. Le service de la scolarité (R07) publie les résultats
-- après la signature du PV (/results/publish) : assessment.publish. Les gardes de l'IPES (jury déclaré, notes verrouillées,
-- double authentification récente, signature du président) s'appliquent toujours.

INSERT INTO role_permissions (role, permission) VALUES ('higher_dir_ecole', 'assessment.deliberation.manage');
INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'assessment.publish');
