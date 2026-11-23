-- FIX-SEC (défaut 1 du guide G-SEC) : le caissier du secondaire voyait un espace Secondaire vide. Le menu n'affiche un écran de
-- module (Frais) que si le profil de l'établissement est lisible : le caissier reçoit la seule lecture du profil, au moindre
-- privilège (aucune écriture, aucune donnée nominative). Miroirs : Secondary RoleMatrix::EXTRA et web/src/lib/permissions.ts.

INSERT INTO role_permissions (role, permission) VALUES ('sec_caissier', 'secondary.profile.read');
