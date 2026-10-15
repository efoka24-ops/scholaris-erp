-- Défaut D-02 (recette Septentrion) : la publication des notes verrouillées (transition locked -> published, 2FA récente)
-- exige higher.grades.publish, que n'avait aucun rôle. Elle revient au service de la scolarité (R07, API-15 du CDC IPES),
-- séparé de la saisie (enseignant) et du verrouillage (chef de département).

INSERT INTO role_permissions (role, permission) VALUES ('higher_scolarite', 'higher.grades.publish');
